<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Domain-specific exception for chat-related errors
 * 
 * Provides factory methods for common error scenarios and ensures
 * proper HTTP status code mapping for API responses. Supports
 * JSON serialization without exposing stack traces.
 * 
 * @package App\Exceptions
 */
class ChatException extends Exception
{
    /**
     * HTTP status code for this exception
     */
    public int $statusCode;
    
    /**
     * Additional context data for debugging
     * 
     * @var array<string, mixed>
     */
    private array $context;
    
    /**
     * Error type identifier
     */
    private string $errorType;
    
    /**
     * Create a new ChatException instance
     * 
     * @param string $message Human-readable error message
     * @param int $statusCode HTTP status code (default: 500)
     * @param string $errorType Error type identifier (default: 'chat_error')
     * @param array<string, mixed> $context Additional context for debugging
     * @param int $code Internal error code (default: 0)
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        int $statusCode = 500,
        string $errorType = 'chat_error',
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->statusCode = $statusCode;
        $this->errorType = $errorType;
        $this->context = $context;
    }
    
    /**
     * Create exception for timeout scenarios
     * 
     * @param string $service Service that timed out (e.g., "OpenAI", "Milvus")
     * @param float $timeoutSeconds Timeout duration in seconds
     * @return self
     */
    public static function fromTimeout(string $service, float $timeoutSeconds): self
    {
        return new self(
            message: "Request to {$service} timed out after {$timeoutSeconds} seconds",
            statusCode: 504,
            errorType: 'timeout',
            context: [
                'service' => $service,
                'timeout_seconds' => $timeoutSeconds,
                'timestamp' => now()->toIso8601String()
            ]
        );
    }
    
    /**
     * Create exception for invalid response from external service
     * 
     * @param string $service Service name
     * @param string $reason Reason for invalidity
     * @return self
     */
    public static function fromInvalidResponse(string $service, string $reason): self
    {
        return new self(
            message: "Invalid response from {$service}: {$reason}",
            statusCode: 502,
            errorType: 'invalid_response',
            context: [
                'service' => $service,
                'reason' => $reason
            ]
        );
    }
    
    /**
     * Create exception for rate limit exceeded
     * 
     * @param int $tenantId Tenant ID
     * @param int $retryAfterSeconds Seconds to wait before retry
     * @return self
     */
    public static function fromRateLimit(int $tenantId, int $retryAfterSeconds): self
    {
        return new self(
            message: "Rate limit exceeded. Please retry after {$retryAfterSeconds} seconds.",
            statusCode: 429,
            errorType: 'rate_limit_exceeded',
            context: [
                'tenant_id' => $tenantId,
                'retry_after_seconds' => $retryAfterSeconds
            ]
        );
    }
    
    /**
     * Create exception for empty/no results from retrieval
     * 
     * @param string $query User query
     * @param int $tenantId Tenant ID
     * @return self
     */
    public static function fromNoResults(string $query, int $tenantId): self
    {
        return new self(
            message: "No relevant information found for your query.",
            statusCode: 404,
            errorType: 'no_results',
            context: [
                'query' => substr($query, 0, 100), // Truncate for privacy
                'tenant_id' => $tenantId
            ]
        );
    }
    
    /**
     * Create exception for validation errors
     * 
     * @param string $field Field that failed validation
     * @param string $reason Validation failure reason
     * @return self
     */
    public static function fromValidation(string $field, string $reason): self
    {
        return new self(
            message: "Validation failed for field '{$field}': {$reason}",
            statusCode: 422,
            errorType: 'validation_error',
            context: [
                'field' => $field,
                'reason' => $reason
            ]
        );
    }
    
    /**
     * Create exception for insufficient confidence in RAG results
     * 
     * @param float $confidence Actual confidence score
     * @param float $minRequired Minimum required confidence
     * @return self
     */
    public static function fromLowConfidence(float $confidence, float $minRequired): self
    {
        return new self(
            message: "Unable to provide a confident answer. Please rephrase your question or contact support.",
            statusCode: 200, // Still returns 200 but with error in body
            errorType: 'low_confidence',
            context: [
                'confidence' => $confidence,
                'min_required' => $minRequired
            ]
        );
    }
    
    /**
     * Create exception for service unavailable
     * 
     * @param string $service Service name
     * @param string $reason Reason for unavailability
     * @return self
     */
    public static function fromServiceUnavailable(string $service, string $reason): self
    {
        return new self(
            message: "Service {$service} is temporarily unavailable: {$reason}",
            statusCode: 503,
            errorType: 'service_unavailable',
            context: [
                'service' => $service,
                'reason' => $reason
            ]
        );
    }
    
    /**
     * Convert exception to array for JSON serialization
     * 
     * Returns OpenAI-compatible error format without exposing
     * sensitive information like stack traces or internal details.
     * 
     * @return array{error: array{message: string, type: string, code: string}, status_code: int}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'message' => $this->getMessage(),
                'type' => $this->errorType,
                'code' => $this->errorType, // OpenAI uses 'code' field
            ],
            'status_code' => $this->statusCode,
        ];
    }
    
    /**
     * Get HTTP status code
     * 
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * Get error type identifier
     * 
     * @return string
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }
    
    /**
     * Get context data
     * 
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
    
    /**
     * Get full error details for logging (includes context)
     * 
     * This method is for internal logging only and should NOT
     * be exposed to API responses.
     * 
     * @return array{message: string, type: string, status_code: int, context: array, file: string, line: int}
     */
    public function toLogArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'type' => $this->errorType,
            'status_code' => $this->statusCode,
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}

