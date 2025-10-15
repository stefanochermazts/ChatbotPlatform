<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatProfilingServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Service for profiling and monitoring chat performance
 * 
 * Tracks metrics to Redis stream for real-time monitoring:
 * - Per-step latency (retrieval, LLM, context building, etc.)
 * - Token usage and costs
 * - Success/failure rates
 * - Alert on threshold exceeded
 * 
 * Gracefully degrades to file-based logging if Redis unavailable.
 * 
 * @package App\Services\Chat
 */
class ChatProfilingService implements ChatProfilingServiceInterface
{
    /**
     * Redis stream key for chat metrics
     */
    private const REDIS_STREAM_KEY = 'chat:profiling:metrics';
    
    /**
     * Performance threshold (milliseconds)
     * Alert if step duration exceeds this
     */
    private const PERFORMANCE_THRESHOLD_MS = 2500.0; // 2.5 seconds
    
    /**
     * OpenAI pricing per 1M tokens (USD)
     * 
     * @var array<string, array{input: float, output: float}>
     */
    private array $pricing = [
        'gpt-4o-mini' => [
            'input' => 0.150,   // $0.150 per 1M input tokens
            'output' => 0.600,  // $0.600 per 1M output tokens
        ],
        'gpt-4o' => [
            'input' => 2.50,    // $2.50 per 1M input tokens
            'output' => 10.00,  // $10.00 per 1M output tokens
        ],
        'gpt-4-turbo' => [
            'input' => 10.00,
            'output' => 30.00,
        ],
        'gpt-3.5-turbo' => [
            'input' => 0.50,
            'output' => 1.50,
        ],
    ];
    
    /**
     * {@inheritDoc}
     */
    public function profile(array $metrics): void
    {
        // Validate required fields
        if (!isset($metrics['step']) || !isset($metrics['duration_ms']) || !isset($metrics['correlation_id'])) {
            Log::warning('profiling.invalid_metrics', [
                'reason' => 'Missing required fields: step, duration_ms, correlation_id',
                'provided_keys' => array_keys($metrics)
            ]);
            return;
        }
        
        $step = (string) $metrics['step'];
        $durationMs = (float) $metrics['duration_ms'];
        $correlationId = (string) $metrics['correlation_id'];
        $success = (bool) ($metrics['success'] ?? true);
        
        // Enrich metrics with cost calculation if tokens provided
        if (isset($metrics['tokens_used']) && isset($metrics['model'])) {
            $cost = $this->calculateCost(
                (string) $metrics['model'],
                (int) ($metrics['prompt_tokens'] ?? 0),
                (int) ($metrics['completion_tokens'] ?? 0)
            );
            
            $metrics['cost_usd'] = $cost;
        }
        
        // Add timestamp
        $metrics['timestamp'] = now()->toIso8601String();
        
        // Check performance threshold
        if ($durationMs > self::PERFORMANCE_THRESHOLD_MS) {
            Log::warning('profiling.threshold_exceeded', [
                'step' => $step,
                'duration_ms' => $durationMs,
                'threshold_ms' => self::PERFORMANCE_THRESHOLD_MS,
                'correlation_id' => $correlationId,
                'tenant_id' => $metrics['tenant_id'] ?? null
            ]);
        }
        
        // Push to Redis stream for real-time monitoring
        $this->pushToRedisStream($metrics);
        
        // Always log to file as backup
        $this->logToFile($metrics, $success);
    }
    
    /**
     * Calculate cost based on token usage and model pricing
     * 
     * @param string $model Model name
     * @param int $promptTokens Input tokens
     * @param int $completionTokens Output tokens
     * @return float Cost in USD
     */
    private function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        // Normalize model name (remove version suffixes)
        $normalizedModel = $model;
        if (str_contains($model, 'gpt-4o-mini')) {
            $normalizedModel = 'gpt-4o-mini';
        } elseif (str_contains($model, 'gpt-4o')) {
            $normalizedModel = 'gpt-4o';
        } elseif (str_contains($model, 'gpt-4-turbo')) {
            $normalizedModel = 'gpt-4-turbo';
        } elseif (str_contains($model, 'gpt-3.5-turbo')) {
            $normalizedModel = 'gpt-3.5-turbo';
        }
        
