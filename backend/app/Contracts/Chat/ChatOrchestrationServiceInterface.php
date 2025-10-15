<?php

declare(strict_types=1);

namespace App\Contracts\Chat;

use Generator;
use Illuminate\Http\JsonResponse;

/**
 * Interface for chat orchestration service
 * 
 * Orchestrates the complete RAG (Retrieval Augmented Generation) pipeline
 * from query processing to LLM response generation. Handles both streaming
 * and synchronous response modes.
 * 
 * @package App\Contracts\Chat
 */
interface ChatOrchestrationServiceInterface
{
    /**
     * Orchestrate the complete RAG pipeline
     * 
     * This method coordinates:
     * - KB (Knowledge Base) selection and retrieval
     * - Intent detection
     * - Context building
     * - Conversation enhancement
     * - Citation scoring
     * - LLM generation (streaming or sync)
     * - Response formatting (OpenAI-compatible)
     * 
     * @param array{
     *     tenant_id: int,
     *     model: string,
     *     messages: array<int, array{role: string, content: string}>,
     *     stream?: bool,
     *     temperature?: float,
     *     tools?: array,
     *     tool_choice?: string|array,
     *     response_format?: array
     * } $request Request payload following OpenAI Chat Completions format
     * 
     * @return Generator<int, array{
     *     id: string,
     *     object: string,
     *     created: int,
     *     model: string,
     *     choices: array<int, array{
     *         index: int,
     *         delta?: array{role?: string, content?: string},
     *         message?: array{role: string, content: string},
     *         finish_reason: string|null
     *     }>,
     *     usage?: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     * }>|JsonResponse Generator for streaming chunks or JsonResponse for synchronous
     * 
     * @throws \App\Exceptions\ChatException When orchestration fails
     * 
     * @example Streaming mode
     * ```php
     * $request = ['tenant_id' => 1, 'model' => 'gpt-4o-mini', 'messages' => [...], 'stream' => true];
     * $generator = $orchestrator->orchestrate($request);
     * foreach ($generator as $chunk) {
     *     echo "data: " . json_encode($chunk) . "\n\n";
     * }
     * ```
     * 
     * @example Synchronous mode
     * ```php
     * $request = ['tenant_id' => 1, 'model' => 'gpt-4o-mini', 'messages' => [...], 'stream' => false];
     * $response = $orchestrator->orchestrate($request);
     * return response()->json($response);
     * ```
     */
    public function orchestrate(array $request): Generator|JsonResponse;
}
