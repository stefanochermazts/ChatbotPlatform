<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ChatOrchestrationServiceInterface;
use App\Contracts\Chat\ContextScoringServiceInterface;
use App\Contracts\Chat\FallbackStrategyServiceInterface;
use App\Contracts\Chat\ChatProfilingServiceInterface;
use App\Exceptions\ChatException;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\ContextBuilder;
use App\Services\RAG\ConversationContextEnhancer;
use App\Services\RAG\CompleteQueryDetector;
use App\Services\RAG\LinkConsistencyService;
use App\Services\RAG\TenantRagConfigService;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Main orchestration service for RAG-powered chat
 * 
 * Coordinates the complete pipeline:
 * 1. Intent detection
 * 2. Conversation enhancement  
 * 3. KB retrieval
 * 4. Citation scoring
 * 5. Context building
 * 6. LLM generation (sync or streaming)
 * 7. Fallback on error
 * 8. Performance profiling
 * 
 * @package App\Services\Chat
 */
class ChatOrchestrationService implements ChatOrchestrationServiceInterface
{
    public function __construct(
        private readonly OpenAIChatService $openAI,
        private readonly KbSearchService $kbSearch,
        private readonly ContextBuilder $contextBuilder,
        private readonly ConversationContextEnhancer $conversationEnhancer,
        private readonly CompleteQueryDetector $completeDetector,
        private readonly LinkConsistencyService $linkConsistency,
        private readonly TenantRagConfigService $tenantConfig,
        private readonly ContextScoringServiceInterface $scorer,
        private readonly FallbackStrategyServiceInterface $fallback,
        private readonly ChatProfilingServiceInterface $profiler
    ) {}
    
