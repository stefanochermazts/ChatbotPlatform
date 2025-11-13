<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\TextParsingServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Service for text parsing, normalization, and preprocessing
 * 
 * Handles cleaning, table detection, and noise removal
 * 
 * @package App\Services\Ingestion
 */
class TextParsingService implements TextParsingServiceInterface
{
    /**
     * {@inheritDoc}
     */
    public function normalize(string $text): string
    {
        // Normalize line endings to \n
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        
        // Compress 3+ newlines to exactly 2 (preserve paragraph boundaries)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        
        // Remove extra spaces within lines, but preserve structural separators
        $text = implode("\n", array_map(function ($line) {
            // Preserve separators: ':' '-' '—' '|' for tables/lists
            $line = preg_replace('/\s{2,}/', ' ', $line);
            return trim($line);
        }, explode("\n", (string) $text)));
        
        return trim($text);
    }

    /**
     * Convert Markdown tables to a plain-text representation suitable for embeddings.
     *
     * Example:
     * | Nome | Ruolo |
     * | --- | --- |
     * | Mario Rossi | Presidente |
     *
     * becomes:
     * Nome: Mario Rossi; Ruolo: Presidente
     *
     * @param  string  $text
     * @return string
     */
    public function flattenMarkdownTables(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $pattern = '/((?:\|[^\n]+\|\s*(?:\n|$))+)/u';

        return preg_replace_callback($pattern, function (array $matches): string {
            $block = trim($matches[0]);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($line) => $line !== ''));

            if (count($lines) < 2) {
                return $block;
            }

            $rows = array_map(function (string $line): array {
                $cells = array_map('trim', explode('|', $line));
                return array_values(array_filter($cells, fn ($cell) => $cell !== ''));
            }, $lines);

            if (count($rows) < 2) {
                return $block;
            }

            $headers = array_shift($rows);

            if ($headers === [] || $this->isSeparatorRow($headers)) {
                return $block;
            }

            if (isset($rows[0]) && $this->isSeparatorRow($rows[0])) {
                array_shift($rows);
            }

            if ($rows === []) {
                return $block;
            }

            $flatRows = [];

            foreach ($rows as $row) {
                if ($this->isSeparatorRow($row)) {
                    continue;
                }

                $pairs = [];

                foreach ($headers as $idx => $header) {
                    $value = $row[$idx] ?? '';

                    if ($value === '') {
                        continue;
                    }

                    $pairs[] = $header.': '.$value;
                }

                if ($pairs !== []) {
                    $flatRows[] = implode('; ', $pairs);
                }
            }

            return $flatRows !== [] ? implode("\n", $flatRows) : $block;
        }, $text);
    }
    
    /**
     * {@inheritDoc}
     */
    public function findTables(string $text): array
    {
        $lines = explode("\n", $text);
        $tables = [];
        $inTable = false;
        $tableLines = [];
        $tableStartIndex = 0;
        
        Log::debug("table_detection.start", [
            'total_lines' => count($lines)
        ]);
        
        foreach ($lines as $lineIndex => $line) {
            $trimmedLine = trim($line);
            
            // Detect table line: contains at least 2 pipes
            $isTableLine = preg_match('/\|.*\|/', $trimmedLine) && substr_count($trimmedLine, '|') >= 2;
            $isEmptyLine = trim($line) === '';
            
            if ($isTableLine) {
                if (!$inTable) {
                    // Start new table
                    $inTable = true;
                    $tableStartIndex = $lineIndex;
                    $tableLines = [];
                    
                    Log::debug("table_detection.table_start", [
                        'line_index' => $lineIndex,
                        'line_content' => substr($trimmedLine, 0, 100)
                    ]);
                }
                
                $tableLines[] = $line;
            } else {
                if ($inTable) {
                    // End table
                    $inTable = false;
                    
                    if (count($tableLines) >= 2) { // At least header + 1 row
                        // Extract context before table (2 lines)
                        $contextBefore = '';
                        for ($i = max(0, $tableStartIndex - 2); $i < $tableStartIndex; $i++) {
                            $contextBefore .= $lines[$i] . "\n";
                        }
                        
                        // Extract context after table (2 lines)
                        $contextAfter = '';
                        for ($i = $lineIndex; $i < min(count($lines), $lineIndex + 2); $i++) {
                            $contextAfter .= $lines[$i] . "\n";
                        }
                        
                        $tables[] = [
                            'content' => implode("\n", $tableLines),
                            'start_line' => $tableStartIndex,
                            'end_line' => $lineIndex - 1,
                            'start_pos' => 0, // Placeholder, not used currently
                            'end_pos' => 0,   // Placeholder
                            'rows' => count($tableLines),
                            'cols' => substr_count($tableLines[0] ?? '', '|') - 1,
                            'context_before' => trim($contextBefore),
                            'context_after' => trim($contextAfter),
                        ];
                        
                        Log::debug("table_detection.table_end", [
                            'table_index' => count($tables) - 1,
                            'rows' => count($tableLines),
                            'cols' => substr_count($tableLines[0] ?? '', '|') - 1
                        ]);
                    }
                    
                    $tableLines = [];
                }
            }
        }
        
        // Handle table at end of file
        if ($inTable && count($tableLines) >= 2) {
            $contextBefore = '';
            for ($i = max(0, $tableStartIndex - 2); $i < $tableStartIndex; $i++) {
                $contextBefore .= $lines[$i] . "\n";
            }
            
            $tables[] = [
                'content' => implode("\n", $tableLines),
                'start_line' => $tableStartIndex,
                'end_line' => count($lines) - 1,
                'start_pos' => 0,
                'end_pos' => 0,
                'rows' => count($tableLines),
                'cols' => substr_count($tableLines[0] ?? '', '|') - 1,
                'context_before' => trim($contextBefore),
                'context_after' => '',
            ];
        }
        
        Log::debug("table_detection.complete", [
            'tables_found' => count($tables)
        ]);
        
        return $tables;
    }
    
    /**
     * {@inheritDoc}
     */
    public function removeTables(string $text, array $tables): string
    {
        $lines = explode("\n", $text);
        
        // Mark table lines for removal
        foreach ($tables as $table) {
            for ($i = $table['start_line']; $i <= $table['end_line']; $i++) {
                if (isset($lines[$i])) {
                    $lines[$i] = ''; // Mark for removal
                }
            }
        }
        
        // Remove empty lines and rejoin
        $cleanLines = array_filter($lines, function($line) {
            return trim($line) !== '';
        });
        
        return implode("\n", $cleanLines);
    }
    
    /**
     * {@inheritDoc}
     */
    public function removeNoise(string $text): string
    {
        // Remove common header/footer patterns
        $text = preg_replace('/Pagina\s+\d+(\s+di\s+\d+)?/i', '', $text);
        $text = preg_replace('/Page\s+\d+(\s+of\s+\d+)?/i', '', $text);
        
        // Remove repeated dashes/underscores (often used as separators)
        $text = preg_replace('/[-_]{5,}/', '', $text);
        
        // Remove control characters (except newlines and tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Remove excessive whitespace
        $text = preg_replace('/\h{5,}/', ' ', $text); // Horizontal whitespace
        
        // Remove lines that are only dots, dashes, or equals
        $text = preg_replace('/^[.\-=\s]+$/m', '', $text);
        
        return trim($text);
    }

    private function isSeparatorRow(array $cells): bool
    {
        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            $cell = trim($cell);

            if ($cell === '') {
                continue;
            }

            if (!preg_match('/^[:\-=]+$/u', $cell)) {
                return false;
            }
        }

        return true;
    }
}

