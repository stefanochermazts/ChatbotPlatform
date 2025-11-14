<?php

declare(strict_types=1);

namespace App\Contracts\Ingestion;

/**
 * Interface for text parsing and normalization service
 *
 * Responsible for cleaning, normalizing, and preprocessing extracted text.
 * Handles table detection, noise removal, and text normalization.
 */
interface TextParsingServiceInterface
{
    /**
     * Normalize and clean extracted text
     *
     * Performs:
     * - UTF-8 sanitization
     * - Whitespace normalization
     * - Special character cleanup
     * - Line break standardization
     *
     * @param  string  $text  Raw extracted text
     * @return string Cleaned and normalized text
     */
    public function normalize(string $text): string;

    /**
     * Find and extract tables from text
     *
     * Detects table-like structures in plain text using patterns
     * and returns metadata for each found table.
     *
     * @param  string  $text  Normalized text
     * @return array<int, array{content: string, start_pos: int, end_pos: int, rows: int, cols: int}> Array of table metadata
     */
    public function findTables(string $text): array;

    /**
     * Remove tables from text
     *
     * Removes previously identified table content from text,
     * leaving only non-tabular content.
     *
     * @param  string  $text  Original text
     * @param  array<int, array{start_pos: int, end_pos: int}>  $tables  Tables metadata from findTables()
     * @return string Text with tables removed
     */
    public function removeTables(string $text, array $tables): string;

    /**
     * Remove noise and artifacts from text
     *
     * Removes:
     * - Headers/footers patterns
     * - Page numbers
     * - Repeated whitespace
     * - OCR artifacts
     *
     * @param  string  $text  Text to clean
     * @return string Cleaned text
     */
    public function removeNoise(string $text): string;
}
