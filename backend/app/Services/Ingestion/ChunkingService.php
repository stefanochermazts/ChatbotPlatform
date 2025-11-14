<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\ChunkingServiceInterface;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Support\Facades\Log;

/**
 * Service for semantic text chunking
 *
 * Implements multiple chunking strategies:
 * - Standard semantic chunking (paragraph → sentence → hard split)
 * - Table-aware chunking
 * - Directory entry extraction
 *
 * ✅ FIXED: Now respects tenant-specific RAG configuration (Step 3/9)
 */
class ChunkingService implements ChunkingServiceInterface
{
    /**
     * @param  TenantRagConfigService  $tenantConfig  Service for retrieving tenant-specific config
     */
    public function __construct(
        private readonly TenantRagConfigService $tenantConfig
    ) {}

    /**
     * {@inheritDoc}
     *
     * ✅ FIXED: Now requires $tenantId for tenant-aware chunking (Step 3/9)
     */
    public function chunk(string $text, int $tenantId, array $options = []): array
    {
        // ✅ FIXED: Get tenant-specific config instead of global config
        $tenantChunkConfig = $this->tenantConfig->getChunkingConfig($tenantId);

        $maxChars = $options['max_chars'] ?? $tenantChunkConfig['max_chars'];
        $overlapChars = $options['overlap_chars'] ?? $tenantChunkConfig['overlap_chars'];
        $strategy = $options['strategy'] ?? 'standard';
        $removeBoilerplate = $options['remove_boilerplate'] ?? false;

        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // ✅ FIX: Remove boilerplate for web_scraper documents to improve semantic similarity
        if ($removeBoilerplate) {
            $text = $this->removeScraperBoilerplate($text);
        }

        Log::debug('chunking.start', [
            'text_length' => strlen($text),
            'max_chars' => $maxChars,
            'overlap_chars' => $overlapChars,
            'strategy' => $strategy,
            'remove_boilerplate' => $removeBoilerplate,
        ]);

        return match ($strategy) {
            'standard' => $this->performStandardChunking($text, $maxChars, $overlapChars),
            'sentence' => $this->chunkBySentences($text, $maxChars, $overlapChars),
            'hard' => $this->performHardChunking($text, $maxChars, $overlapChars),
            default => $this->performStandardChunking($text, $maxChars, $overlapChars)
        };
    }

