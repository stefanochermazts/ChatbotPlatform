<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\FallbackStrategyService;
use App\Exceptions\ChatException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase as BaseTestCase;

/**
 * Tests for FallbackStrategyService
 * 
 * @group fallback
 * @group chat
 * @group services
 */
class FallbackStrategyServiceTest extends BaseTestCase
{
    private FallbackStrategyService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock facades for unit testing
        Cache::spy();
        Log::spy();
        
        $this->service = new FallbackStrategyService();
    }
    
    public function test_handles_non_retryable_exception(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        // 422 Validation Error is not retryable
        $exception = ChatException::fromValidation('messages', 'Invalid format');
        
        $response = $this->service->handleFallback($request, $exception);
        
        $this->assertEquals(200, $response->status());
        
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('choices', $data);
        $this->assertArrayHasKey('message', $data['choices'][0]);
        $this->assertStringContainsString('Mi dispiace', $data['choices'][0]['message']['content']);
    }
    
    public function test_attempts_retry_for_timeout_exception(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        // 504 Timeout is retryable
        $exception = ChatException::fromTimeout('OpenAI', 5.0);
        
        $response = $this->service->handleFallback($request, $exception);
        
        // Should log retry attempts
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'fallback.retry_strategy');
            })
            ->atLeast()
            ->once();
        
        $this->assertEquals(200, $response->status());
    }
    
    public function test_attempts_retry_for_rate_limit_exception(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        // 429 Rate Limit is retryable
        $exception = ChatException::fromRateLimit(1, 60);
        
        $response = $this->service->handleFallback($request, $exception);
        
        // Should log retry attempts
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'fallback.retry_strategy');
            })
            ->atLeast()
            ->once();
        
        $this->assertEquals(200, $response->status());
    }
    
    public function test_attempts_retry_for_service_unavailable_exception(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        // 503 Service Unavailable is retryable
        $exception = ChatException::fromServiceUnavailable('Redis', 'Connection refused');
        
        $response = $this->service->handleFallback($request, $exception);
        
        // Should log retry attempts
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'fallback.retry_strategy');
            })
            ->atLeast()
            ->once();
        
        $this->assertEquals(200, $response->status());
    }
    
    public function test_returns_cached_response_on_cache_hit(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        $cachedResponse = [
            'id' => 'chatcmpl-cached-123',
            'choices' => [
                [
                    'message' => [
                        'content' => 'Cached response'
                    ]
                ]
            ]
        ];
        
        // Mock cache hit
        Cache::shouldReceive('get')
            ->once()
            ->andReturn($cachedResponse);
        
        $exception = ChatException::fromValidation('test', 'test');
        
        $response = $this->service->handleFallback($request, $exception);
        
        $this->assertEquals(200, $response->status());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Cached response', $data['choices'][0]['message']['content']);
        $this->assertTrue($data['x_cached'] ?? false);
        $this->assertEquals('fallback', $data['x_cache_strategy'] ?? '');
    }
    
    public function test_falls_back_to_generic_message_on_cache_miss(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        // Mock cache miss
        Cache::shouldReceive('get')
            ->once()
            ->andReturn(null);
        
        $exception = ChatException::fromValidation('test', 'test');
        
        $response = $this->service->handleFallback($request, $exception);
        
        $this->assertEquals(200, $response->status());
        
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Mi dispiace', $data['choices'][0]['message']['content']);
        $this->assertArrayHasKey('x_error', $data);
        $this->assertEquals('generic_message', $data['x_error']['fallback_strategy']);
    }
    
    public function test_generic_fallback_has_openai_compatible_structure(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        Cache::shouldReceive('get')->andReturn(null);
        
        $exception = ChatException::fromTimeout('OpenAI', 5.0);
        
        $response = $this->service->handleFallback($request, $exception);
        
        $data = json_decode($response->getContent(), true);
        
        // OpenAI Chat Completions structure
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('object', $data);
        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('model', $data);
        $this->assertArrayHasKey('choices', $data);
        $this->assertArrayHasKey('usage', $data);
        
        $this->assertEquals('chat.completion', $data['object']);
        $this->assertEquals('gpt-4o-mini', $data['model']);
        
        // Choices structure
        $this->assertIsArray($data['choices']);
        $this->assertCount(1, $data['choices']);
        $this->assertArrayHasKey('message', $data['choices'][0]);
        $this->assertEquals('assistant', $data['choices'][0]['message']['role']);
        $this->assertArrayHasKey('finish_reason', $data['choices'][0]);
        
        // Usage structure
        $this->assertIsArray($data['usage']);
        $this->assertArrayHasKey('prompt_tokens', $data['usage']);
        $this->assertArrayHasKey('completion_tokens', $data['usage']);
        $this->assertArrayHasKey('total_tokens', $data['usage']);
    }
    
    public function test_generic_fallback_includes_error_metadata(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        Cache::shouldReceive('get')->andReturn(null);
        
        $exception = ChatException::fromTimeout('OpenAI', 5.0);
        
        $response = $this->service->handleFallback($request, $exception);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('x_error', $data);
        $this->assertArrayHasKey('type', $data['x_error']);
        $this->assertArrayHasKey('correlation_id', $data['x_error']);
        $this->assertArrayHasKey('fallback_strategy', $data['x_error']);
        
        $this->assertEquals('timeout', $data['x_error']['type']);
        $this->assertEquals('generic_message', $data['x_error']['fallback_strategy']);
        $this->assertStringStartsWith('fallback-', $data['x_error']['correlation_id']);
    }
    
    public function test_cache_successful_response_stores_in_cache(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        $response = [
            'id' => 'chatcmpl-123',
            'choices' => [
                [
                    'message' => [
                        'content' => 'Success response'
                    ]
                ]
            ]
        ];
        
        Cache::shouldReceive('put')
            ->once()
            ->withArgs(function ($key, $value, $ttl) use ($response) {
                return str_contains($key, 'chat:cache:')
                    && $value === $response
                    && $ttl === 3600;
            })
            ->andReturn(true);
        
        $this->service->cacheSuccessfulResponse($request, $response);
        
        // Assertion handled by shouldReceive()->once()
        $this->assertTrue(true);
    }
    
    public function test_gracefully_handles_cache_storage_failure(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        $response = [
            'id' => 'chatcmpl-123',
            'choices' => []
        ];
        
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));
        
        // Should not throw exception (graceful degradation)
        $this->service->cacheSuccessfulResponse($request, $response);
        
        // Should log warning
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'fallback.cache_store_failed');
            })
            ->once();
        
        $this->assertTrue(true);
    }
    
    public function test_gracefully_handles_cache_lookup_failure(): void
    {
        $request = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'test query']
            ]
        ];
        
        Cache::shouldReceive('get')
            ->once()
            ->andThrow(new \Exception('Cache unavailable'));
        
        $exception = ChatException::fromValidation('test', 'test');
        
        // Should not throw exception (graceful degradation)
        $response = $this->service->handleFallback($request, $exception);
        
        // Should fall back to generic message
        $this->assertEquals(200, $response->status());
        
        // Should log warning
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return str_contains($message, 'fallback.cache_unavailable');
            })
            ->once();
    }
    
    public function test_builds_cache_key_from_last_user_message(): void
    {
        $request1 = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'query A']
            ]
        ];
        
        $request2 = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'system prompt'],
                ['role' => 'user', 'content' => 'query A']
            ]
        ];
        
        $response = ['test' => 'response'];
        
        $capturedKeys = [];
        
        Cache::shouldReceive('put')
            ->twice()
            ->withArgs(function ($key, $value, $ttl) use (&$capturedKeys, $response) {
                $capturedKeys[] = $key;
                return $value === $response && $ttl === 3600;
            })
            ->andReturn(true);
        
        $this->service->cacheSuccessfulResponse($request1, $response);
        $this->service->cacheSuccessfulResponse($request2, $response);
        
        // Same last user message → same cache key
        $this->assertEquals($capturedKeys[0], $capturedKeys[1]);
    }
    
    public function test_different_queries_get_different_cache_keys(): void
    {
        $request1 = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'query A']
            ]
        ];
        
        $request2 = [
            'tenant_id' => 1,
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => 'query B']
            ]
        ];
        
        $response = ['test' => 'response'];
        
        $capturedKeys = [];
        
        Cache::shouldReceive('put')
            ->twice()
            ->withArgs(function ($key, $value, $ttl) use (&$capturedKeys, $response) {
                $capturedKeys[] = $key;
                return $value === $response && $ttl === 3600;
            })
            ->andReturn(true);
        
        $this->service->cacheSuccessfulResponse($request1, $response);
        $this->service->cacheSuccessfulResponse($request2, $response);
        
        // Different queries → different cache keys
        $this->assertNotEquals($capturedKeys[0], $capturedKeys[1]);
    }
}