        if (!isset($this->pricing[$normalizedModel])) {
            Log::warning('profiling.unknown_model_pricing', [
                'model' => $model,
                'normalized' => $normalizedModel
            ]);
            return 0.0; // Unknown model, can't calculate cost
        }
        
        $pricing = $this->pricing[$normalizedModel];
        
        // Cost = (input_tokens / 1M * input_price) + (output_tokens / 1M * output_price)
        $inputCost = ($promptTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output'];
        
        return round($inputCost + $outputCost, 6); // Round to 6 decimals
    }
    
    /**
     * Push metrics to Redis stream for real-time monitoring
     * 
     * @param array<string, mixed> $metrics
     * @return void
     */
    private function pushToRedisStream(array $metrics): void
    {
        try {
            // Convert array to flat key-value pairs for Redis stream
            $streamData = [];
            foreach ($metrics as $key => $value) {
                // Convert arrays/objects to JSON
                if (is_array($value) || is_object($value)) {
                    $streamData[$key] = json_encode($value);
                } else {
                    $streamData[$key] = (string) $value;
                }
            }
            
            // XADD chat:profiling:metrics * key1 val1 key2 val2 ...
            Redis::xadd(
                self::REDIS_STREAM_KEY,
                '*', // Auto-generate ID
                $streamData
            );
            
            Log::debug('profiling.pushed_to_redis', [
                'stream_key' => self::REDIS_STREAM_KEY,
                'step' => $metrics['step'] ?? 'unknown',
                'correlation_id' => $metrics['correlation_id'] ?? 'unknown'
            ]);
            
        } catch (Throwable $e) {
            // Graceful degradation: Redis unavailable is not critical
            Log::warning('profiling.redis_unavailable', [
                'exception' => $e->getMessage(),
                'step' => $metrics['step'] ?? 'unknown',
                'fallback' => 'file-based logging only'
            ]);
        }
    }
    
    /**
     * Log metrics to file (backup and persistent record)
     * 
     * @param array<string, mixed> $metrics
     * @param bool $success
     * @return void
     */
    private function logToFile(array $metrics, bool $success): void
    {
        $logLevel = $success ? 'info' : 'error';
        $logMessage = $success 
            ? 'profiling.step_completed' 
            : 'profiling.step_failed';
        
        Log::log($logLevel, $logMessage, [
            'step' => $metrics['step'],
            'duration_ms' => $metrics['duration_ms'],
            'correlation_id' => $metrics['correlation_id'],
            'tenant_id' => $metrics['tenant_id'] ?? null,
            'model' => $metrics['model'] ?? null,
            'tokens_used' => $metrics['tokens_used'] ?? null,
            'cost_usd' => $metrics['cost_usd'] ?? null,
            'success' => $success,
            'error' => $metrics['error'] ?? null,
            'timestamp' => $metrics['timestamp'] ?? now()->toIso8601String()
        ]);
    }
    
    /**
     * Get pricing for a specific model
     * 
     * @param string $model Model name
     * @return array{input: float, output: float}|null Pricing or null if unknown
     */
    public function getPricing(string $model): ?array
    {
        $normalizedModel = $model;
        if (str_contains($model, 'gpt-4o-mini')) {
            $normalizedModel = 'gpt-4o-mini';
        } elseif (str_contains($model, 'gpt-4o')) {
            $normalizedModel = 'gpt-4o';
        } elseif (str_contains($model, 'gpt-4-turbo')) {
            $normalizedModel = 'gpt-4-turbo';
        } elseif (str_contains($model, 'gpt-3.5-turbo')) {
            $normalizedModel = 'gpt-3.5-turbo';
        }
        
        return $this->pricing[$normalizedModel] ?? null;
    }
}