    /**
     * {@inheritDoc}
     */
    public function orchestrate(array $request): Generator|JsonResponse
    {
        $startTime = microtime(true);
        $correlationId = $this->generateCorrelationId();
        $tenantId = (int) ($request['tenant_id'] ?? 0);
        $isStreaming = (bool) ($request['stream'] ?? false);
        
        Log::info('orchestration.start', [
            'correlation_id' => $correlationId,
            'tenant_id' => $tenantId,
            'stream' => $isStreaming,
            'model' => $request['model'] ?? 'unknown'
        ]);
        
        try {
            // Step 1: Extract query from messages
            $queryText = $this->extractUserQuery($request['messages']);
            
            // Step 2: Intent Detection
            $stepStart = microtime(true);
            $intentData = $this->completeDetector->detectCompleteIntent($queryText);
            $this->profiler->profile([
                'step' => 'intent_detection',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            // Step 3: Conversation Enhancement (if enabled)
            $stepStart = microtime(true);
            $finalQuery = $queryText;
            $conversationContext = null;
            
            if ($this->conversationEnhancer->isEnabled() && count($request['messages']) > 1) {
                $conversationContext = $this->conversationEnhancer->enhanceQuery(
                    $queryText,
                    $request['messages'],
                    $tenantId
                );
                
                if ($conversationContext['context_used']) {
                    $finalQuery = $conversationContext['enhanced_query'];
                }
            }
            
            $this->profiler->profile([
                'step' => 'conversation_enhancement',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            // Step 4: RAG Retrieval
            $stepStart = microtime(true);
            $retrieval = $intentData['is_complete_query']
                ? $this->kbSearch->retrieveComplete($tenantId, $finalQuery, $intentData, true)
                : $this->kbSearch->retrieve($tenantId, $finalQuery, true);
            
            $this->profiler->profile([
                'step' => 'rag_retrieval',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            $citations = $retrieval['citations'] ?? [];
            $confidence = (float) ($retrieval['confidence'] ?? 0.0);
            
            // Step 5: Citation Scoring (NEW!)
            $stepStart = microtime(true);
            if (!empty($citations)) {
                // Normalize citation format for scorer (expects 'content' field)
                $normalizedCitations = array_map(function ($citation) {
                    if (!isset($citation['content'])) {
                        // Map snippet/chunk_text to content for compatibility
                        $citation['content'] = $citation['snippet'] ?? $citation['chunk_text'] ?? '';
                    }
                    // Ensure document_id exists (scorer expects it)
                    if (!isset($citation['document_id']) && isset($citation['id'])) {
                        $citation['document_id'] = $citation['id'];
                    }
                    return $citation;
                }, $citations);
                
                $scoredCitations = $this->scorer->scoreCitations($normalizedCitations, [
                    'query' => $queryText,
                    'intent' => $intentData['intent'] ?? '',
                    'tenant_id' => $tenantId,
                    'min_confidence' => (float) config('rag.answer.min_confidence', 0.05)
                ]);
                
                // Replace citations with scored & sorted ones
                $citations = $scoredCitations;
            }
            
            $this->profiler->profile([
                'step' => 'citation_scoring',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            // Step 6: Link Filtering
            $stepStart = microtime(true);
            $filteredCitations = $this->linkConsistency->filterLinksInContext($citations, $queryText);
            
            $this->profiler->profile([
                'step' => 'link_filtering',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            // Step 7: Context Building
            $stepStart = microtime(true);
            $contextResult = $this->contextBuilder->build($filteredCitations);
            $contextText = is_array($contextResult) ? ($contextResult['context'] ?? '') : $contextResult;
            
            $this->profiler->profile([
                'step' => 'context_building',
                'duration_ms' => (microtime(true) - $stepStart) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            // Step 8: Prepare LLM Payload
            $payload = $this->buildLLMPayload($request, $queryText, $contextText, $tenantId);
            
            // Step 9: LLM Generation
            $stepStart = microtime(true);
            
            if ($isStreaming) {
                return $this->handleStreaming($payload, $citations, $confidence, $correlationId, $tenantId, $startTime, $stepStart);
            } else {
                $result = $this->openAI->chatCompletions($payload);
                
                $this->profiler->profile([
                    'step' => 'llm_generation',
                    'duration_ms' => (microtime(true) - $stepStart) * 1000,
                    'correlation_id' => $correlationId,
                    'tenant_id' => $tenantId,
                    'model' => $result['model'] ?? $payload['model'],
                    'tokens_used' => $result['usage']['total_tokens'] ?? 0,
                    'prompt_tokens' => $result['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $result['usage']['completion_tokens'] ?? 0,
                    'success' => true
                ]);
                
                // Apply fallback rules if needed
                $result = $this->applyFallbackRules($result, $citations, $confidence, $retrieval);
                
                // Attach metadata
                $result['citations'] = $citations;
                $result['retrieval'] = ['confidence' => $confidence];
                
                if ($conversationContext && $conversationContext['context_used']) {
                    $result['conversation_debug'] = [
                        'original_query' => $conversationContext['original_query'],
                        'enhanced_query' => $conversationContext['enhanced_query'],
                        'conversation_summary' => $conversationContext['conversation_summary'],
                        'processing_time_ms' => $conversationContext['processing_time_ms'],
                        'context_used' => true,
                    ];
                }
                
                // Profile total request
                $this->profiler->profile([
                    'step' => 'total_request',
                    'duration_ms' => (microtime(true) - $startTime) * 1000,
                    'correlation_id' => $correlationId,
                    'tenant_id' => $tenantId,
                    'success' => true
                ]);
                
                // Cache successful response for future fallback use
                $this->fallback->cacheSuccessfulResponse($request, $result);
                
                Log::info('orchestration.complete', [
                    'correlation_id' => $correlationId,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'citations_count' => count($citations)
                ]);
                
                return response()->json($result);
            }
            
        } catch (ChatException $e) {
            // Profile failed request
            $this->profiler->profile([
                'step' => 'orchestration',
                'duration_ms' => (microtime(true) - $startTime) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            Log::error('orchestration.failed', [
                'correlation_id' => $correlationId,
                'exception' => $e->getMessage(),
                'error_type' => $e->getErrorType()
            ]);
            
            // Delegate to fallback strategy
            return $this->fallback->handleFallback($request, $e);
            
        } catch (Throwable $e) {
            // Wrap unexpected exceptions in ChatException
            $chatException = new ChatException(
                message: 'Orchestration failed: ' . $e->getMessage(),
                statusCode: 500,
                errorType: 'orchestration_error',
                context: [
                    'correlation_id' => $correlationId,
                    'original_exception' => get_class($e)
                ]
            );
            
            $this->profiler->profile([
                'step' => 'orchestration',
                'duration_ms' => (microtime(true) - $startTime) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            return $this->fallback->handleFallback($request, $chatException);
        }
    }
    
    /**
     * Handle streaming response using PHP Generator
     * 
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $citations
     * @param float $confidence
     * @param string $correlationId
     * @param int $tenantId
     * @param float $requestStartTime
     * @param float $llmStartTime
     * @return Generator
     */
    private function handleStreaming(
        array $payload,
        array $citations,
        float $confidence,
        string $correlationId,
        int $tenantId,
        float $requestStartTime,
        float $llmStartTime
    ): Generator {
        // Yield SSE headers as first chunk
        yield [
            'headers' => [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no'
            ]
        ];
        
        $accumulated = '';
        $chunkCount = 0;
        
        try {
            // Call OpenAI streaming API
            $this->openAI->chatCompletionsStream($payload, function ($delta, $chunkData) use (&$accumulated, &$chunkCount) {
                $accumulated .= $delta;
                $chunkCount++;
                
                // Yield chunk data
                return [
                    'type' => 'chunk',
                    'data' => $chunkData
                ];
            });
            
            // Profile LLM generation
            $this->profiler->profile([
                'step' => 'llm_generation_stream',
                'duration_ms' => (microtime(true) - $llmStartTime) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'chunks_sent' => $chunkCount,
                'success' => true
            ]);
            
            // Yield final [DONE] message
            yield [
                'type' => 'done',
                'data' => '[DONE]'
            ];
            
            // Profile total request
            $this->profiler->profile([
                'step' => 'total_request_stream',
                'duration_ms' => (microtime(true) - $requestStartTime) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => true
            ]);
            
            Log::info('orchestration.streaming_complete', [
                'correlation_id' => $correlationId,
                'chunks_sent' => $chunkCount,
                'total_length' => strlen($accumulated),
                'duration_ms' => round((microtime(true) - $requestStartTime) * 1000, 2)
            ]);
            
        } catch (Throwable $e) {
            // Profile streaming error
            $this->profiler->profile([
                'step' => 'llm_generation_stream',
                'duration_ms' => (microtime(true) - $llmStartTime) * 1000,
                'correlation_id' => $correlationId,
                'tenant_id' => $tenantId,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            // Yield error as SSE
            yield [
                'type' => 'error',
                'data' => [
                    'error' => [
                        'message' => 'Streaming failed: ' . $e->getMessage(),
                        'type' => 'stream_error'
                    ]
                ]
            ];
        }
    }
    
    /**
     * Build LLM payload with system prompts and context
     * 
     * @param array<string, mixed> $request
     * @param string $queryText
     * @param string $contextText
     * @param int $tenantId
     * @return array<string, mixed>
     */
    private function buildLLMPayload(array $request, string $queryText, string $contextText, int $tenantId): array
    {
        $widgetConfig = $this->tenantConfig->getWidgetConfig($tenantId);
        
        $payload = [
            'model' => $request['model'] ?? ($widgetConfig['model'] ?? config('openai.chat_model', 'gpt-4o-mini')),
            'messages' => $request['messages'],
            'temperature' => (float) ($request['temperature'] ?? ($widgetConfig['temperature'] ?? 0.2)),
            'max_tokens' => (int) ($request['max_tokens'] ?? ($widgetConfig['max_tokens'] ?? 1000)),
        ];
        
        // Add system prompt
        $systemPrompt = config('rag.system_prompt', 'Sei un assistente. Rispondi in italiano.');
        array_unshift($payload['messages'], ['role' => 'system', 'content' => $systemPrompt]);
        
        // Append context to last user message
        for ($i = count($payload['messages']) - 1; $i >= 0; $i--) {
            if (($payload['messages'][$i]['role'] ?? '') === 'user') {
                $original = (string) ($payload['messages'][$i]['content'] ?? $queryText);
                $payload['messages'][$i]['content'] = 'Domanda: ' . $queryText . (
                    $contextText !== '' ? "\n" . $contextText : ''
                );
                break;
            }
        }
        
        return $payload;
    }
    
    /**
     * Apply fallback rules based on citation count and confidence
     * 
     * @param array<string, mixed> $result
     * @param array<int, array<string, mixed>> $citations
     * @param float $confidence
     * @param array<string, mixed> $retrieval
     * @return array<string, mixed>
     */
    private function applyFallbackRules(array $result, array $citations, float $confidence, array $retrieval): array
    {
        // Check fallback conditions
        $minCit = (int) config('rag.answer.min_citations', 1);
        $minConf = (float) config('rag.answer.min_confidence', 0.05);
        $forceIfHas = (bool) config('rag.answer.force_if_has_citations', true);
        
        if ((count($citations) < $minCit || $confidence < $minConf) && !($forceIfHas && count($citations) > 0)) {
            $fallback = (string) config('rag.answer.fallback_message', 'Non ho trovato informazioni sufficienti per rispondere.');
            $result['choices'][0]['message']['content'] = $fallback;
        }
        
        // Preserve intent-specific response if available
        if (!empty($retrieval['response_text'])) {
            $expansionText = (string) $retrieval['response_text'];
            $finalContent = (string) ($result['choices'][0]['message']['content'] ?? '');
            
            // Check if LLM response is missing expected info
            $expectsAddress = str_contains($expansionText, '📍') || str_contains($expansionText, 'Indirizzo');
            $expectsPhone   = str_contains($expansionText, '📞') || str_contains($expansionText, 'Telefono');
            $expectsEmail   = str_contains($expansionText, '📧') || str_contains($expansionText, 'Email');
            $expectsHours   = str_contains($expansionText, '🕒') || str_contains($expansionText, 'Orari');
            
            $hasAddress = str_contains($finalContent, '📍') || str_contains($finalContent, 'Indirizzo');
            $hasPhone   = str_contains($finalContent, '📞') || str_contains($finalContent, 'Telefono');
            $hasEmail   = str_contains($finalContent, '📧') || str_contains($finalContent, 'Email');
            $hasHours   = str_contains($finalContent, '🕒') || str_contains($finalContent, 'Orari');
            
            $isScheduleFocus = $expectsHours && !$expectsAddress && !$expectsPhone && !$expectsEmail;
            $missing = ($expectsAddress && !$hasAddress) || ($expectsPhone && !$hasPhone) || ($expectsEmail && !$hasEmail) || ($expectsHours && !$hasHours);
            
            if ($missing && !$isScheduleFocus) {
                $result['choices'][0]['message']['content'] = $expansionText;
            }
        }
        
        return $result;
    }
    
    /**
     * Extract user query from messages array
     * 
     * @param array<int, array<string, mixed>> $messages
     * @return string
     */
    private function extractUserQuery(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string) ($messages[$i]['content'] ?? '');
            }
        }
        
        return '';
    }
    
    /**
     * Generate unique correlation ID for request tracing
     * 
     * @return string
     */
    private function generateCorrelationId(): string
    {
        return 'orch-' . bin2hex(random_bytes(8));
    }
}

