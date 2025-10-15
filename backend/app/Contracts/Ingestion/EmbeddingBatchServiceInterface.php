<?php

declare(strict_types=1);

namespace App\Contracts\Ingestion;

use App\Exceptions\EmbeddingException;

/**
 * Interface for batch embedding generation service
 * 
 * Responsible for generating vector embeddings from text chunks
 * using OpenAI API. Handles batching, rate limiting, and retries.
 * 
 * @package App\Contracts\Ingestion
 */
interface EmbeddingBatchServiceInterface
{
    /**
     * Generate embeddings for multiple chunks in optimized batches
     * 
     * Automatically splits chunks into optimal batches respecting:
     * - OpenAI API limits (2048 texts, ~8000 tokens per request)
     * - Rate limiting with exponential backoff
     * - Retry logic for transient failures
     * 
     * @param array<int, array{text: string, id?: int}> $chunks Array of text chunks
     * @return array<int, array{chunk_id: int, vector: array<int, float>, model: string}> Array of embeddings
     * @throws EmbeddingException If OpenAI API fails after retries
     */
    public function embedBatch(array $chunks): array;

    /**
     * Generate embedding for a single text
     * 
     * @param string $text Text to embed
     * @return array<int, float> Embedding vector (1536 dimensions for text-embedding-3-small)
     * @throws EmbeddingException If OpenAI API fails
     */
    public function embedSingle(string $text): array;

    /**
     * Execute operation with rate limit handling
     * 
     * Wraps an operation with retry logic for rate limit errors (429).
     * Uses exponential backoff: 1s, 2s, 4s, 8s, 16s.
     * 
     * @param callable $operation Operation to execute
     * @param int $maxRetries Maximum retry attempts (default: 3)
     * @return mixed Operation result
     * @throws EmbeddingException If max retries exceeded
     */
    public function withRateLimitHandling(callable $operation, int $maxRetries = 3): mixed;

    /**
     * Get embedding model name
     * 
     * @return string Model identifier (e.g., "text-embedding-3-small")
     */
    public function getModelName(): string;

    /**
     * Get embedding dimensions
     * 
     * @return int Vector dimensions (e.g., 1536)
     */
    public function getDimensions(): int;
}

