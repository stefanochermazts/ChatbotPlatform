<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TestDocumentParsing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'document:test-parsing {file_path : Percorso del file da testare (relativo a storage/app/public)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa il parsing di un documento per verificare l\'estrazione del testo';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file_path');

        if (! Storage::disk('public')->exists($filePath)) {
            $this->error("❌ File non trovato: {$filePath}");
            $this->info("💡 Assicurati che il file sia in storage/app/public/{$filePath}");

            return 1;
        }

        $this->info("🔍 Testando parsing del file: {$filePath}");

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $size = Storage::disk('public')->size($filePath);

        $this->info("📄 Estensione: {$ext}");
        $this->info('📏 Dimensione: '.$this->formatBytes($size));

        try {
            $text = $this->readTextFromStoragePath($filePath);

            if (empty($text)) {
                $this->warn('⚠️  Nessun testo estratto dal file');

                return 1;
            }

            $wordCount = str_word_count($text);
            $charCount = mb_strlen($text);

            $this->info('✅ Testo estratto con successo!');
            $this->info('📊 Statistiche:');
            $this->info("   - Caratteri: {$charCount}");
            $this->info("   - Parole: {$wordCount}");

            // Mostra preview del testo
            $preview = mb_substr($text, 0, 200);
            if (mb_strlen($text) > 200) {
                $preview .= '...';
            }

            $this->info("\n📝 Preview del testo estratto:");
            $this->line($preview);

            // Test chunking
            $chunks = $this->chunkText($text);
            $this->info("\n🧩 Test chunking:");
            $this->info('   - Chunk generati: '.count($chunks));

            if (count($chunks) > 0) {
                $firstChunkLength = mb_strlen($chunks[0]);
                $this->info("   - Lunghezza primo chunk: {$firstChunkLength} caratteri");
            }

            return 0;

        } catch (\Throwable $e) {
            $this->error('❌ Errore durante il parsing:');
            $this->error('Classe: '.get_class($e));
            $this->error('Messaggio: '.$e->getMessage());

            $this->info("\n💡 Suggerimenti:");
            $this->info('- Verifica che il file non sia corrotto');
            $this->info('- Controlla che le librerie PHPOffice siano installate');
            $this->info('- Controlla i log in storage/logs/laravel.log');

            return 1;
        }
    }

    /**
     * Copia del metodo di parsing dal Job (per testing)
     */
    private function readTextFromStoragePath(string $path): string
    {
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
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
                $parser = new \Smalot\PdfParser\Parser;
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
                            $text .= $element->getText().' ';
                        } elseif (method_exists($element, 'getElements')) {
                            // Gestisce elementi complessi come tabelle, liste, etc.
                            $text .= $this->extractTextFromComplexElement($element).' ';
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
                            $cell = $sheet->getCell($col.$row);

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
                                    'cell' => $col.$row,
                                    'error' => $cellError->getMessage(),
                                ]);
                                $cellValue = '';
                            }

                            if (! empty(trim($cellValue))) {
                                $text .= $cellValue.' ';
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
     * Estrae testo da elementi complessi di Word (tabelle, liste, etc.)
     */
    private function extractTextFromComplexElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $subElement) {
                if (method_exists($subElement, 'getText')) {
                    $text .= $subElement->getText().' ';
                } elseif (method_exists($subElement, 'getElements')) {
                    // Ricorsione per elementi annidati
                    $text .= $this->extractTextFromComplexElement($subElement).' ';
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
            $zip = new \ZipArchive;
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
                        $text .= html_entity_decode($match).' ';
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
     * Copia del metodo di chunking dal Job (per testing)
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
            if ($start < 0) {
                $start = 0;
            }
        }

        return $chunks;
    }

    /**
     * Formatta i byte in una stringa leggibile
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
