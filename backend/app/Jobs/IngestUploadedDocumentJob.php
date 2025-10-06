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
            \Log::warning('ingestion.document_not_found', ['document_id' => $this->documentId]);
            return;
        }

        // 🔒 Verifica se un altro job sta già processando questo documento
        if ($doc->ingestion_status === 'processing') {
            \Log::info('ingestion.already_processing', [
                'document_id' => $this->documentId,
                'status' => $doc->ingestion_status
            ]);
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
            $chunks = $this->chunkText($text, $doc);
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

            // 4) Persistenza chunk su DB (sostituzione completa) - ATOMIC TRANSACTION
            DB::transaction(function () use ($doc, $chunks) {
                // 🔒 Lock del documento per evitare race conditions
                $doc->refresh();
                $doc->lockForUpdate();
                
                // Elimina e reinserisci in modo atomico
                DB::table('document_chunks')->where('document_id', $doc->id)->delete();
                
                $now = now();
                $rows = [];
                foreach ($chunks as $i => $content) {
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
                    'chunks_count' => count($chunks),
                    'tenant_id' => $doc->tenant_id
                ]);
            });
            $this->updateDoc($doc, ['ingestion_progress' => 80]);

            // 5) Indicizzazione vettori su Milvus
            $milvus->upsertVectors((int) $doc->tenant_id, (int) $doc->id, $chunks, $vectors);

            // 6) Salvataggio Markdown estratto per preview/download
            try {
                $mdPath = $this->saveExtractedMarkdown($doc, $chunks);
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
     * Spezza il testo in chunk con overlap, rispettando i limiti da config tenant.
     * 🚀 TABLE-AWARE CHUNKING: Preserva tabelle complete in chunk dedicati
     * @return array<int, string>
     */
    private function chunkText(string $text, Document $doc): array
    {
        // 🎯 TENANT-SPECIFIC CHUNKING: Usa parametri del tenant
        $tenantRagConfig = app(\App\Services\RAG\TenantRagConfigService::class);
        $chunkingConfig = $tenantRagConfig->getChunkingConfig($doc->tenant_id);
        
        $max = (int) $chunkingConfig['max_chars'];
        $overlap = (int) $chunkingConfig['overlap_chars'];
        
        Log::info("tenant_chunking.parameters", [
            'tenant_id' => $doc->tenant_id,
            'document_id' => $doc->id,
            'max_chars' => $max,
            'overlap_chars' => $overlap,
            'text_length' => strlen($text)
        ]);
        
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
                // Coalescing: se inferiore a 1200 caratteri, prova a fondere con contesto successivo
                if (mb_strlen($contextualizedTable) < 1200 && isset($tables[$tableIndex + 1])) {
                    $next = trim($tables[$tableIndex + 1]['content']);
                    if ($next !== '' && mb_strlen($contextualizedTable . "\n\n" . $next) < 2200) {
                        $contextualizedTable .= "\n\n" . $next;
                    }
                }
                $contextualizedTable = trim($contextualizedTable);
                
                // Nuovo: se la tabella è in markdown, crea chunk per riga (mappando le colonne con etichette)
                $rowChunks = $this->explodeMarkdownTableIntoRowChunks($tableContent);
                if (!empty($rowChunks)) {
                    foreach ($rowChunks as $rc) {
                        // Include un minimo di contesto prima dell'elenco per migliorare recall
                        $final = trim(($table['context_before'] ? $table['context_before'] . "\n\n" : '') . $rc);
                        $chunks[] = $final;
                    }
                } else {
                    // Fallback: usa la tabella intera contestualizzata
                    $chunks[] = $contextualizedTable;
                }
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
        // 🚫 SKIP extractDirectoryEntries per documenti scraped: il Markdown è già ben formattato
        $isScrapedDocument = in_array($doc->source, ['web_scraper', 'web_scraper_linked'], true);
        
        Log::info("chunking.scraped_document_check", [
            'document_id' => $doc->id,
            'source' => $doc->source,
            'is_scraped' => $isScrapedDocument
        ]);
        
        if (count($tables) > 0) {
            $textWithoutTables = $this->removeTablesFromText($text, $tables);
            $normalizedText = $this->normalizePlainText($textWithoutTables);
            
            // Prova estrazione directory entries (Nome/Telefono/Indirizzo) SOLO per documenti NON scraped
            if (!$isScrapedDocument) {
                $dirEntries = $this->extractDirectoryEntries($normalizedText);
                if (!empty($dirEntries)) {
                    $chunks = array_merge($chunks, $dirEntries);
                    // Rimuovi possibili duplicati riducendo il testo per chunking standard
                    // (heuristic: se molte directory entries trovate, skippa chunking standard per evitare ripetizioni)
                    if (count($dirEntries) >= 5) {
                        Log::info("table_aware_chunking.directory_entries_only", [
                            'document_id' => $doc->id,
                            'entries_count' => count($dirEntries)
                        ]);
                        return $chunks;
                    }
                }
            }
            
            if (trim($normalizedText) !== '') {
                $regularChunks = $this->performStandardChunking($normalizedText, $max, $overlap);
                $chunks = array_merge($chunks, $regularChunks);
            }
        } else {
            // Nessuna tabella trovata, chunking normale su tutto il testo
            $normalizedText = $this->normalizePlainText($text);
            
            // Directory-aware splitter per liste di contatti SOLO per documenti NON scraped
            if (!$isScrapedDocument) {
                $dirEntries = $this->extractDirectoryEntries($normalizedText);
                if (!empty($dirEntries)) {
                    $chunks = array_merge($chunks, $dirEntries);
                    if (count($dirEntries) >= 5) {
                        Log::info("table_aware_chunking.directory_entries_only", [
                            'document_id' => $doc->id,
                            'entries_count' => count($dirEntries)
                        ]);
                        return $chunks;
                    }
                }
            }
            
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

        Log::info("table_detection.start", [
            'total_lines' => count($lines),
            'first_10_lines' => array_slice($lines, 0, 10)
        ]);

        foreach ($lines as $lineIndex => $line) {
            $trimmedLine = trim($line);
            
            // 🔍 Rileva linea di tabella: contiene almeno 2 pipe
            $isTableLine = preg_match('/\|.*\|/', $trimmedLine) && substr_count($trimmedLine, '|') >= 2;
            $isEmptyLine = trim($line) === '';
            
            if ($isTableLine) {
                if (!$inTable) {
                    Log::info("table_detection.table_start", [
                        'line_index' => $lineIndex,
                        'line_content' => $trimmedLine
                    ]);
                }
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

                    $tableData = [
                        'content' => implode("\n", $tableLines),
                        'context_before' => trim($contextBefore),
                        'context_after' => trim($contextAfter),
                        'start_line' => $tableStartIndex,
                        'end_line' => $lineIndex - 1,
                        'rows_count' => count($tableLines)
                    ];
                    $tables[] = $tableData;
                    
                    Log::info("table_detection.table_found", [
                        'table_index' => count($tables) - 1,
                        'rows_count' => count($tableLines),
                        'start_line' => $tableStartIndex,
                        'end_line' => $lineIndex - 1,
                        'first_3_lines' => array_slice($tableLines, 0, 3)
                    ]);
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
            
            $tableData = [
                'content' => implode("\n", $tableLines),
                'context_before' => trim($contextBefore),
                'context_after' => '',
                'start_line' => $tableStartIndex,
                'end_line' => count($lines) - 1,
                'rows_count' => count($tableLines)
            ];
            $tables[] = $tableData;
            
            Log::info("table_detection.table_found_end", [
                'table_index' => count($tables) - 1,
                'rows_count' => count($tableLines),
                'start_line' => $tableStartIndex,
                'end_line' => count($lines) - 1,
                'first_3_lines' => array_slice($tableLines, 0, 3)
            ]);
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
     * Normalizza il testo preservando separatori, etichette e chiavi/valori
     * per migliorare la ricercabilità di campi (es. telefono, email, orari).
     */
    private function normalizePlainText(string $text): string
    {
        // Sostituisci sequenze di spazi multipli con singolo spazio, ma preserva newline doppie come separatori paragrafo
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        // comprimi più di 2 newline in esattamente 2
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        // rimuovi spazi extra all'interno delle righe
        $text = implode("\n", array_map(function ($line) {
            // preserva separatori ':' '-' '—' '|' per tabelle/elenchi
            $line = preg_replace('/\s{2,}/', ' ', $line);
            return trim($line);
        }, explode("\n", (string) $text)));
        return trim($text);
    }

    /**
     * Estrae “directory entries” da testo non tabellare (Nome/Telefono/Indirizzo...) in chunk key:value
     */
    private function extractDirectoryEntries(string $text): array
    {
        $lines = array_values(array_filter(array_map(fn($l) => trim((string)$l), explode("\n", $text))));
        if (count($lines) < 3) return [];

        $chunks = [];
        $phoneRegex = '/(?:(?:\+?39\s?)?)?(?:\d[\d\.\-\s]{5,}\d)/u';
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($line === '') continue;

            if (preg_match_all($phoneRegex, $line, $m) && !empty($m[0])) {
                // Trova nome sulla/e riga/e precedenti
                $name = '';
                for ($k = 1; $k <= 2; $k++) {
                    $idx = $i - $k;
                    if ($idx >= 0 && $lines[$idx] !== '' && !preg_match($phoneRegex, $lines[$idx])) {
                        $name = $lines[$idx];
                        break;
                    }
                }
                // Indirizzo o info aggiuntive sulla riga successiva
                $address = '';
                if (isset($lines[$i + 1]) && $lines[$i + 1] !== '' && !preg_match($phoneRegex, $lines[$i + 1])) {
                    $address = $lines[$i + 1];
                }
                $phones = implode(' / ', array_map('trim', $m[0]));
                // Crea chunk
                $parts = [];
                if ($name !== '') $parts[] = "Nome: $name";
                $parts[] = "Telefono: $phones";
                if ($address !== '') $parts[] = "Indirizzo: $address";
                $chunk = implode("\n", $parts);
                if (mb_strlen($chunk) >= 15) {
                    $chunks[] = $chunk;
                }
            }
        }
        // Dedup
        $chunks = array_values(array_unique($chunks));
        // Se meno di 2, non considerare affidabile
        return count($chunks) >= 2 ? $chunks : [];
    }

    /**
     * 📝 Chunking standard per testo normale - WORD-AWARE per preservare informazioni
     */
    private function performStandardChunking(string $text, int $max, int $overlap): array
    {
        $chunks = [];
        $text = trim($text);
        
        if (mb_strlen($text) <= $max) {
            return [$text]; // Testo piccolo, nessun chunking necessario
        }
        
        // 🔍 Dividi per paragrafi prima, poi per frasi per preservare struttura
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $currentChunk = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;
            
            // Se il paragrafo corrente + quello nuovo supera il limite
            if (mb_strlen($currentChunk . "\n\n" . $paragraph) > $max && !empty($currentChunk)) {
                // Salva il chunk corrente e inizia nuovo
                $chunks[] = trim($currentChunk);
                
                // 🔄 OVERLAP: inizia il nuovo chunk con gli ultimi N caratteri del precedente
                $overlapText = $this->getLastWords($currentChunk, $overlap);
                $currentChunk = $overlapText;
            }
            
            // Aggiungi il paragrafo al chunk corrente
            if (!empty($currentChunk)) {
                $currentChunk .= "\n\n" . $paragraph;
            } else {
                $currentChunk = $paragraph;
            }
            
            // 🚨 Se un singolo paragrafo è troppo lungo, suddividilo per frasi
            if (mb_strlen($currentChunk) > $max) {
                $sentenceChunks = $this->chunkLongParagraph($currentChunk, $max, $overlap);
                if (count($sentenceChunks) > 1) {
                    // Aggiungi tutti i chunk tranne l'ultimo
                    for ($i = 0; $i < count($sentenceChunks) - 1; $i++) {
                        $chunks[] = trim($sentenceChunks[$i]);
                    }
                    // L'ultimo diventa il chunk corrente
                    $currentChunk = trim($sentenceChunks[count($sentenceChunks) - 1]);
                }
            }
        }
        
        // Aggiungi l'ultimo chunk se non vuoto
        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }
        
        // 🛡️ FAIL-SAFE: se nessun chunk generato, usa chunking caratteri di emergenza
        if (empty($chunks)) {
            Log::warning("chunking.fallback_to_char_chunking", [
                'text_length' => mb_strlen($text),
                'max_chars' => $max
            ]);
            return $this->performEmergencyCharChunking($text, $max, $overlap);
        }
        
        return $chunks;
    }
    
    /**
     * 🔄 Ottieni le ultime N parole di un testo per overlap
     */
    private function getLastWords(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        
        // Prendi gli ultimi N caratteri, poi trova l'ultimo spazio per non spezzare parole
        $lastChars = mb_substr($text, -$maxChars);
        $firstSpace = mb_strpos($lastChars, ' ');
        
        if ($firstSpace !== false && $firstSpace > 0) {
            return mb_substr($lastChars, $firstSpace + 1);
        }
        
        return $lastChars; // Se non trovi spazi, prendi tutto
    }
    
    /**
     * 🚨 Chunking di emergenza per paragrafi troppo lunghi - SENTENCE-AWARE
     */
    private function chunkLongParagraph(string $paragraph, int $max, int $overlap): array
    {
        // Dividi per frasi (. ! ? + spazio/newline)
        $sentences = preg_split('/([.!?]+)(\s+|$)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $chunks = [];
        $currentChunk = '';
        
        for ($i = 0; $i < count($sentences); $i += 3) { // Ogni frase ha 3 parti: testo, punteggiatura, spazio
            $sentence = '';
            if (isset($sentences[$i])) $sentence .= $sentences[$i];
            if (isset($sentences[$i + 1])) $sentence .= $sentences[$i + 1];
            if (isset($sentences[$i + 2])) $sentence .= $sentences[$i + 2];
            
            $sentence = trim($sentence);
            if (empty($sentence)) continue;
            
            // Se aggiungere questa frase supera il limite
            if (mb_strlen($currentChunk . ' ' . $sentence) > $max && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                
                // Overlap con ultime parole
                $overlapText = $this->getLastWords($currentChunk, $overlap);
                $currentChunk = $overlapText . ' ' . $sentence;
            } else {
                $currentChunk = empty($currentChunk) ? $sentence : $currentChunk . ' ' . $sentence;
            }
        }
        
        // Ultimo chunk
        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }
        
        // Se ancora troppo lungo, usa chunking caratteri di emergenza
        if (empty($chunks) || max(array_map('mb_strlen', $chunks)) > $max * 1.5) {
            return $this->performEmergencyCharChunking($paragraph, $max, $overlap);
        }
        
        return $chunks;
    }
    
    /**
     * 🆘 Chunking di emergenza per caratteri (versione originale come fallback)
     */
    private function performEmergencyCharChunking(string $text, int $max, int $overlap): array
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

    /**
     * Converte una tabella markdown in chunk per singola riga, esponendo le colonne come key:value
     */
    private function explodeMarkdownTableIntoRowChunks(string $tableMarkdown): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $tableMarkdown))));
        Log::info("table_explosion.start", [
            'total_lines' => count($lines),
            'first_5_lines' => array_slice($lines, 0, 5)
        ]);
        
        if (count($lines) < 2) {
            Log::info("table_explosion.too_few_lines", ['lines' => count($lines)]);
            return [];
        }
        // Individua header e separatore ---|---
        $headerLine = null;
        $separatorIndex = -1;
        foreach ($lines as $i => $line) {
            if ($headerLine === null && preg_match('/\|/', $line)) {
                $headerLine = $line;
                Log::info("table_explosion.header_found", ['header' => $headerLine]);
                continue;
            }
            if ($headerLine !== null && preg_match('/^\|?\s*:?-+.*\|.*-+.*\s*\|?\s*$/', $line)) {
                $separatorIndex = $i;
                Log::info("table_explosion.separator_found", ['separator' => $line, 'index' => $i]);
                break;
            }
        }
        if ($headerLine === null || $separatorIndex < 0) {
            Log::info("table_explosion.invalid_table", [
                'header_found' => $headerLine !== null,
                'separator_found' => $separatorIndex >= 0
            ]);
            return [];
        }
        $headers = array_map('trim', array_filter(array_map('trim', explode('|', trim($headerLine, '| ')))));
        if (empty($headers)) {
            return [];
        }
        $rows = array_slice($lines, $separatorIndex + 1);
        $chunks = [];
        foreach ($rows as $row) {
            if ($row === '' || !str_contains($row, '|')) continue;
            $cols = array_map('trim', array_filter(array_map('trim', explode('|', trim($row, '| ')))));
            if (empty($cols)) continue;
            // Allinea numero colonne
            $pairs = [];
            for ($i = 0; $i < min(count($headers), count($cols)); $i++) {
                $h = $headers[$i];
                $v = $cols[$i];
                if ($v !== '') {
                    $pairs[] = "$h: $v";
                }
            }
            if (!empty($pairs)) {
                $chunks[] = implode("\n", $pairs);
            }
        }
        
        Log::info("table_explosion.completed", [
            'total_chunks_created' => count($chunks),
            'headers' => $headers,
            'total_rows_processed' => count($rows),
            'first_chunk_sample' => $chunks[0] ?? null
        ]);
        
        return $chunks;
    }

    /**
     * 🧹 Sanitizza contenuto rimuovendo caratteri non UTF-8 validi
     * Risolve errore PostgreSQL: "invalid byte sequence for encoding UTF8"
     */
    private function sanitizeUtf8Content(string $content): string
    {
        // 🚀 STEP 1: Rimuovi byte non UTF-8 validi
        $clean = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        
        // 🚀 STEP 2: Correggi caratteri comuni malformati dai PDF
        $replacements = [
            // Caratteri di sostituzione comuni da OCR
            '!' => 't',           // spesso i e t diventano !
            '�' => '',            // carattere di replacement generico
            chr(0x81) => '',      // byte problematico specifico
            chr(0x8F) => '',      // altro byte non UTF-8
            chr(0x90) => '',      // altro byte non UTF-8
            chr(0x9D) => '',      // altro byte non UTF-8
            
            // Pattern comuni di OCR errato
            'plas!ca' => 'plastica',
            'riﬁu!' => 'rifiuti',
            'u!lizzare' => 'utilizzare',
            'bo%glie' => 'bottiglie',
            'pia%' => 'piatti',
            'sacche$o' => 'sacchetto',
        ];
        
        // Applica sostituzioni
        $clean = strtr($clean, $replacements);
        
        // 🚀 STEP 3: Verifica finale e fallback
        if (!mb_check_encoding($clean, 'UTF-8')) {
            // Se ancora non valido, forza pulizia aggressiva
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
            $clean = preg_replace('/[\x00-\x1F\x7F\x81\x8D\x8F\x90\x9D]/u', '', $clean);
        }
        
        Log::debug('pdf.content_sanitized', [
            'original_length' => strlen($content),
            'clean_length' => strlen($clean),
            'has_replacements' => $content !== $clean
        ]);
        
        return $clean;
    }

    /**
     * Salva un file Markdown concatenando i chunk estratti
     * in percorso pubblico segregato per tenant/KB.
     */
    private function saveExtractedMarkdown(Document $doc, array $chunks): string
    {
        $safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/u', '-', strtolower($doc->title ?? 'documento'));
        $fileName = $doc->id . '-' . $safeTitle . '.md';
        $dir = 'kb/' . (int) $doc->tenant_id . '/extracted';
        $fullPath = $dir . '/' . $fileName;

        // Header con metadati minimi
        $header = "# " . ($doc->title ?? 'Documento') . "\n\n";
        $header .= "_Tenant ID_: " . (int) $doc->tenant_id . "  \n";
        if ($doc->knowledge_base_id) {
            $header .= "_KB ID_: " . (int) $doc->knowledge_base_id . "  \n";
        }
        if (!empty($doc->source_url)) {
            $header .= "_Source URL_: " . $doc->source_url . "  \n";
        }
        $header .= "\n---\n\n";

        // Concatena i chunk con separatori
        $body = '';
        foreach ($chunks as $i => $c) {
            $body .= $c;
            if ($i < count($chunks) - 1) {
                $body .= "\n\n---\n\n"; // separatore visivo
            }
        }

        $markdown = $header . $body;

        // Salva su disco pubblico per poterlo servire come link
        Storage::disk('public')->put($fullPath, $markdown);

        return $fullPath; // da memorizzare in documents.extracted_path
    }
}


