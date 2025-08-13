<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\LLM\OpenAIEmbeddingsService;
use App\Services\RAG\MilvusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IngestUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(OpenAIEmbeddingsService $embeddings, MilvusClient $milvus): void
    {
        /** @var Document|null $doc */
        $doc = Document::find($this->documentId);
        if ($doc === null) {
            return;
        }

        $this->updateDoc($doc, ['ingestion_status' => 'processing', 'ingestion_progress' => 0, 'last_error' => null]);

        try {
            // 1) Carica testo dal file
            $text = $this->readTextFromStoragePath((string) $doc->path);
            if ($text === '') {
                throw new \RuntimeException('File vuoto o non parsabile');
            }

            // 2) Chunking
            $chunks = $this->chunkText($text);
            if ($chunks === []) {
                throw new \RuntimeException('Nessun chunk generato');
            }
            $total = count($chunks);
            $this->updateDoc($doc, ['ingestion_progress' => 10]);

            // 3) Embeddings
            $vectors = $embeddings->embedTexts($chunks);
            if (count($vectors) !== $total) {
                throw new \RuntimeException('Dimensione vettori non corrisponde ai chunk');
            }
            $this->updateDoc($doc, ['ingestion_progress' => 60]);

            // 4) Persistenza chunk su DB (sostituzione completa)
            DB::table('document_chunks')->where('document_id', $doc->id)->delete();
            $now = now();
            $rows = [];
            foreach ($chunks as $i => $content) {
                $rows[] = [
                    'tenant_id' => (int) $doc->tenant_id,
                    'document_id' => (int) $doc->id,
                    'chunk_index' => (int) $i,
                    'content' => (string) $content,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('document_chunks')->insert($batch);
            }
            $this->updateDoc($doc, ['ingestion_progress' => 80]);

            // 5) Indicizzazione vettori su Milvus
            $milvus->upsertVectors((int) $doc->tenant_id, (int) $doc->id, $chunks, $vectors);
            $this->updateDoc($doc, ['ingestion_status' => 'completed', 'ingestion_progress' => 100]);
        } catch (\Throwable $e) {
            Log::error('ingestion.failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $this->updateDoc($doc, ['ingestion_status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }

    private function updateDoc(Document $doc, array $attrs): void
    {
        $doc->fill($attrs);
        $doc->save();
    }

    private function readTextFromStoragePath(string $path): string
    {
        if ($path === '' || !Storage::disk('public')->exists($path)) {
            return '';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $raw = Storage::disk('public')->get($path);

        if ($ext === 'txt' || $ext === 'md') {
            return (string) $raw;
        }
        if ($ext === 'pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseContent($raw);
                return (string) $pdf->getText();
            } catch (\Throwable) {
                // fallback a stringa vuota
                return '';
            }
        }
        // Fallback semplice per altri formati: prova a trattare come testo
        return (string) $raw;
    }

    /**
     * Spezza il testo in chunk con overlap, rispettando i limiti da config.
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        $max = (int) config('rag.chunk.max_chars', 1500);
        $overlap = (int) config('rag.chunk.overlap_chars', 200);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $start = 0;
        $len = mb_strlen($text);
        while ($start < $len) {
            $end = min($len, $start + $max);
            $slice = mb_substr($text, $start, $end - $start);
            $chunks[] = $slice;
            if ($end >= $len) {
                break;
            }
            $start = $end - $overlap;
            if ($start < 0) { $start = 0; }
        }
        return $chunks;
    }
}


