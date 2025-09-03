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
        $fullPath = Storage::disk('public')->path($path);

        // File di testo semplici
        if ($ext === 'txt' || $ext === 'md') {
            $raw = Storage::disk('public')->get($path);
            return (string) $raw;
        }

        // PDF
        if ($ext === 'pdf') {
            try {
                $raw = Storage::disk('public')->get($path);
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseContent($raw);
                return (string) $pdf->getText();
            } catch (\Throwable $e) {
                Log::warning('pdf.parse_failed', ['path' => $path, 'error' => $e->getMessage()]);
                return '';
            }
        }

        // Microsoft Word (.docx, .doc)
        if ($ext === 'docx' || $ext === 'doc') {
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
                $text = '';
                
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . ' ';
                        } elseif (method_exists($element, 'getElements')) {
                            // Gestisce elementi complessi come tabelle, liste, etc.
                            $text .= $this->extractTextFromComplexElement($element) . ' ';
                        }
                    }
                }
                
                return trim($text);
            } catch (\Throwable $e) {
                Log::warning('docx.parse_failed', ['path' => $path, 'error' => $e->getMessage()]);
                return '';
            }
        }

        // Microsoft Excel (.xlsx, .xls)
        if ($ext === 'xlsx' || $ext === 'xls') {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
                $text = '';
                
                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    $highestRow = $sheet->getHighestRow();
                    $highestColumn = $sheet->getHighestColumn();
                    
                    for ($row = 1; $row <= $highestRow; $row++) {
                        for ($col = 'A'; $col <= $highestColumn; $col++) {
                            $cell = $sheet->getCell($col . $row);
                            
                            // Prova diversi metodi per ottenere il valore della cella
                            $cellValue = '';
                            try {
                                // Metodo preferito: getValue() per il valore raw
                                $cellValue = (string) $cell->getValue();
                                
                                // Se è vuoto, prova getFormattedValue() per valori formattati
                                if (empty($cellValue) && method_exists($cell, 'getFormattedValue')) {
                                    $cellValue = (string) $cell->getFormattedValue();
                                }
                                
                                // Se è ancora vuoto, prova getCalculatedValue() per formule
                                if (empty($cellValue) && method_exists($cell, 'getCalculatedValue')) {
                                    $cellValue = (string) $cell->getCalculatedValue();
                                }
                            } catch (\Throwable $cellError) {
                                // Se una cella ha problemi, continua con le altre
                                Log::debug('xlsx.cell_read_failed', [
                                    'path' => $path,
                                    'cell' => $col . $row,
                                    'error' => $cellError->getMessage()
                                ]);
                                $cellValue = '';
                            }
                            
                            if (!empty(trim($cellValue))) {
                                $text .= $cellValue . ' ';
                            }
                        }
                        $text .= "\n";
                    }
                }
                
                return trim($text);
            } catch (\Throwable $e) {
                Log::warning('xlsx.parse_failed', ['path' => $path, 'error' => $e->getMessage()]);
                return '';
            }
        }

        // Microsoft PowerPoint (.pptx, .ppt)
        if ($ext === 'pptx' || $ext === 'ppt') {
            try {
                // PhpOffice non ha ancora un parser completo per PowerPoint
                // Fallback: prova a estrarre con zip per .pptx
                if ($ext === 'pptx') {
                    return $this->extractTextFromPptx($fullPath);
                }
                
                Log::info('ppt.unsupported', ['path' => $path, 'ext' => $ext]);
                return '';
            } catch (\Throwable $e) {
                Log::warning('ppt.parse_failed', ['path' => $path, 'error' => $e->getMessage()]);
                return '';
            }
        }

        // Fallback per formati non supportati
        Log::info('file.unsupported_format', ['path' => $path, 'ext' => $ext]);
        return '';
    }

    /**
     * Spezza il testo in chunk con overlap, rispettando i limiti da config.
     * 🚀 TABLE-AWARE CHUNKING: Preserva tabelle complete in chunk dedicati
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        $max = (int) config('rag.chunk.max_chars', 1500);
        $overlap = (int) config('rag.chunk.overlap_chars', 200);
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // 🔍 STEP 1: Rileva tabelle markdown nel testo
        $tables = $this->findTablesInText($text);
        $chunks = [];

        // 🚀 STEP 2: Crea chunk dedicati per ogni tabella (IGNORA limiti di caratteri)
        foreach ($tables as $tableIndex => $table) {
            $tableContent = trim($table['content']);
            if (strlen($tableContent) > 0) {
                // Aggiungi contesto se disponibile
                $contextualizedTable = $table['context_before'] . "\n\n" . $tableContent . "\n\n" . $table['context_after'];
                $contextualizedTable = trim($contextualizedTable);
                
                $chunks[] = $contextualizedTable;
                Log::info("table_aware_chunking.table_preserved", [
                    'table_index' => $tableIndex,
                    'table_chars' => strlen($tableContent),
                    'with_context_chars' => strlen($contextualizedTable),
                    'rows_detected' => substr_count($tableContent, '|'),
                    'preview' => substr($tableContent, 0, 200)
                ]);
            }
        }

        // 📝 STEP 3: Chunking normale per il resto del testo (escludendo tabelle)
        if (count($tables) > 0) {
            $textWithoutTables = $this->removeTablesFromText($text, $tables);
            $normalizedText = trim(preg_replace('/\s+/', ' ', $textWithoutTables));
            if (trim($normalizedText) !== '') {
                $regularChunks = $this->performStandardChunking($normalizedText, $max, $overlap);
                $chunks = array_merge($chunks, $regularChunks);
            }
        } else {
            // Nessuna tabella trovata, chunking normale su tutto il testo
            $normalizedText = trim(preg_replace('/\s+/', ' ', $text));
            $regularChunks = $this->performStandardChunking($normalizedText, $max, $overlap);
            $chunks = array_merge($chunks, $regularChunks);
        }

        Log::info("table_aware_chunking.completed", [
            'total_chunks' => count($chunks),
            'table_chunks' => count($tables),
            'regular_chunks' => count($chunks) - count($tables),
            'original_text_chars' => strlen($text),
            'tables_detected' => count($tables)
        ]);

        return $chunks;
    }

    /**
     * 🔍 Trova tabelle markdown nel testo preservando contesto
     */
    private function findTablesInText(string $text): array
    {
        $lines = explode("\n", $text);
        $tables = [];
        $inTable = false;
        $tableLines = [];
        $tableStartIndex = 0;

        foreach ($lines as $lineIndex => $line) {
            $trimmedLine = trim($line);
            
            // 🔍 Rileva linea di tabella: contiene almeno 2 pipe
            $isTableLine = preg_match('/\|.*\|/', $trimmedLine) && substr_count($trimmedLine, '|') >= 2;
            $isEmptyLine = trim($line) === '';
            
            if ($isTableLine) {
                if (!$inTable) {
                    // Inizia nuova tabella
                    $inTable = true;
                    $tableStartIndex = $lineIndex;
                    $tableLines = [];
                }
                $tableLines[] = $line;
            } elseif ($inTable && $isEmptyLine) {
                // Riga vuota all'interno di una tabella - mantieni la tabella aperta
                // ma non aggiungere la riga vuota
                continue;
            } else {
                // Linea con contenuto non-tabella dopo essere in tabella = fine tabella
                if ($inTable && count($tableLines) >= 2) {
                    // Cattura contesto prima della tabella (max 3 righe)
                    $contextBefore = '';
                    $contextStart = max(0, $tableStartIndex - 3);
                    for ($i = $contextStart; $i < $tableStartIndex; $i++) {
                        if (isset($lines[$i]) && trim($lines[$i]) !== '') {
                            $contextBefore .= $lines[$i] . "\n";
                        }
                    }
                    
                    // Cattura contesto dopo la tabella (max 3 righe)
                    $contextAfter = '';
                    $contextEnd = min(count($lines), $lineIndex + 3);
                    for ($i = $lineIndex; $i < $contextEnd; $i++) {
                        if (isset($lines[$i]) && trim($lines[$i]) !== '') {
                            $contextAfter .= $lines[$i] . "\n";
                        }
                    }

                    $tables[] = [
                        'content' => implode("\n", $tableLines),
                        'context_before' => trim($contextBefore),
                        'context_after' => trim($contextAfter),
                        'start_line' => $tableStartIndex,
                        'end_line' => $lineIndex - 1,
                        'rows_count' => count($tableLines)
                    ];
                }
                $inTable = false;
                $tableLines = [];
            }
        }

        // 🔄 Gestisci tabella che finisce alla fine del file
        if ($inTable && count($tableLines) >= 2) {
            $contextBefore = '';
            $contextStart = max(0, $tableStartIndex - 3);
            for ($i = $contextStart; $i < $tableStartIndex; $i++) {
                if (isset($lines[$i]) && trim($lines[$i]) !== '') {
                    $contextBefore .= $lines[$i] . "\n";
                }
            }
            
            $tables[] = [
                'content' => implode("\n", $tableLines),
                'context_before' => trim($contextBefore),
                'context_after' => '',
                'start_line' => $tableStartIndex,
                'end_line' => count($lines) - 1,
                'rows_count' => count($tableLines)
            ];
        }

        return $tables;
    }

    /**
     * 🗑️ Rimuove tabelle dal testo mantenendo il resto
     */
    private function removeTablesFromText(string $text, array $tables): string
    {
        $lines = explode("\n", $text);
        
        // Marca le linee delle tabelle per rimozione
        foreach ($tables as $table) {
            for ($i = $table['start_line']; $i <= $table['end_line']; $i++) {
                if (isset($lines[$i])) {
                    $lines[$i] = ''; // Marca per rimozione
                }
            }
        }
        
        // Rimuovi linee vuote e ricomponi
        $cleanLines = array_filter($lines, function($line) {
            return trim($line) !== '';
        });
        
        return implode("\n", $cleanLines);
    }

    /**
     * 📝 Chunking standard per testo normale
     */
    private function performStandardChunking(string $text, int $max, int $overlap): array
    {
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

    /**
     * Estrae testo da elementi complessi di Word (tabelle, liste, etc.)
     */
    private function extractTextFromComplexElement($element): string
    {
        $text = '';
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $subElement) {
                if (method_exists($subElement, 'getText')) {
                    $text .= $subElement->getText() . ' ';
                } elseif (method_exists($subElement, 'getElements')) {
                    // Ricorsione per elementi annidati
                    $text .= $this->extractTextFromComplexElement($subElement) . ' ';
                }
            }
        }
        
        return $text;
    }

    /**
     * Estrae testo da PowerPoint .pptx usando ZIP
     */
    private function extractTextFromPptx(string $filePath): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) !== true) {
                return '';
            }

            $text = '';
            
            // Itera sui slide nel PowerPoint
            for ($i = 1; $i <= 100; $i++) {
                $slideXml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if ($slideXml === false) {
                    break; // Non ci sono più slide
                }
                
                // Estrae il testo dai tag <a:t>
                if (preg_match_all('/<a:t[^>]*>([^<]*)<\/a:t>/', $slideXml, $matches)) {
                    foreach ($matches[1] as $match) {
                        $text .= html_entity_decode($match) . ' ';
                    }
                }
                $text .= "\n";
            }
            
            $zip->close();
            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('pptx.zip_extract_failed', ['path' => $filePath, 'error' => $e->getMessage()]);
            return '';
        }
    }
}


