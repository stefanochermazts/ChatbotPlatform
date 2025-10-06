<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LatencyMetrics
{
    /**
     * OpenAI pricing per 1M tokens (as of 2025)
     * Update these values when OpenAI changes pricing
     */
    private const PRICING = [
        'gpt-4o' => ['input' => 5.00, 'output' => 15.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.0],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.0],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Feature flag check
        if (!$this->isEnabled()) {
            return $next($request);
        }

        $startTime = microtime(true);
        $correlationId = $request->header('X-Request-ID') ?? (string) Str::uuid();
        
        // Store correlation ID in request for downstream use
        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);

        $endTime = microtime(true);
        $latencyMs = ($endTime - $startTime) * 1000;

        // Extract metrics
        $metrics = $this->extractMetrics($request, $response, $latencyMs, $correlationId);

        // Emit metrics to various backends
        $this->emitToRedis($metrics);
        $this->emitToLog($metrics);
        $this->emitToPrometheus($metrics);

        // Add latency header for debugging
        $response->headers->set('X-Latency-Ms', round($latencyMs, 2));
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    /**
     * Check if latency metrics are enabled via feature flag
     */
    private function isEnabled(): bool
    {
        // Check if spatie/laravel-feature-flags is available
        if (class_exists(\Spatie\LaravelFeatureFlags\Feature::class)) {
            return \Spatie\LaravelFeatureFlags\Feature::isEnabled('latency_metrics');
        }

        // Fallback to env variable
        return config('app.latency_metrics_enabled', false);
    }

    /**
     * Extract metrics from request/response
     */
    private function extractMetrics(Request $request, Response $response, float $latencyMs, string $correlationId): array
    {
        $tenantId = $this->extractTenantId($request);
        $endpoint = $request->path();
        $method = $request->method();
        $statusCode = $response->getStatusCode();
        $isError = $statusCode >= 400;

        // Extract token usage and cost (if available in response)
        $tokens = $this->extractTokenUsage($response);
        $cost = $this->calculateCost($tokens);

        return [
            'correlation_id' => $correlationId,
            'timestamp' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'endpoint' => $endpoint,
            'method' => $method,
            'status' => $statusCode,
            'latency_ms' => round($latencyMs, 2),
            'is_error' => $isError,
            'tokens' => $tokens,
            'cost_usd' => round($cost, 6),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ];
    }

    /**
     * Extract tenant_id from request
     */
    private function extractTenantId(Request $request): ?int
    {
        // Try from route parameter
        if ($request->route('tenant')) {
            $tenant = $request->route('tenant');
            return is_object($tenant) ? $tenant->id : (int) $tenant;
        }

        // Try from authenticated user
        if ($request->user()) {
            return $request->user()->tenant_id ?? null;
        }

        // Try from query parameter
        return $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;
    }

    /**
     * Extract token usage from response (if it's a chat completion)
     */
    private function extractTokenUsage(Response $response): array
    {
        $tokens = [
            'model' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        try {
            $content = $response->getContent();
            if (!$content) {
                return $tokens;
            }

            $data = json_decode($content, true);
            if (!$data || !isset($data['usage'])) {
                return $tokens;
            }

            $tokens['model'] = $data['model'] ?? null;
            $tokens['prompt_tokens'] = $data['usage']['prompt_tokens'] ?? 0;
            $tokens['completion_tokens'] = $data['usage']['completion_tokens'] ?? 0;
            $tokens['total_tokens'] = $data['usage']['total_tokens'] ?? 0;

        } catch (\Exception $e) {
            // Silently fail - not all responses have usage data
        }

        return $tokens;
    }

    /**
     * Calculate OpenAI cost based on model and token usage
     */
    private function calculateCost(array $tokens): float
    {
        if (!$tokens['model'] || $tokens['total_tokens'] === 0) {
            return 0.0;
        }

        $model = $tokens['model'];
        if (!isset(self::PRICING[$model])) {
            // Unknown model, can't calculate cost
            return 0.0;
        }

        $pricing = self::PRICING[$model];
        
        // Cost = (input_tokens * input_price + output_tokens * output_price) / 1M
        $inputCost = ($tokens['prompt_tokens'] * $pricing['input']) / 1_000_000;
        $outputCost = ($tokens['completion_tokens'] * $pricing['output']) / 1_000_000;

        return $inputCost + $outputCost;
    }

    /**
     * Emit metrics to Redis for short-term storage (UI dashboards)
     */
    private function emitToRedis(array $metrics): void
    {
        try {
            if (!$metrics['tenant_id']) {
                return;
            }

            $key = "latency:chat:{$metrics['tenant_id']}";
            
            // Store last 100 requests per tenant with 1 hour TTL
            Redis::lpush($key, json_encode($metrics));
            Redis::ltrim($key, 0, 99);
            Redis::expire($key, 3600);

            // Also store aggregate stats
            $statsKey = "latency:stats:{$metrics['tenant_id']}:today";
            Redis::hincrby($statsKey, 'total_requests', 1);
            Redis::hincrbyfloat($statsKey, 'total_latency_ms', $metrics['latency_ms']);
            Redis::hincrbyfloat($statsKey, 'total_cost_usd', $metrics['cost_usd']);
            
            if ($metrics['is_error']) {
                Redis::hincrby($statsKey, 'error_count', 1);
            }
            
            Redis::expire($statsKey, 86400); // 24 hours

        } catch (\Exception $e) {
            Log::warning('Failed to emit metrics to Redis', [
                'error' => $e->getMessage(),
                'correlation_id' => $metrics['correlation_id'],
            ]);
        }
    }

    /**
     * Emit structured JSON log
     */
    private function emitToLog(array $metrics): void
    {
        try {
            Log::channel('latency')->info('request_completed', $metrics);
        } catch (\Exception $e) {
            // Fallback to default log
            Log::info('request_completed', $metrics);
        }
    }

    /**
     * Emit to Prometheus (via StatsD or push gateway)
     */
    private function emitToPrometheus(array $metrics): void
    {
        // This is a placeholder for Prometheus integration
        // Options:
        // 1. Use promphp/prometheus_client_php with push gateway
        // 2. Use StatsD exporter (statsd_exporter)
        // 3. Use custom /metrics endpoint
        
        // For now, we'll implement a simple counter approach
        // The actual implementation will be completed in a follow-up
        
        try {
            // Example: increment request counter
            // Prometheus::counter('http_requests_total')
            //     ->labels(['tenant' => $metrics['tenant_id'], 'endpoint' => $metrics['endpoint'], 'status' => $metrics['status']])
            //     ->increment();
            
            // Example: observe latency histogram
            // Prometheus::histogram('http_request_duration_ms')
            //     ->labels(['tenant' => $metrics['tenant_id'], 'endpoint' => $metrics['endpoint']])
            //     ->observe($metrics['latency_ms']);
            
        } catch (\Exception $e) {
            // Silently fail - Prometheus integration is optional
        }
    }
}

