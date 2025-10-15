<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Chat\ChatOrchestrationServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat Completions API Controller
 * 
 * OpenAI-compatible endpoint for chat completions with RAG.
 * Delegates orchestration to ChatOrchestrationService.
 * 
 * @package App\Http\Controllers\Api
 */
class ChatCompletionsController extends Controller
{
    public function __construct(
        private readonly ChatOrchestrationServiceInterface $orchestrator
    ) {}
    
    /**
     * Create chat completion (OpenAI-compatible)
     * 
     * POST /v1/chat/completions
     * 
     * @param Request $request
     * @return JsonResponse|StreamedResponse
     */
    public function create(Request $request): JsonResponse|StreamedResponse
    {
        // Extract tenant ID from middleware
        $tenantId = (int) $request->attributes->get('tenant_id');

        // Validate request (OpenAI Chat Completions format)
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:128'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'stream' => ['nullable', 'boolean'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
            'tools' => ['nullable', 'array'],
            'tool_choice' => ['nullable'],
            'response_format' => ['nullable', 'array'],
        ]);

        Log::info('chat.request_received', [
            'tenant_id' => $tenantId,
            'model' => $validated['model'],
            'stream' => $validated['stream'] ?? false,
            'messages_count' => count($validated['messages'])
        ]);
        
        // 🚫 Agent Console: Block bot if operator has taken control
        $sessionId = $request->header('X-Session-ID');
        if ($sessionId && $this->isOperatorActive($sessionId)) {
            return $this->buildOperatorActiveResponse($validated['model']);
        }
        
        // Prepare orchestration request
        $orchestrationRequest = array_merge($validated, [
            'tenant_id' => $tenantId,
        ]);
        
        // Delegate to orchestrator
        $result = $this->orchestrator->orchestrate($orchestrationRequest);
        
        // Handle streaming vs. sync response
        if ($result instanceof \Generator) {
            return $this->handleStreamingResponse($result);
        }
        
        // Sync response is already JsonResponse
        return $result;
    }
    
    /**
     * Check if operator has taken control of conversation
     * 
     * @param string $sessionId
     * @return bool
     */
    private function isOperatorActive(string $sessionId): bool
    {
            $session = ConversationSession::where('session_id', $sessionId)->first();
        
        if (!$session) {
            return false;
        }
        
        $isActive = $session->handoff_status === 'handoff_active';
        
        if ($isActive) {
            Log::info('chat.operator_active', [
                'session_id' => $sessionId,
                'handoff_status' => $session->handoff_status
            ]);
        }
        
        return $isActive;
    }
    
    /**
     * Build response when operator is active
     * 
     * @param string $model
     * @return JsonResponse
     */
    private function buildOperatorActiveResponse(string $model): JsonResponse
    {
        Log::info('chat.bot_blocked_operator_active');
        
                return response()->json([
            'id' => 'chatcmpl-operator-handoff-' . uniqid(),
                    'object' => 'chat.completion',
                    'created' => time(),
            'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => '🤝 **Operatore connesso**: Un operatore umano ha preso il controllo di questa conversazione. Il bot è temporaneamente disattivato.'
                            ],
                            'finish_reason' => 'stop'
                        ]
                    ],
                    'usage' => [
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'total_tokens' => 0
                    ]
                ], 200);
    }
    
    /**
     * Convert Generator to Server-Sent Events (SSE) stream
     * 
     * @param \Generator $generator
     * @return StreamedResponse
     */
    private function handleStreamingResponse(\Generator $generator): StreamedResponse
    {
        return response()->stream(function () use ($generator) {
            // Set SSE headers
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            
            try {
                foreach ($generator as $chunk) {
                    // Handle different chunk types
                    if (isset($chunk['type'])) {
                        switch ($chunk['type']) {
                            case 'headers':
                                // Headers already set above, skip
                                break;
                                
                            case 'chunk':
                                // Send SSE data chunk
                                echo "data: " . json_encode($chunk['data']) . "\n\n";
                    
                    // Flush output buffer
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                                break;
                
                            case 'done':
                // Send final [DONE] message
                echo "data: [DONE]\n\n";
                
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                                break;
                                
                            case 'error':
                                // Send error as SSE
                                echo "data: " . json_encode($chunk['data']) . "\n\n";
                                
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
                                flush();
                                break;
                        }
                    }
                }
                
            } catch (\Throwable $e) {
                // Log streaming error
                Log::error('chat.streaming_error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Send error as SSE
                echo "data: " . json_encode([
                    'error' => [
                        'message' => 'Streaming error: ' . $e->getMessage(),
                        'type' => 'stream_error'
                    ]
                ]) . "\n\n";
                
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
