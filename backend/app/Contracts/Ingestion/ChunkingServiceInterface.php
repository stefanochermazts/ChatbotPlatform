<?php

declare(strict_types=1);

namespace App\Contracts\Ingestion;

/**
 * Interface for semantic text chunking service
 * 
 * Responsible for splitting text into semantically meaningful chunks
 * suitable for RAG retrieval. Supports multiple chunking strategies.
 * 
 * @package App\Contracts\Ingestion
 */
interface ChunkingServiceInterface
{
    /**
     * Chunk text using semantic boundaries
     * 
     * Splits text respecting:
     * - Paragraph boundaries
     * - Sentence boundaries
     * - Maximum character limit
     * - Overlap for context continuity
     * 
     * @param string $text Clean text to chunk
     * @param array{max_chars?: int, overlap_chars?: int, strategy?: string} $options Chunking configuration
     * @return array<int, array{text: string, type: string, position: int, metadata: array<string, mixed>}> Array of chunks
     */
    public function chunk(string $text, array $options = []): array;

    /**
     * Chunk tables separately with table-aware logic
     * 
     * Tables are chunked differently to preserve structure and meaning.
     * Each table becomes one or more chunks depending on size.
     * 
     * @param array<int, array{content: string, rows: int, cols: int}> $tables Tables from TextParsingService
     * @return array<int, array{text: string, type: 'table', metadata: array<string, mixed>}> Array of table chunks
     */
    public function chunkTables(array $tables): array;

    /**
     * Extract directory-like entries from text
     * 
     * Detects and extracts structured listings like:
     * - Contact directories
     * - Phone/email lists
     * - Opening hours
     * 
     * @param string $text Text containing directory-like entries
     * @return array<int, array{text: string, type: 'directory_entry', metadata: array<string, mixed>}> Array of directory chunks
     */
    public function extractDirectoryEntries(string $text): array;

    /**
     * Calculate optimal chunk size for a given text
     * 
     * Analyzes text characteristics and returns recommended chunk size.
     * 
     * @param string $text Text to analyze
     * @return int Recommended chunk size in characters
     */
    public function calculateOptimalChunkSize(string $text): int;
}

