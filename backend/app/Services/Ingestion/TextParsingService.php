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
}

