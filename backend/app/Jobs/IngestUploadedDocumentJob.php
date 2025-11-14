<?php

namespace App\Jobs;

use App\Contracts\Ingestion\ChunkingServiceInterface;
use App\Contracts\Ingestion\DocumentExtractionServiceInterface;
use App\Contracts\Ingestion\EmbeddingBatchServiceInterface;
use App\Contracts\Ingestion\TextParsingServiceInterface;
use App\Contracts\Ingestion\VectorIndexingServiceInterface;
use App\Models\Document;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job for ingesting uploaded documents into RAG pipeline
 *
 * Orchestrates the complete ingestion flow:
 * 1. Extract text from file
 * 2. Parse and normalize text
 * 3. Chunk text semantically
 * 4. Generate embeddings
 * 5. Index vectors in Milvus
 * 6. Save extracted markdown
 */
class IngestUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(
        DocumentExtractionServiceInterface $extraction,
        TextParsingServiceInterface $parsing,
        ChunkingServiceInterface $chunking,
        EmbeddingBatchServiceInterface $embeddings,
        VectorIndexingServiceInterface $indexing,
        TenantRagConfigService $tenantRagConfig
    ): void {
        /** @var Document|null $doc */
        $doc = Document::find($this->documentId);
        if ($doc === null) {
            Log::warning('ingestion.document_not_found', ['document_id' => $this->documentId]);

            return;
        }

        // 🔒 Verifica se un altro job sta già processando questo documento
        if ($doc->ingestion_status === 'processing') {
            Log::info('ingestion.already_processing', [
                'document_id' => $this->documentId,
                'status' => $doc->ingestion_status,
            ]);

            return;
        }

        $this->updateDoc($doc, ['ingestion_status' => 'processing', 'ingestion_progress' => 0, 'last_error' => null]);

        try {
            // ========================================
            // STEP 1: Extract text from file
            // ========================================
            $text = $extraction->extractText((string) $doc->path);
            if ($text === '') {
                throw new \RuntimeException('File vuoto o non parsabile');
            }

            // ========================================
            // STEP 2: Parse and normalize text
            // ========================================
            $normalizedText = $parsing->normalize($text);
            $normalizedText = $parsing->removeNoise($normalizedText);

            // Check if scraped document (Markdown is already well-formatted)
            $isScrapedDocument = in_array($doc->source, ['web_scraper', 'web_scraper_linked'], true);

            // ========================================
            // STEP 3: Chunk text
            // ========================================
            // Get tenant-specific chunking config
            $chunkingConfig = $tenantRagConfig->getChunkingConfig($doc->tenant_id);
            $chunkOptions = [
                'max_chars' => (int) $chunkingConfig['max_chars'],
                'overlap_chars' => (int) $chunkingConfig['overlap_chars'],
                'strategy' => 'standard',
            ];

            Log::info('tenant_chunking.parameters', [
                'tenant_id' => $doc->tenant_id,
                'document_id' => $doc->id,
                'max_chars' => $chunkOptions['max_chars'],
                'overlap_chars' => $chunkOptions['overlap_chars'],
                'text_length' => strlen($normalizedText),
                'is_scraped' => $isScrapedDocument,
                'chunking_strategy' => $isScrapedDocument ? 'semantic_only' : 'table_aware',
            ]);

            // ✅ FIX: For scraped documents, SKIP table extraction completely
            // Scraped Markdown is well-formatted. Large chunks (e.g., 3000 chars for Tenant 5)
            // can contain entire tables with context, preserving narrative flow.
            // Table-aware chunking was causing context loss and information mixing.
            if ($isScrapedDocument) {
                // SIMPLE: Pure semantic chunking with ALL text (tables inline)
                // ✅ SECOND FIX: Remove boilerplate (URL, "Scraped on", etc.) to improve semantic similarity
                $chunkOptions['remove_boilerplate'] = true;
                $allChunks = $chunking->chunk($normalizedText, $doc->tenant_id, $chunkOptions);

                Log::info('chunking.scraped_semantic_only', [
                    'chunks_created' => count($allChunks),
                    'reason' => 'scraped_markdown_well_formatted',
                    'boilerplate_removed' => true,
                ]);
            } else {
                // COMPLEX: Table-aware chunking for uploaded files (PDF, DOCX, etc.)
                $tables = $parsing->findTables($normalizedText);

                // Chunk tables separately
                $tableChunks = $chunking->chunkTables($tables);

                // Chunk remaining text (without tables)
                $textWithoutTables = $parsing->removeTables($normalizedText, $tables);

                // Extract directory entries for uploaded documents
                $directoryChunks = [];
                if (trim($textWithoutTables) !== '') {
                    $directoryChunks = $chunking->extractDirectoryEntries($textWithoutTables);
                }

                // Standard chunking for non-table, non-directory text
                $standardChunks = [];
                if (count($directoryChunks) < 5 && trim($textWithoutTables) !== '') {
                    $standardChunks = $chunking->chunk($textWithoutTables, $doc->tenant_id, $chunkOptions);
                }

                // Merge all chunks
                $allChunks = array_merge($tableChunks, $directoryChunks, $standardChunks);

                Log::info('chunking.uploaded_table_aware', [
                    'table_chunks' => count($tableChunks),
                    'directory_chunks' => count($directoryChunks),
                    'standard_chunks' => count($standardChunks),
                    'total_chunks' => count($allChunks),
                ]);
            }

            if (empty($allChunks)) {
                throw new \RuntimeException('Nessun chunk generato');
            }

            // Convert chunk objects to plain text array (for backward compatibility)
            $chunkTexts = array_map(function ($chunk) {
                return is_array($chunk) ? ($chunk['text'] ?? '') : (string) $chunk;
            }, $allChunks);

            // Generate embedding-friendly text (tables flattened to plain text)
            $embeddingTexts = array_map(function (string $chunk) use ($parsing): string {
                return $parsing->flattenMarkdownTables($chunk);
            }, $chunkTexts);

            $total = count($chunkTexts);
            $this->updateDoc($doc, ['ingestion_progress' => 10]);

            // ========================================
            // STEP 4: Generate embeddings
            // ========================================
            $embeddingResults = $embeddings->embedBatch($embeddingTexts);

            if (count($embeddingResults) !== $total) {
                throw new \RuntimeException('Dimensione vettori non corrisponde ai chunk');
            }

            // Extract vectors from embedding results
            $vectors = array_map(fn ($result) => $result['vector'], $embeddingResults);

            $this->updateDoc($doc, ['ingestion_progress' => 60]);

            // ========================================
            // STEP 5: Persist chunks to DB (ATOMIC)
            // ========================================
            DB::transaction(function () use ($doc, $chunkTexts) {
                // 🔒 Lock del documento per evitare race conditions
                $doc->refresh();
                $doc->lockForUpdate();

                // Elimina e reinserisci in modo atomico
                DB::table('document_chunks')->where('document_id', $doc->id)->delete();

                $now = now();
                $rows = [];
                foreach ($chunkTexts as $i => $content) {
                    $rows[] = [
                        'tenant_id' => (int) $doc->tenant_id,
                        'document_id' => (int) $doc->id,
                        'chunk_index' => (int) $i,
                        'content' => $this->sanitizeUtf8Content((string) $content),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // Inserisci in batch per performance
                foreach (array_chunk($rows, 500) as $batch) {
                    DB::table('document_chunks')->insert($batch);
                }

                Log::debug('document_chunks.replaced_atomically', [
                    'document_id' => $doc->id,
                    'chunks_count' => count($chunkTexts),
                    'tenant_id' => $doc->tenant_id,
                ]);
            });
            $this->updateDoc($doc, ['ingestion_progress' => 80]);

            // ========================================
            // STEP 6: Index vectors in Milvus
            // ========================================
            // Prepare chunks for indexing (text + vector pairs)
            $chunksForIndexing = [];
            foreach ($chunkTexts as $i => $text) {
                $chunksForIndexing[] = [
                    'text' => $text,
                    'vector' => $vectors[$i],
                ];
            }

            $indexing->upsert((int) $doc->id, (int) $doc->tenant_id, $chunksForIndexing);

            // ========================================
            // STEP 7: Save extracted Markdown (optional)
            // ========================================
            try {
                $mdPath = $this->saveExtractedMarkdown($doc, $chunkTexts);
                $this->updateDoc($doc, [
                    'extracted_path' => $mdPath,
                ]);
            } catch (\Throwable $e) {
                Log::warning('extracted_md.save_failed', [
                    'document_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->updateDoc($doc, ['ingestion_status' => 'completed', 'ingestion_progress' => 100]);

            Log::info('ingestion.completed', [
                'document_id' => $doc->id,
                'chunks_count' => $total,
                'tenant_id' => $doc->tenant_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('ingestion.failed', [
                'document_id' => $doc->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->updateDoc($doc, ['ingestion_status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }

    /**
     * Update document attributes
     */
    private function updateDoc(Document $doc, array $attrs): void
    {
        $doc->fill($attrs);
        $doc->save();
    }

    /**
     * Sanitize UTF-8 content to prevent PostgreSQL encoding errors
     *
     * Fixes: "invalid byte sequence for encoding UTF8"
     */
    private function sanitizeUtf8Content(string $content): string
    {
        // Remove invalid UTF-8 bytes
        $clean = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        // Fix common malformed characters from PDF OCR
        $replacements = [
            // Common OCR substitution characters
            '!' => 't',           // Often i and t become !
            '�' => '',            // Generic replacement character
            chr(0x81) => '',      // Problematic specific byte
            chr(0x8F) => '',      // Another non-UTF-8 byte
            chr(0x90) => '',      // Another non-UTF-8 byte
            chr(0x9D) => '',      // Another non-UTF-8 byte

            // Common OCR error patterns
            'plas!ca' => 'plastica',
            'riﬁu!' => 'rifiuti',
            'u!lizzare' => 'utilizzare',
            'bo%glie' => 'bottiglie',
            'pia%' => 'piatti',
            'sacche$o' => 'sacchetto',
        ];

        $clean = strtr($clean, $replacements);

        // Final validation and aggressive cleanup if still invalid
        if (! mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
            $clean = preg_replace('/[\x00-\x1F\x7F\x81\x8D\x8F\x90\x9D]/u', '', $clean);
        }

        return $clean;
    }

    /**
     * Save extracted Markdown file for preview/download
     *
     * Creates a concatenated Markdown file from chunks with metadata header.
     */
    private function saveExtractedMarkdown(Document $doc, array $chunks): string
    {
        $safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/u', '-', strtolower($doc->title ?? 'documento'));
        $fileName = $doc->id.'-'.$safeTitle.'.md';
        $dir = 'kb/'.(int) $doc->tenant_id.'/extracted';
        $fullPath = $dir.'/'.$fileName;

        // Header with minimal metadata
        $header = '# '.($doc->title ?? 'Documento')."\n\n";
        $header .= '_Tenant ID_: '.(int) $doc->tenant_id."  \n";
        if ($doc->knowledge_base_id) {
            $header .= '_KB ID_: '.(int) $doc->knowledge_base_id."  \n";
        }
        if (! empty($doc->source_url)) {
            $header .= '_Source URL_: '.$doc->source_url."  \n";
        }
        $header .= "\n---\n\n";

        // Concatenate chunks with separators
        $body = '';
        foreach ($chunks as $i => $c) {
            $body .= $c;
            if ($i < count($chunks) - 1) {
                $body .= "\n\n---\n\n"; // Visual separator
            }
        }

        $markdown = $header.$body;

        // Save to public disk for serving as link
        Storage::disk('public')->put($fullPath, $markdown);

        return $fullPath;
    }
}
