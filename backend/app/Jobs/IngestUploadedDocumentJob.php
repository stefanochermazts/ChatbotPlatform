<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\LLM\OpenAIEmbeddingsService;
use App\Services\RAG\MilvusClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\PdfToText\Pdf;

class IngestUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $documentId)
    {
        $this->onQueue('ingestion');
    }

    public function handle(OpenAIEmbeddingsService $embeddings, MilvusClient $milvus): void
    {
        $document = Document::find($this->documentId);
        if ($document === null) {
            return;
        }

        try {
            $document->update(['ingestion_status' => 'processing', 'ingestion_progress' => 0, 'last_error' => null]);

            $path = Storage::disk('public')->path($document->path);
            $content = $this->extractText($path);

            $chunks = $this->chunkText($content);
            if (count($chunks) === 0) {
                $document->update(['ingestion_status' => 'ready', 'ingestion_progress' => 100]);
                return;
            }

            $chunks = array_map([$this, 'sanitizeUtf8'], $chunks);
            $chunks = array_values(array_filter($chunks, fn ($c) => $c !== ''));
            if (count($chunks) === 0) {
                $document->update(['ingestion_status' => 'ready', 'ingestion_progress' => 100]);
                return;
            }

            // Persisto i chunk testuali su DB (rimpiazzo quelli esistenti)
            DB::transaction(function () use ($document, $chunks): void {
                DB::table('document_chunks')->where('document_id', $document->id)->delete();
                $rows = [];
                foreach ($chunks as $i => $text) {
                    $rows[] = [
                        'tenant_id' => $document->tenant_id,
                        'document_id' => $document->id,
                        'chunk_index' => $i,
                        'content' => $text,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                // Inserimento in batch
                foreach (array_chunk($rows, 100) as $batch) {
                    DB::table('document_chunks')->insert($batch);
                }
            });

            $batchSize = 16;
            $total = count($chunks);
            $embVectors = [];
            for ($i = 0; $i < $total; $i += $batchSize) {
                $slice = array_slice($chunks, $i, $batchSize);
                $vecs = $embeddings->embedTexts($slice);
                $embVectors = array_merge($embVectors, $vecs);
                $progress = (int) floor((min($i + $batchSize, $total) / $total) * 80);
                $document->update(['ingestion_progress' => $progress]);
            }

            // Upsert in Milvus (20% finali)
            $milvus->upsertVectors($document->tenant_id, $document->id, $chunks, $embVectors);
            $document->update(['ingestion_status' => 'ready', 'ingestion_progress' => 100]);
        } catch (\Throwable $e) {
            Log::error('Ingestion failed', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
            $document->update(['ingestion_status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }

    private function chunkText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $max = (int) config('rag.chunk.max_chars');
        $ovl = (int) config('rag.chunk.overlap_chars');

        $paragraphs = preg_split("/(\r?\n){2,}/", $text) ?: [$text];
        $chunks = [];
        $buffer = '';
        foreach ($paragraphs as $p) {
            if (mb_strlen($buffer) + mb_strlen($p) + 1 <= $max) {
                $buffer = $buffer === '' ? $p : ($buffer."\n".$p);
                continue;
            }
            if ($buffer !== '') {
                $chunks[] = $buffer;
            }
            $tail = mb_substr($buffer, max(0, mb_strlen($buffer) - $ovl));
            $buffer = $tail === '' ? $p : ($tail."\n".$p);
            if (mb_strlen($buffer) > $max) {
                for ($i = 0; $i < mb_strlen($buffer); $i += $max - $ovl) {
                    $chunks[] = mb_substr($buffer, $i, $max);
                }
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        return array_values(array_filter($chunks, fn ($c) => trim($c) !== ''));
    }

    private function extractText(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            if (in_array($ext, ['txt', 'md', 'csv', 'log'])) {
                $data = @file_get_contents($path) ?: '';
                return $this->sanitizeUtf8($this->normalizeWhitespace($data));
            }
            if ($ext === 'docx') {
                $zip = new \ZipArchive();
                if ($zip->open($path) === true) {
                    $xml = $zip->getFromName('word/document.xml') ?: '';
                    $zip->close();
                    $xml = preg_replace('/<w:p[^>]*>/i', "\n", $xml ?? '');
                    $xml = strip_tags($xml ?? '');
                    return $this->sanitizeUtf8($this->normalizeWhitespace($xml ?? ''));
                }
            }
            if ($ext === 'pdf') {
                // Tentativo 1: pdftotext (poppler) tramite spatie/pdf-to-text (molto accurato per layout)
                try {
                    $bin = env('PDFTOTEXT_BIN', 'pdftotext');
                    $txt = Pdf::getText($path, $bin);
                    if (is_string($txt) && trim($txt) !== '') {
                        return $this->sanitizeUtf8($this->normalizeWhitespace($txt));
                    }
                } catch (\Throwable $e) {
                    // fallback sotto
                }
                // Tentativo 2: Smalot\PdfParser
                if (class_exists('Smalot\\PdfParser\\Parser')) {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($path);
                    $txt = $pdf->getText();
                    if (is_string($txt)) {
                        return $this->sanitizeUtf8($this->normalizeWhitespace($txt));
                    }
                }
            }
        } catch (\Throwable $e) {}
        return '';
    }

    private function normalizeWhitespace(string $text): string
    {
        // Rimuove spazi doppi, normalizza linee vuote, elimina numeri pagina tipici (solo pattern semplici)
        $t = preg_replace("/\xC2\xA0/", ' ', $text); // nbsp
        $t = preg_replace('/[ \t]+/u', ' ', $t ?? '');
        $t = preg_replace('/\n{3,}/', "\n\n", $t ?? '');
        // Rimuovi footers semplici (pagina X di Y)
        $t = preg_replace('/Pagina\s+\d+\s+di\s+\d+/i', '', $t ?? '');
        return trim($t ?? '');
    }

    private function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
        if ($converted === false) {
            $converted = $text;
        }
        $converted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $converted ?? '');
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $converted) ?: $converted;
        return trim($converted);
    }
}


