<?php

declare(strict_types=1);

namespace App\Contracts\Chat;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Interface for fallback strategy service
 * 
 * Handles error scenarios and implements fallback strategies when the
 * primary RAG pipeline fails. Includes retry logic with exponential
 * backoff, cached response fallback, and generic error responses.
 * 
 * @package App\Contracts\Chat
 */
interface FallbackStrategyServiceInterface
{
    /**
     * Handle fallback when primary pipeline fails
     * 
     * Implements a multi-tier fallback strategy:
     * 1. Retry with exponential backoff (for transient failures)
     * 2. Cached response lookup (for repeated queries)
     * 3. Generic fallback message (last resort)
     * 
     * All fallback responses maintain OpenAI Chat Completions format
     * to ensure compatibility with clients.
     * 
     * Retry timing: baseDelay * (2 ^ attemptNumber)
     * Example: 200ms, 400ms, 800ms for 3 retries
     * 
     * @param array{
     *     tenant_id: int,
     *     model: string,
     *     messages: array<int, array{role: string, content: string}>,
     *     stream?: bool,
     *     temperature?: float
     * } $request Original request payload
     * 
     * @param Throwable $exception The exception that triggered the fallback
     * 
     * @return JsonResponse OpenAI-compatible error response with choices array
     * 
     * @example Timeout scenario
     * ```php
     * try {
     *     $response = $orchestrator->orchestrate($request);
     * } catch (OpenAITimeoutException $e) {
     *     return $fallback->handleFallback($request, $e);
     * }
     * // Returns: {
     * //   "id": "chatcmpl-fallback-...",
     * //   "choices": [{"message": {"content": "I'm sorry, I couldn't..."}}],
     * //   "error": {"type": "timeout", "message": "..."}
     * // }
     * ```
     * 
     * @example Empty results scenario
     * ```php
     * try {
     *     $response = $orchestrator->orchestrate($request);
     * } catch (NoResultsException $e) {
     *     return $fallback->handleFallback($request, $e);
     * }
     * // Returns cached response if available, or generic "I don't know" message
     * ```
     */
    public function handleFallback(array $request, Throwable $exception): JsonResponse;
}
