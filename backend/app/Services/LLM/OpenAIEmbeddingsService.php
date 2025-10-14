<?php

namespace App\Services\LLM;

use GuzzleHttp\Client;

class OpenAIEmbeddingsService
{
    private Client $http;

    // OpenAI API Limits (as of 2024)
    private const MAX_BATCH_SIZE = 2048; // Max texts per request
    private const MAX_TOKENS_PER_REQUEST = 8000; // Safety margin below 8,191
    private const AVG_CHARS_PER_TOKEN = 4; // Approximation for token estimation

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('openai.base_url', 'https://api.openai.com'),
            'timeout' => 60, // ⚡ Increased from 30s for large batches
        ]);
    }

    public function embedTexts(array $texts, ?string $model = null): array
    {
        $apiKey = (string) config('openai.api_key');
        $model = $model ?: (string) config('rag.embedding_model');

        // Sanitize: assicurati che siano stringhe non vuote e UTF-8
        $clean = [];
        foreach ($texts as $t) {
            if (!is_string($t)) { $t = (string) $t; }
            $t = trim($t);
            if ($t === '') { continue; }
            // Converte in UTF-8 eliminando byte non validi
            $t = @mb_convert_encoding($t, 'UTF-8', 'UTF-8');
            if ($t === '' || $t === false) { continue; }
            $clean[] = $t;
        }
        if ($clean === []) {
            return [];
        }

        // ⚡ OPTIMIZED: Dynamic batching respecting OpenAI limits
        $batches = $this->createOptimalBatches($clean);
        
        \Log::info('embeddings.batch_info', [
            'total_texts' => count($clean),
            'num_batches' => count($batches),
            'batch_sizes' => array_map('count', $batches),
            'avg_batch_size' => count($clean) / max(count($batches), 1),
        ]);
        
        $all = [];
        foreach ($batches as $batchIndex => $batch) {
            try {
                $embeds = $this->embedBatchWithRetry($batch, $model, $apiKey, $batchIndex);
                $all = array_merge($all, $embeds);
            } catch (\Throwable $e) {
                \Log::error('embeddings.batch_failed', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
        
        return $all;
    }

    /**
     * ⚡ Create optimal batches respecting OpenAI limits
     * 
     * Strategy:
     * - Max 2048 texts per batch (OpenAI hard limit)
     * - Max ~8000 tokens per batch (safety margin)
     * - Estimate tokens as chars/4
     * 
     * @param array<int, string> $texts
     * @return array<int, array<int, string>> Batches of texts
     */
    private function createOptimalBatches(array $texts): array
    {
        $batches = [];
        $currentBatch = [];
        $currentTokens = 0;
        
        foreach ($texts as $text) {
            $estimatedTokens = $this->estimateTokens($text);
            
            // Check if adding this text would exceed limits
            $wouldExceedSize = count($currentBatch) >= self::MAX_BATCH_SIZE;
            $wouldExceedTokens = ($currentTokens + $estimatedTokens) > self::MAX_TOKENS_PER_REQUEST;
            
            if ($wouldExceedSize || $wouldExceedTokens) {
                // Save current batch and start new one
                if (!empty($currentBatch)) {
                    $batches[] = $currentBatch;
                }
                $currentBatch = [$text];
                $currentTokens = $estimatedTokens;
            } else {
                // Add to current batch
                $currentBatch[] = $text;
                $currentTokens += $estimatedTokens;
            }
        }
        
        // Don't forget last batch
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }
        
        return $batches;
    }

    /**
     * Estimate token count for text (rough approximation: 1 token ≈ 4 chars)
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::AVG_CHARS_PER_TOKEN);
    }

    /**
     * ⚡ Embed single batch with retry logic and exponential backoff
     * 
     * @param array<int, string> $batch
     * @param string $model
     * @param string $apiKey
     * @param int $batchIndex For logging
     * @return array<int, array<int, float>> Embeddings
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function embedBatchWithRetry(
        array $batch,
        string $model,
        string $apiKey,
        int $batchIndex
    ): array {
        $maxRetries = 3;
        $baseBackoffMs = 1000; // 1 second
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $startTime = microtime(true);
                
                $response = $this->http->post('/v1/embeddings', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'input' => array_values($batch),
                    ],
                ]);
                
                $duration = (microtime(true) - $startTime) * 1000; // ms
                
                $data = json_decode((string) $response->getBody(), true);
                $embeds = array_map(fn ($d) => $d['embedding'] ?? [], $data['data'] ?? []);
                
                \Log::info('embeddings.batch_success', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'duration_ms' => round($duration, 2),
                    'attempt' => $attempt,
                ]);
                
                return $embeds;
                
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                $isRateLimit = $statusCode === 429;
                $isServerError = $statusCode >= 500;
                
                // Don't retry on client errors (except rate limit)
                if ($statusCode >= 400 && $statusCode < 500 && !$isRateLimit) {
                    \Log::error('embeddings.client_error', [
                        'batch_index' => $batchIndex,
                        'status_code' => $statusCode,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                
                // Last attempt - throw error
                if ($attempt === $maxRetries) {
                    \Log::error('embeddings.max_retries_exceeded', [
                        'batch_index' => $batchIndex,
                        'batch_size' => count($batch),
                        'attempts' => $maxRetries,
                        'last_error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                
                // Calculate exponential backoff
                $backoffMs = $baseBackoffMs * (2 ** ($attempt - 1)); // 1s, 2s, 4s
                
                // Check for Retry-After header (rate limit)
                if ($isRateLimit && $e->hasResponse()) {
                    $retryAfter = $e->getResponse()->getHeader('Retry-After')[0] ?? null;
                    if ($retryAfter) {
                        $backoffMs = ((int) $retryAfter) * 1000;
                    }
                }
                
                \Log::warning('embeddings.retry', [
                    'batch_index' => $batchIndex,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'backoff_ms' => $backoffMs,
                    'status_code' => $statusCode,
                    'is_rate_limit' => $isRateLimit,
                    'is_server_error' => $isServerError,
                ]);
                
                // Wait before retry
                usleep($backoffMs * 1000); // Convert ms to microseconds
            }
        }
        
        // Should never reach here due to throw in loop, but TypeScript-style safety
        return [];
    }
}





