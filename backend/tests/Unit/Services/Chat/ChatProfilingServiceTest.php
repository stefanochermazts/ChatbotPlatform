<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\ChatProfilingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ChatProfilingService
 * 
 * @group profiling
 * @group chat
 * @group services
 */
class ChatProfilingServiceTest extends TestCase
{
    private ChatProfilingService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock facades
        Log::spy();
        Redis::spy();
        
        $this->service = new ChatProfilingService();
    }
    
    public function test_profiles_successful_step(): void
    {
        $metrics = [
            'step' => 'orchestration',
            'duration_ms' => 1250.5,
            'correlation_id' => 'req-abc123',
            'tenant_id' => 1,
            'success' => true
        ];
        
        $this->service->profile($metrics);
        
        // Should log to file
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info' 
                    && str_contains($message, 'profiling.step_completed')
                    && $context['step'] === 'orchestration';
            })
            ->once();
        
        // Should push to Redis
        Redis::shouldHaveReceived('xadd')
            ->once();
    }
    
    public function test_profiles_failed_step(): void
    {
        $metrics = [
            'step' => 'llm_generation',
            'duration_ms' => 5000.0,
            'correlation_id' => 'req-xyz789',
            'success' => false,
            'error' => 'OpenAI timeout'
        ];
        
        $this->service->profile($metrics);
        
        // Should log error
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error' 
                    && str_contains($message, 'profiling.step_failed')
                    && $context['error'] === 'OpenAI timeout';
            })
            ->once();
    }
    
    public function test_skips_invalid_metrics(): void
    {
        $invalidMetrics = [
            'step' => 'test',
            // Missing duration_ms and correlation_id
        ];
        
        $this->service->profile($invalidMetrics);
        
        // Should log warning
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'profiling.invalid_metrics');
            })
            ->once();
        
        // Should NOT push to Redis
        Redis::shouldNotHaveReceived('xadd');
    }
    
    public function test_calculates_cost_for_gpt4o_mini(): void
    {
        $metrics = [
            'step' => 'llm_generation',
            'duration_ms' => 2000.0,
            'correlation_id' => 'req-123',
            'model' => 'gpt-4o-mini',
            'tokens_used' => 1000,
            'prompt_tokens' => 800,
            'completion_tokens' => 200
        ];
        
        $this->service->profile($metrics);
        
        // GPT-4o-mini: $0.150/1M input, $0.600/1M output
        // Cost = (800/1M * 0.150) + (200/1M * 0.600)
        //      = 0.00012 + 0.00012 = 0.00024
        
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return isset($context['cost_usd'])
                    && abs($context['cost_usd'] - 0.00024) < 0.000001; // Float comparison with tolerance
            })
            ->once();
    }
    
    public function test_calculates_cost_for_gpt4o(): void
    {
        $metrics = [
            'step' => 'llm_generation',
            'duration_ms' => 2000.0,
            'correlation_id' => 'req-123',
            'model' => 'gpt-4o',
            'tokens_used' => 1000,
            'prompt_tokens' => 800,
            'completion_tokens' => 200
        ];
        
        $this->service->profile($metrics);
        
        // GPT-4o: $2.50/1M input, $10.00/1M output
        // Cost = (800/1M * 2.50) + (200/1M * 10.00)
        //      = 0.002 + 0.002 = 0.004
        
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return isset($context['cost_usd'])
                    && abs($context['cost_usd'] - 0.004) < 0.000001;
            })
            ->once();
    }
    
    public function test_alerts_on_threshold_exceeded(): void
    {
        $metrics = [
            'step' => 'slow_operation',
            'duration_ms' => 3000.0, // > 2500ms threshold
            'correlation_id' => 'req-slow',
            'tenant_id' => 1
        ];
        
        $this->service->profile($metrics);
        
        // Should log warning for threshold
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'profiling.threshold_exceeded')
                    && $context['duration_ms'] === 3000.0;
            })
            ->once();
    }
    
    public function test_does_not_alert_under_threshold(): void
    {
        $metrics = [
            'step' => 'fast_operation',
            'duration_ms' => 500.0, // < 2500ms threshold
            'correlation_id' => 'req-fast',
            'tenant_id' => 1
        ];
        
        $this->service->profile($metrics);
        
        // Should NOT log threshold warning
        Log::shouldNotHaveReceived('warning', function ($message) {
            return str_contains($message, 'profiling.threshold_exceeded');
        });
    }
    
    public function test_gracefully_handles_redis_unavailable(): void
    {
        Redis::shouldReceive('xadd')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));
        
        $metrics = [
            'step' => 'test',
            'duration_ms' => 100.0,
            'correlation_id' => 'req-123'
        ];
        
        // Should not throw exception
        $this->service->profile($metrics);
        
        // Should log warning
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'profiling.redis_unavailable');
            })
            ->once();
        
        // Should still log to file
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message) {
                return str_contains($message, 'profiling.step_completed');
            })
            ->once();
    }
    
    public function test_pushes_to_redis_stream(): void
    {
        $metrics = [
            'step' => 'retrieval',
            'duration_ms' => 234.5,
            'correlation_id' => 'req-abc',
            'tenant_id' => 5
        ];
        
        $this->service->profile($metrics);
        
        Redis::shouldHaveReceived('xadd')
            ->withArgs(function ($key, $id, $data) {
                return $key === 'chat:profiling:metrics'
                    && $id === '*' // Auto-generate ID
                    && is_array($data)
                    && isset($data['step'])
                    && $data['step'] === 'retrieval';
            })
            ->once();
    }
    
    public function test_converts_arrays_to_json_for_redis(): void
    {
        $metrics = [
            'step' => 'test',
            'duration_ms' => 100.0,
            'correlation_id' => 'req-123',
            'breakdown' => [
                'vector_search' => 50.0,
                'bm25_search' => 30.0,
                'fusion' => 20.0
            ]
        ];
        
        $this->service->profile($metrics);
        
        Redis::shouldHaveReceived('xadd')
            ->withArgs(function ($key, $id, $data) {
                return isset($data['breakdown'])
                    && is_string($data['breakdown'])
                    && str_contains($data['breakdown'], 'vector_search'); // JSON string
            })
            ->once();
    }
    
    public function test_adds_timestamp_to_metrics(): void
    {
        $metrics = [
            'step' => 'test',
            'duration_ms' => 100.0,
            'correlation_id' => 'req-123'
        ];
        
        $this->service->profile($metrics);
        
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return isset($context['timestamp'])
                    && is_string($context['timestamp']);
            })
            ->once();
    }
    
    public function test_get_pricing_returns_correct_prices(): void
    {
        $pricing = $this->service->getPricing('gpt-4o-mini');
        
        $this->assertNotNull($pricing);
        $this->assertArrayHasKey('input', $pricing);
        $this->assertArrayHasKey('output', $pricing);
        $this->assertEquals(0.150, $pricing['input']);
        $this->assertEquals(0.600, $pricing['output']);
    }
    
    public function test_get_pricing_handles_model_versions(): void
    {
        // Should normalize model versions
        $pricing1 = $this->service->getPricing('gpt-4o-mini-2024-07-18');
        $pricing2 = $this->service->getPricing('gpt-4o-mini');
        
        $this->assertEquals($pricing1, $pricing2);
    }
    
    public function test_get_pricing_returns_null_for_unknown_model(): void
    {
        $pricing = $this->service->getPricing('unknown-model-xyz');
        
        $this->assertNull($pricing);
    }
    
    public function test_handles_missing_tokens_gracefully(): void
    {
        $metrics = [
            'step' => 'llm_generation',
            'duration_ms' => 2000.0,
            'correlation_id' => 'req-123',
            'model' => 'gpt-4o-mini',
            // Missing tokens_used, prompt_tokens, completion_tokens
        ];
        
        // Should not throw exception
        $this->service->profile($metrics);
        
        Log::shouldHaveReceived('log')
            ->withArgs(function ($level, $message, $context) {
                return $context['step'] === 'llm_generation'
                    && !isset($context['cost_usd']); // Cost not calculated
            })
            ->once();
    }
}

