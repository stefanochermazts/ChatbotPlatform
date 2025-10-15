<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\ChatException;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ChatException factory methods and serialization
 * 
 * @group exceptions
 * @group chat
 */
class ChatExceptionTest extends TestCase
{
    public function test_creates_exception_from_timeout(): void
    {
        $exception = ChatException::fromTimeout('OpenAI', 5.0);
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('OpenAI', $exception->getMessage());
        $this->assertStringContainsString('5', $exception->getMessage());
        $this->assertEquals(504, $exception->getStatusCode());
        $this->assertEquals('timeout', $exception->getErrorType());
        $this->assertArrayHasKey('service', $exception->getContext());
        $this->assertEquals('OpenAI', $exception->getContext()['service']);
    }
    
    public function test_creates_exception_from_invalid_response(): void
    {
        $exception = ChatException::fromInvalidResponse('Milvus', 'Empty vector returned');
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('Invalid response', $exception->getMessage());
        $this->assertEquals(502, $exception->getStatusCode());
        $this->assertEquals('invalid_response', $exception->getErrorType());
        $this->assertEquals('Empty vector returned', $exception->getContext()['reason']);
    }
    
    public function test_creates_exception_from_rate_limit(): void
    {
        $exception = ChatException::fromRateLimit(123, 60);
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('Rate limit', $exception->getMessage());
        $this->assertEquals(429, $exception->getStatusCode());
        $this->assertEquals('rate_limit_exceeded', $exception->getErrorType());
        $this->assertEquals(123, $exception->getContext()['tenant_id']);
        $this->assertEquals(60, $exception->getContext()['retry_after_seconds']);
    }
    
    public function test_creates_exception_from_no_results(): void
    {
        $exception = ChatException::fromNoResults('What are the opening hours?', 5);
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('No relevant information', $exception->getMessage());
        $this->assertEquals(404, $exception->getStatusCode());
        $this->assertEquals('no_results', $exception->getErrorType());
        $this->assertEquals(5, $exception->getContext()['tenant_id']);
    }
    
    public function test_creates_exception_from_validation_error(): void
    {
        $exception = ChatException::fromValidation('messages', 'Array is empty');
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('Validation failed', $exception->getMessage());
        $this->assertEquals(422, $exception->getStatusCode());
        $this->assertEquals('validation_error', $exception->getErrorType());
        $this->assertEquals('messages', $exception->getContext()['field']);
    }
    
    public function test_creates_exception_from_low_confidence(): void
    {
        $exception = ChatException::fromLowConfidence(0.45, 0.70);
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('Unable to provide a confident answer', $exception->getMessage());
        $this->assertEquals(200, $exception->getStatusCode());
        $this->assertEquals('low_confidence', $exception->getErrorType());
        $this->assertEquals(0.45, $exception->getContext()['confidence']);
        $this->assertEquals(0.70, $exception->getContext()['min_required']);
    }
    
    public function test_creates_exception_from_service_unavailable(): void
    {
        $exception = ChatException::fromServiceUnavailable('Redis', 'Connection refused');
        
        $this->assertInstanceOf(ChatException::class, $exception);
        $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
        $this->assertEquals(503, $exception->getStatusCode());
        $this->assertEquals('service_unavailable', $exception->getErrorType());
        $this->assertEquals('Redis', $exception->getContext()['service']);
    }
    
    public function test_serializes_to_array_without_stack_trace(): void
    {
        $exception = ChatException::fromTimeout('OpenAI', 5.0);
        $array = $exception->toArray();
        
        $this->assertArrayHasKey('error', $array);
        $this->assertArrayHasKey('status_code', $array);
        $this->assertArrayHasKey('message', $array['error']);
        $this->assertArrayHasKey('type', $array['error']);
        $this->assertArrayHasKey('code', $array['error']);
        $this->assertEquals(504, $array['status_code']);
        $this->assertEquals('timeout', $array['error']['type']);
        
        // Ensure no stack trace
        $this->assertArrayNotHasKey('trace', $array);
        $this->assertArrayNotHasKey('file', $array);
        $this->assertArrayNotHasKey('line', $array);
    }
    
    public function test_provides_full_details_for_logging(): void
    {
        $exception = ChatException::fromRateLimit(123, 60);
        $logArray = $exception->toLogArray();
        
        $this->assertArrayHasKey('message', $logArray);
        $this->assertArrayHasKey('type', $logArray);
        $this->assertArrayHasKey('status_code', $logArray);
        $this->assertArrayHasKey('context', $logArray);
        $this->assertArrayHasKey('file', $logArray);
        $this->assertArrayHasKey('line', $logArray);
        $this->assertArrayHasKey('tenant_id', $logArray['context']);
        $this->assertStringContainsString('ChatExceptionTest.php', $logArray['file']);
        $this->assertIsInt($logArray['line']);
    }
    
    public function test_chains_exceptions_correctly(): void
    {
        $previous = new Exception('Original error');
        $exception = new ChatException(
            message: 'Wrapped error',
            statusCode: 500,
            errorType: 'wrapper',
            context: [],
            code: 0,
            previous: $previous
        );
        
        $this->assertInstanceOf(Exception::class, $exception->getPrevious());
        $this->assertEquals('Original error', $exception->getPrevious()->getMessage());
    }
    
    public function test_truncates_long_queries_in_no_results_exception(): void
    {
        $longQuery = str_repeat('a', 200);
        $exception = ChatException::fromNoResults($longQuery, 1);
        
        $context = $exception->getContext();
        
        $this->assertLessThanOrEqual(100, strlen($context['query']));
    }
    
    public function test_has_correct_openai_compatible_error_format(): void
    {
        $exception = ChatException::fromValidation('model', 'Required field missing');
        $array = $exception->toArray();
        
        // OpenAI error format: { error: { message, type, code } }
        $this->assertArrayHasKey('message', $array['error']);
        $this->assertArrayHasKey('type', $array['error']);
        $this->assertArrayHasKey('code', $array['error']);
        $this->assertEquals($array['error']['type'], $array['error']['code']); // code === type
    }
}

