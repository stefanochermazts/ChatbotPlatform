<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\EmbeddingBatchServiceInterface;
use App\Exceptions\EmbeddingException;
use App\Services\LLM\OpenAIEmbeddingsService;
use Illuminate\Support\Facades\Log;

/**
 * Service for batch embedding generation with rate limiting
 * 
 * Wraps OpenAIEmbeddingsService with additional features:
 * - Batch optimization
 * - Rate limit handling
 * - Retry logic
 * 
 * @package App\Services\Ingestion
 */
class EmbeddingBatchService implements EmbeddingBatchServiceInterface
{
    public function __construct(
        private readonly OpenAIEmbeddingsService $embeddingsService
    ) {}
    
    /**
     * {@inheritDoc}
     */
    public function embedBatch(array $chunks): array
    {
        if (empty($chunks)) {
            return [];
        }
        
        Log::debug('embedding_batch.start', [
            'chunks_count' => count($chunks),
            'model' => $this->getModelName()
        ]);
        
        try {
            // Extract text from chunks (support both array and string format)
            $texts = array_map(function ($chunk) {
                if (is_array($chunk)) {
                    return $chunk['text'] ?? $chunk['content'] ?? '';
                }
                return (string) $chunk;
            }, $chunks);
            
            // Filter empty texts
            $texts = array_filter($texts, fn($text) => trim($text) !== '');
            
            if (empty($texts)) {
                throw new EmbeddingException("No valid texts to embed");
            }
            
            // Use OpenAIEmbeddingsService with rate limiting
            $vectors = $this->withRateLimitHandling(function () use ($texts) {
                return $this->embeddingsService->embedTexts($texts);
            });
            
            // Map vectors back to chunks
            $result = [];
            foreach ($vectors as $index => $vector) {
                $result[] = [
                    'chunk_id' => $index,
                    'vector' => $vector,
                    'model' => $this->getModelName()
                ];
            }
            
            Log::debug('embedding_batch.success', [
                'vectors_count' => count($result),
                'dimensions' => count($vectors[0] ?? [])
            ]);
            
            return $result;
        } catch (\Throwable $e) {
            Log::error('embedding_batch.failed', [
                'chunks_count' => count($chunks),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new EmbeddingException(
                "Failed to generate embeddings: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function embedSingle(string $text): array
    {
        if (trim($text) === '') {
            throw new EmbeddingException("Cannot embed empty text");
        }
        
        $vectors = $this->embeddingsService->embedTexts([$text]);
        
        if (empty($vectors)) {
            throw new EmbeddingException("No vector returned from embedding service");
        }
        
        return $vectors[0];
    }
    
    /**
     * {@inheritDoc}
     */
    public function withRateLimitHandling(callable $operation, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                
                // Check if it's a rate limit error (429 or specific OpenAI error)
                $isRateLimit = str_contains($e->getMessage(), '429') ||
                               str_contains($e->getMessage(), 'rate limit') ||
                               str_contains($e->getMessage(), 'Rate limit');
                
                if ($isRateLimit && $attempt < $maxRetries) {
                    $delay = pow(2, $attempt - 1); // Exponential backoff: 1s, 2s, 4s
                    
                    Log::warning('embedding.rate_limit', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_seconds' => $delay,
                        'error' => $e->getMessage()
                    ]);
                    
                    sleep($delay);
                    continue;
                }
                
                // For non-rate-limit errors, throw immediately
                break;
            }
        }
        
        throw new EmbeddingException(
            "Operation failed after {$maxRetries} retries: " . $lastException?->getMessage(),
            0,
            $lastException
        );
    }
    
    /**
     * {@inheritDoc}
     */
    public function getModelName(): string
    {
        return config('openai.embedding_model', 'text-embedding-3-small');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getDimensions(): int
    {
        $model = $this->getModelName();
        
        // Model-specific dimensions
        return match ($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536
        };
    }
}

