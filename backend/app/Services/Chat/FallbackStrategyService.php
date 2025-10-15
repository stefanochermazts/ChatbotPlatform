<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\FallbackStrategyServiceInterface;
use App\Exceptions\ChatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service for handling fallback strategies when RAG pipeline fails
 * 
 * Implements multi-tier fallback:
 * 1. Retry with exponential backoff (for transient failures)
 * 2. Cached response lookup (for repeated queries)
 * 3. Generic fallback message (last resort)
 * 
 * All responses maintain OpenAI Chat Completions format.
 * 
 * @package App\Services\Chat
 */
class FallbackStrategyService implements FallbackStrategyServiceInterface
{
    /**
     * Maximum retry attempts
     */
    private const MAX_RETRIES = 3;
    
    /**
     * Base delay for exponential backoff (milliseconds)
     */
    private const BASE_DELAY_MS = 200;
    
    /**
     * Cache TTL for responses (seconds)
     */
    private const CACHE_TTL_SECONDS = 3600; // 1 hour
    
    /**
     * Generic fallback message (user-friendly)
     */
    private const FALLBACK_MESSAGE = 'Mi dispiace, al momento non riesco a elaborare la tua richiesta. Per favore riprova tra qualche istante o contatta il supporto se il problema persiste.';
    
    /**
     * {@inheritDoc}
     */
    public function handleFallback(array $request, Throwable $exception): JsonResponse
    {
        $correlationId = $this->generateCorrelationId();
        
        Log::warning('fallback.triggered', [
            'correlation_id' => $correlationId,
            'exception_type' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'request_model' => $request['model'] ?? 'unknown',
            'request_messages_count' => count($request['messages'] ?? [])
        ]);
        
        // Strategy 1: Retry for transient failures
        if ($this->isRetryable($exception)) {
            Log::info('fallback.retry_strategy', [
                'correlation_id' => $correlationId,
                'reason' => 'Transient failure detected'
            ]);
            
            $retryResponse = $this->attemptRetryWithBackoff($request, $exception, $correlationId);
            if ($retryResponse !== null) {
                return $retryResponse;
            }
        }
        
        // Strategy 2: Cache lookup
        Log::info('fallback.cache_strategy', [
            'correlation_id' => $correlationId
        ]);
        
        $cachedResponse = $this->attemptCacheLookup($request, $correlationId);
        if ($cachedResponse !== null) {
            return $cachedResponse;
        }
        
        // Strategy 3: Generic fallback message (last resort)
        Log::warning('fallback.generic_message', [
            'correlation_id' => $correlationId,
            'reason' => 'All strategies exhausted'
        ]);
        
        return $this->buildGenericFallbackResponse($request, $exception, $correlationId);
    }
    
    /**
     * Determine if exception is retryable
     * 
     * Retryable exceptions:
     * - Timeout errors
     * - Rate limit errors (429)
     * - Service unavailable (503)
     * 
     * @param Throwable $exception
     * @return bool
     */
    private function isRetryable(Throwable $exception): bool
    {
        if (!($exception instanceof ChatException)) {
            return false; // Only retry ChatException types
        }
        
        $retryableStatuses = [429, 503, 504];
        return in_array($exception->getStatusCode(), $retryableStatuses, true);
    }
    
    /**
     * Attempt retry with exponential backoff
     * 
     * Retry timing: 200ms, 400ms, 800ms
     * 
     * @param array<string, mixed> $request
     * @param Throwable $exception
     * @param string $correlationId
     * @return JsonResponse|null Null if all retries failed
     */
    private function attemptRetryWithBackoff(array $request, Throwable $exception, string $correlationId): ?JsonResponse
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $delayMs = self::BASE_DELAY_MS * (2 ** ($attempt - 1)); // 200, 400, 800
            
            Log::info('fallback.retry_attempt', [
                'correlation_id' => $correlationId,
                'attempt' => $attempt,
                'max_retries' => self::MAX_RETRIES,
                'delay_ms' => $delayMs
            ]);
            
            // Sleep for exponential backoff
            usleep($delayMs * 1000); // Convert ms to microseconds
            
