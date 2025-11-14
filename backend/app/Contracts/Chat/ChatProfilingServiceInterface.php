<?php

declare(strict_types=1);

namespace App\Contracts\Chat;

/**
 * Interface for chat profiling service
 *
 * Tracks and logs performance metrics for the RAG pipeline.
 * Captures latency per step, token usage, costs, and quality metrics.
 * Enables observability and performance monitoring.
 */
interface ChatProfilingServiceInterface
{
    /**
     * Profile and log performance metrics
     *
     * Records metrics to Redis stream for real-time monitoring and
     * analysis. Increments per-step counters and triggers alerts
     * when thresholds are exceeded.
     *
     * Metrics are logged in structured JSON format with correlation_id
     * for request tracing across services.
     *
     * @param array{
     *     step: string,
     *     duration_ms: float,
     *     tokens_used?: int,
     *     cost_usd?: float,
     *     correlation_id: string,
     *     tenant_id?: int,
     *     model?: string,
     *     success?: bool,
     *     error?: string
     * } $metrics Performance metrics for a specific pipeline step
     *
     * @example Profiling orchestration step
     * ```php
     * $profiler->profile([
     *     'step' => 'orchestration',
     *     'duration_ms' => 1250.5,
     *     'tokens_used' => 850,
     *     'cost_usd' => 0.0042,
     *     'correlation_id' => 'req-abc123',
     *     'tenant_id' => 1,
     *     'success' => true
     * ]);
     * ```
     * @example Profiling with error
     * ```php
     * $profiler->profile([
     *     'step' => 'llm_generation',
     *     'duration_ms' => 5000.0,
     *     'correlation_id' => 'req-xyz789',
     *     'success' => false,
     *     'error' => 'OpenAI timeout after 5s'
     * ]);
     * // Logs warning if duration exceeds threshold
     * ```
     *
     * @note If Redis is unavailable, the service should gracefully degrade
     *       to file-based logging without breaking the request flow.
     */
    public function profile(array $metrics): void;
}