    /**
     * Remove scraper boilerplate (URL, "Scraped on", etc.) from text
     *
     * @param  string  $text  Text with potential boilerplate
     * @return string Cleaned text
     */
    private function removeScraperBoilerplate(string $text): string
    {
        // Remove lines starting with "# " followed by domain (e.g., "# www.comune.sancesareo.rm.it")
        $text = preg_replace('/^#\s+[a-z0-9\-\.]+\s*$/m', '', $text);

        // Remove "**URL:** ..." lines
        $text = preg_replace('/^\*\*URL:\*\*.*$/m', '', $text);

        // Remove "**Scraped on:** ..." lines
        $text = preg_replace('/^\*\*Scraped on:\*\*.*$/m', '', $text);

        // Remove multiple consecutive blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * {@inheritDoc}
     */
    public function chunkTables(array $tables): array
    {
        $chunks = [];

        foreach ($tables as $tableIndex => $table) {
            $tableContent = trim($table['content']);
            if (strlen($tableContent) === 0) {
                continue;
            }

            // Add context before and after table
            $contextualizedTable = $table['context_before']."\n\n".$tableContent."\n\n".$table['context_after'];
            $contextualizedTable = trim($contextualizedTable);

            // ✅ FIX: Do NOT explode small tables (<10 rows) to preserve context
            // Small tables (contact info, schedules, etc.) should stay together
            // to avoid mixing information from different services/offices
            $rowCount = $table['rows'] ?? 0;
            $shouldExplode = $rowCount >= 10;

            // Try to explode markdown tables into row chunks (ONLY for large tables)
            $rowChunks = [];
            if ($shouldExplode) {
                $rowChunks = $this->explodeMarkdownTableIntoRowChunks($tableContent);
            }

            if (! empty($rowChunks)) {
                // Include minimal context before each row
                foreach ($rowChunks as $rc) {
                    $final = trim(($table['context_before'] ? $table['context_before']."\n\n" : '').$rc);
                    $chunks[] = [
                        'text' => $final,
                        'type' => 'table_row',
                        'position' => $tableIndex,
                        'metadata' => [
                            'table_index' => $tableIndex,
                            'rows' => $table['rows'] ?? 0,
                            'cols' => $table['cols'] ?? 0,
                        ],
                    ];
                }

                Log::debug('table_chunking.exploded', [
                    'table_index' => $tableIndex,
                    'rows' => $rowCount,
                    'chunks_created' => count($rowChunks),
                ]);
            } else {
                // Fallback: use entire contextualized table
                // This preserves context for small tables (contacts, schedules, etc.)
                $chunks[] = [
                    'text' => $contextualizedTable,
                    'type' => 'table',
                    'position' => $tableIndex,
                    'metadata' => [
                        'table_index' => $tableIndex,
                        'rows' => $table['rows'] ?? 0,
                        'cols' => $table['cols'] ?? 0,
                        'chars' => strlen($contextualizedTable),
                    ],
                ];

                Log::debug('table_chunking.preserved_whole', [
                    'table_index' => $tableIndex,
                    'rows' => $rowCount,
                    'reason' => $shouldExplode ? 'explosion_failed' : 'small_table_preserve_context',
                ]);
            }

            Log::debug('table_chunking.table_processed', [
                'table_index' => $tableIndex,
                'table_chars' => strlen($tableContent),
                'chunks_created' => count($rowChunks) > 0 ? count($rowChunks) : 1,
            ]);
        }

        return $chunks;
    }

    /**
     * {@inheritDoc}
     */
    public function extractDirectoryEntries(string $text): array
    {
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), explode("\n", $text))));
        if (count($lines) < 3) {
            return [];
        }

        $chunks = [];
        $phoneRegex = '/(?:(?:\+?39\s?)?)?(?:\d[\d\.\-\s]{5,}\d)/u';

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($line === '') {
                continue;
            }

            if (preg_match_all($phoneRegex, $line, $m) && ! empty($m[0])) {
                // Find name in previous lines
                $name = '';
                for ($k = 1; $k <= 2; $k++) {
                    $idx = $i - $k;
                    if ($idx >= 0 && $lines[$idx] !== '' && ! preg_match($phoneRegex, $lines[$idx])) {
                        $name = $lines[$idx];
                        break;
                    }
                }

                // Find address or additional info in next line
                $address = '';
                if (isset($lines[$i + 1]) && $lines[$i + 1] !== '' && ! preg_match($phoneRegex, $lines[$i + 1])) {
                    $address = $lines[$i + 1];
                }

                $phones = implode(' / ', array_map('trim', $m[0]));

                // Create chunk
                $parts = [];
                if ($name !== '') {
                    $parts[] = "Nome: $name";
                }
                $parts[] = "Telefono: $phones";
                if ($address !== '') {
                    $parts[] = "Indirizzo: $address";
                }

                $chunkText = implode("\n", $parts);
                if (mb_strlen($chunkText) >= 15) {
                    $chunks[] = [
                        'text' => $chunkText,
                        'type' => 'directory_entry',
                        'position' => $i,
                        'metadata' => [
                            'name' => $name,
                            'phones' => $phones,
                            'address' => $address,
                        ],
                    ];
                }
            }
        }

        // Dedup
        $uniqueTexts = [];
        $uniqueChunks = [];
        foreach ($chunks as $chunk) {
            if (! in_array($chunk['text'], $uniqueTexts, true)) {
                $uniqueTexts[] = $chunk['text'];
                $uniqueChunks[] = $chunk;
            }
        }

        // Return only if at least 2 entries found (reliability check)
        return count($uniqueChunks) >= 2 ? $uniqueChunks : [];
    }

    /**
     * {@inheritDoc}
     */
    public function calculateOptimalChunkSize(string $text): int
    {
        $textLength = mb_strlen($text);

        // For very short texts, no chunking needed
        if ($textLength < 1000) {
            return $textLength;
        }

        // For medium texts, use default
        if ($textLength < 10000) {
            return config('rag.chunk_max_chars', 2200);
        }

        // For very long texts, increase chunk size slightly
        return min(3000, config('rag.chunk_max_chars', 2200) + 500);
    }

    /**
     * Standard semantic chunking with paragraph → sentence → hard split strategy
     *
     * @param  string  $text  Text to chunk
     * @param  int  $maxChars  Maximum characters per chunk
     * @param  int  $overlapChars  Overlap between chunks
     * @return array<int, array{text: string, type: string, position: int, metadata: array}> Array of chunks
     */
    private function performStandardChunking(string $text, int $maxChars, int $overlapChars): array
    {
        $chunks = [];
        $text = trim($text);

        if (mb_strlen($text) <= $maxChars) {
            return [[
                'text' => $text,
                'type' => 'standard',
                'position' => 0,
                'metadata' => ['char_count' => mb_strlen($text)],
            ]];
        }

        // Split by paragraphs first to preserve structure
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $currentChunk = '';
        $position = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }

            // If adding this paragraph exceeds limit
            if (mb_strlen($currentChunk."\n\n".$paragraph) > $maxChars && ! empty($currentChunk)) {
                // Save current chunk
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'type' => 'standard',
                    'position' => $position++,
                    'metadata' => ['char_count' => mb_strlen($currentChunk)],
                ];

                // Start new chunk with overlap
                $overlapText = $this->getLastWords($currentChunk, $overlapChars);
                $currentChunk = $overlapText;
            }

            // Add paragraph to current chunk
            if (! empty($currentChunk)) {
                $currentChunk .= "\n\n".$paragraph;
            } else {
                $currentChunk = $paragraph;
            }

            // If single paragraph is too long, chunk by sentences
            if (mb_strlen($currentChunk) > $maxChars) {
                $sentenceChunks = $this->chunkBySentences($currentChunk, $maxChars, $overlapChars);
                if (count($sentenceChunks) > 1) {
                    // Add all chunks except the last
                    for ($i = 0; $i < count($sentenceChunks) - 1; $i++) {
                        $chunks[] = $sentenceChunks[$i];
                    }
                    // Last becomes current chunk
                    $currentChunk = $sentenceChunks[count($sentenceChunks) - 1]['text'];
                }
            }
        }

        // Add last chunk if not empty
        if (! empty(trim($currentChunk))) {
            $chunks[] = [
                'text' => trim($currentChunk),
                'type' => 'standard',
                'position' => $position,
                'metadata' => ['char_count' => mb_strlen($currentChunk)],
            ];
        }

        // FAIL-SAFE: if no chunks generated, use emergency char chunking
        if (empty($chunks)) {
            Log::warning('chunking.fallback_to_char_chunking', [
                'text_length' => mb_strlen($text),
                'max_chars' => $maxChars,
            ]);

            return $this->performHardChunking($text, $maxChars, $overlapChars);
        }

        return $chunks;
    }

    /**
     * Chunk text by sentences (for long paragraphs)
     *
     * @param  string  $paragraph  Text to chunk
     * @param  int  $maxChars  Maximum characters per chunk
     * @param  int  $overlapChars  Overlap between chunks
     * @return array<int, array{text: string, type: string, position: int, metadata: array}> Array of chunks
     */
    private function chunkBySentences(string $paragraph, int $maxChars, int $overlapChars): array
    {
        // Split by sentences (. ! ? + space/newline)
        $sentences = preg_split('/([.!?]+)(\s+|$)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';
        $position = 0;

        for ($i = 0; $i < count($sentences); $i += 3) { // Each sentence has 3 parts: text, punctuation, space
            $sentence = '';
            if (isset($sentences[$i])) {
                $sentence .= $sentences[$i];
            }
            if (isset($sentences[$i + 1])) {
                $sentence .= $sentences[$i + 1];
            }
            if (isset($sentences[$i + 2])) {
                $sentence .= $sentences[$i + 2];
            }

            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            // If adding this sentence exceeds limit
            if (mb_strlen($currentChunk.' '.$sentence) > $maxChars && ! empty($currentChunk)) {
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'type' => 'sentence',
                    'position' => $position++,
                    'metadata' => ['char_count' => mb_strlen($currentChunk)],
                ];

                // Overlap with last words
                $overlapText = $this->getLastWords($currentChunk, $overlapChars);
                $currentChunk = $overlapText.' '.$sentence;
            } else {
                $currentChunk = empty($currentChunk) ? $sentence : $currentChunk.' '.$sentence;
            }
        }

        // Last chunk
        if (! empty(trim($currentChunk))) {
            $chunks[] = [
                'text' => trim($currentChunk),
                'type' => 'sentence',
                'position' => $position,
                'metadata' => ['char_count' => mb_strlen($currentChunk)],
            ];
        }

        // If still too long, use hard chunking
        if (empty($chunks) || max(array_map(fn ($c) => mb_strlen($c['text']), $chunks)) > $maxChars * 1.5) {
            return $this->performHardChunking($paragraph, $maxChars, $overlapChars);
        }

        return $chunks;
    }

    /**
     * Hard chunking by character count (emergency fallback)
     *
     * @param  string  $text  Text to chunk
     * @param  int  $maxChars  Maximum characters per chunk
     * @param  int  $overlapChars  Overlap between chunks
     * @return array<int, array{text: string, type: string, position: int, metadata: array}> Array of chunks
     */
    private function performHardChunking(string $text, int $maxChars, int $overlapChars): array
    {
        $chunks = [];
        $start = 0;
        $len = mb_strlen($text);
        $position = 0;

        while ($start < $len) {
            $end = min($len, $start + $maxChars);
            $slice = mb_substr($text, $start, $end - $start);

            $chunks[] = [
                'text' => $slice,
                'type' => 'hard',
                'position' => $position++,
                'metadata' => ['char_count' => mb_strlen($slice)],
            ];

            if ($end >= $len) {
                break;
            }

            $start = $end - $overlapChars;
            if ($start < 0) {
                $start = 0;
            }
        }

        return $chunks;
    }

    /**
     * Get last N characters preserving word boundaries
     *
     * @param  string  $text  Source text
     * @param  int  $maxChars  Maximum characters to extract
     * @return string Last words
     */
    private function getLastWords(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        // Take last N characters, then find last space to avoid breaking words
        $lastChars = mb_substr($text, -$maxChars);
        $firstSpace = mb_strpos($lastChars, ' ');

        if ($firstSpace !== false && $firstSpace > 0) {
            return mb_substr($lastChars, $firstSpace + 1);
        }

        return $lastChars; // If no spaces found, take everything
    }

    /**
     * Explode markdown table into row chunks with column labels
     *
     * @param  string  $tableMarkdown  Markdown table content
     * @return array<string> Array of row chunks (plain strings for backward compatibility)
     */
    private function explodeMarkdownTableIntoRowChunks(string $tableMarkdown): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $tableMarkdown))));

        Log::debug('table_explosion.start', [
            'total_lines' => count($lines),
        ]);

        if (count($lines) < 2) {
            return [];
        }

        // Find header and separator (---|---)
        $headerLine = null;
        $separatorIndex = -1;
        foreach ($lines as $i => $line) {
            if ($headerLine === null && preg_match('/\|/', $line)) {
                $headerLine = $line;

                continue;
            }
            if ($headerLine !== null && preg_match('/^\|?\s*:?-+.*\|.*-+.*\s*\|?\s*$/', $line)) {
                $separatorIndex = $i;
                break;
            }
        }

        if ($headerLine === null || $separatorIndex < 0) {
            Log::debug('table_explosion.invalid_table');

            return [];
        }

        $headers = array_map('trim', array_filter(array_map('trim', explode('|', trim($headerLine, '| ')))));
        if (empty($headers)) {
            return [];
        }

        $rows = array_slice($lines, $separatorIndex + 1);
        $chunks = [];

        foreach ($rows as $row) {
            if ($row === '' || ! str_contains($row, '|')) {
                continue;
            }

            $cols = array_map('trim', array_filter(array_map('trim', explode('|', trim($row, '| ')))));
            if (empty($cols)) {
                continue;
            }

            // Align column count
            $pairs = [];
            for ($i = 0; $i < min(count($headers), count($cols)); $i++) {
                $h = $headers[$i];
                $v = $cols[$i];
                if ($v !== '') {
                    $pairs[] = "$h: $v";
                }
            }

            if (! empty($pairs)) {
                $chunks[] = implode("\n", $pairs);
            }
        }

        Log::debug('table_explosion.completed', [
            'total_chunks_created' => count($chunks),
            'headers' => $headers,
        ]);

        return $chunks;
    }
}