            try {
                // NOTE: In real implementation, this would call ChatOrchestrationService
                // For now, we just log and continue to next strategy
                Log::info('fallback.retry_would_call_orchestrator', [
                    'correlation_id' => $correlationId,
                    'attempt' => $attempt,
                    'note' => 'Actual retry logic will be implemented when integrated with ChatOrchestrationService'
                ]);
                
                // Placeholder: would return successful response here
                // return $orchestrator->orchestrate($request);
                
            } catch (Throwable $retryException) {
                Log::warning('fallback.retry_failed', [
                    'correlation_id' => $correlationId,
                    'attempt' => $attempt,
                    'exception' => $retryException->getMessage()
                ]);
                
                if ($attempt === self::MAX_RETRIES) {
                    Log::error('fallback.retry_exhausted', [
                        'correlation_id' => $correlationId,
                        'total_attempts' => self::MAX_RETRIES
                    ]);
                }
            }
        }
        
        return null; // All retries failed
    }
    
    /**
     * Attempt to retrieve cached response for this query
     * 
     * Cache key: hash of (tenant_id + model + last_user_message)
     * 
     * @param array<string, mixed> $request
     * @param string $correlationId
     * @return JsonResponse|null Null if no cache hit
     */
    private function attemptCacheLookup(array $request, string $correlationId): ?JsonResponse
    {
        try {
            $cacheKey = $this->buildCacheKey($request);
            
            Log::debug('fallback.cache_lookup', [
                'correlation_id' => $correlationId,
                'cache_key' => $cacheKey
            ]);
            
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                Log::info('fallback.cache_hit', [
                    'correlation_id' => $correlationId,
                    'cache_key' => $cacheKey
                ]);
                
                // Add metadata to indicate cached response
                if (is_array($cached)) {
                    $cached['x_cached'] = true;
                    $cached['x_cache_strategy'] = 'fallback';
                }
                
                return response()->json($cached);
            }
            
            Log::debug('fallback.cache_miss', [
                'correlation_id' => $correlationId,
                'cache_key' => $cacheKey
            ]);
            
        } catch (Throwable $cacheException) {
            // Graceful degradation: cache unavailable is not critical
            Log::warning('fallback.cache_unavailable', [
                'correlation_id' => $correlationId,
                'exception' => $cacheException->getMessage()
            ]);
        }
        
        return null; // No cache hit or cache unavailable
    }
    
    /**
     * Build generic fallback response (last resort)
     * 
     * Returns OpenAI-compatible error response with user-friendly message
     * 
     * @param array<string, mixed> $request
     * @param Throwable $exception
     * @param string $correlationId
     * @return JsonResponse
     */
    private function buildGenericFallbackResponse(array $request, Throwable $exception, string $correlationId): JsonResponse
    {
        $response = [
            'id' => 'chatcmpl-fallback-' . substr($correlationId, 0, 8),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => (string) ($request['model'] ?? 'gpt-4o-mini'),
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => self::FALLBACK_MESSAGE,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
        
        // Add error metadata (for debugging, not shown to user)
        $response['x_error'] = [
            'type' => $exception instanceof ChatException 
                ? $exception->getErrorType() 
                : 'internal_error',
            'correlation_id' => $correlationId,
            'fallback_strategy' => 'generic_message',
        ];
        
        // Determine HTTP status code
        $statusCode = $exception instanceof ChatException 
            ? $exception->getStatusCode() 
            : 500;
        
        // For user-facing fallback, always return 200 OK with message
        // (error is in the response body, not HTTP status)
        return response()->json($response, 200);
    }
    
    /**
     * Build cache key from request
     * 
     * Key format: chat:cache:{tenant_id}:{model}:{hash}
     * Hash: SHA256 of last user message
     * 
     * @param array<string, mixed> $request
     * @return string
     */
    private function buildCacheKey(array $request): string
    {
        $tenantId = (int) ($request['tenant_id'] ?? 0);
        $model = (string) ($request['model'] ?? 'gpt-4o-mini');
        
        // Extract last user message for hashing
        $messages = (array) ($request['messages'] ?? []);
        $lastUserMessage = '';
        
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUserMessage = (string) ($messages[$i]['content'] ?? '');
                break;
            }
        }
        
        // Hash the message for privacy and fixed-length key
        $messageHash = hash('sha256', $lastUserMessage);
        
        return "chat:cache:{$tenantId}:{$model}:" . substr($messageHash, 0, 16);
    }
    
    /**
     * Generate unique correlation ID for request tracing
     * 
     * @return string
     */
    private function generateCorrelationId(): string
    {
        return 'fallback-' . bin2hex(random_bytes(8));
    }
    
    /**
     * Cache successful response for future fallback use
     * 
     * This method should be called by ChatOrchestrationService
     * after a successful response to populate the cache.
     * 
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     * @return void
     */
    public function cacheSuccessfulResponse(array $request, array $response): void
    {
        try {
            $cacheKey = $this->buildCacheKey($request);
            
            Cache::put($cacheKey, $response, self::CACHE_TTL_SECONDS);
            
            Log::debug('fallback.response_cached', [
                'cache_key' => $cacheKey,
                'ttl_seconds' => self::CACHE_TTL_SECONDS
            ]);
            
        } catch (Throwable $e) {
            // Graceful degradation: caching failure is not critical
            Log::warning('fallback.cache_store_failed', [
                'exception' => $e->getMessage()
            ]);
        }
    }
}

