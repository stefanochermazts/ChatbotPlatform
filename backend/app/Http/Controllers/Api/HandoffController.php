<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Models\HandoffRequest;
use App\Models\User;
use App\Services\HandoffService;
use App\Services\OperatorRoutingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class HandoffController extends Controller
{
    public function __construct(
        private HandoffService $handoffService,
        private OperatorRoutingService $routingService
    ) {}

    /**
     * 🤝 Richiede handoff da bot a operatore
     */
    public function request(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string|exists:conversation_sessions,session_id',
                'trigger_type' => 'required|string|in:user_explicit,bot_escalation,intent_complex,sentiment_negative,timeout_frustration,manual_operator,system_rule',
                'reason' => 'nullable|string|max:500',
                'priority' => 'string|in:low,normal,high,urgent',
                'routing_criteria' => 'nullable|array',
                'context_data' => 'nullable|array'
            ]);

            // Find conversation session
            $session = ConversationSession::where('session_id', $validated['session_id'])->first();
            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            }

            // Check if handoff already exists and is pending
            $existingHandoff = HandoffRequest::where('conversation_session_id', $session->id)
                                           ->where('status', 'pending')
                                           ->first();
            if ($existingHandoff) {
                return response()->json([
                    'success' => true,
                    'handoff_request' => [
                        'id' => $existingHandoff->id,
                        'status' => $existingHandoff->status,
                        'message' => 'Handoff request already exists'
                    ]
                ]);
            }

            // Create handoff request via service
            $handoffRequest = $this->handoffService->requestHandoff(
                $session,
                $validated['trigger_type'],
                $validated['reason'] ?? 'User requested human assistance',
                $validated['context_data'] ?? [],
                $validated['priority'] ?? 'normal'
            );

            if ($handoffRequest) {
                // ✅ MANUAL ASSIGNMENT: Do NOT auto-assign, let operators take it manually via notification
                // The handoff will remain in "pending" status and operators will see it via polling/toast
                // $isAssigned = $this->routingService->autoAssignHandoff($handoffRequest);
                // $assignedOperator = $isAssigned ? $handoffRequest->fresh()->assignedOperator : null;
                
                \Log::info('handoff.created_pending', [
                    'handoff_id' => $handoffRequest->id,
                    'status' => $handoffRequest->status,
                    'tenant_id' => $handoffRequest->tenant_id,
                    'priority' => $handoffRequest->priority
                ]);
                
                return response()->json([
                    'success' => true,
                    'handoff_request' => [
                        'id' => $handoffRequest->id,
                        'status' => $handoffRequest->status,
                        'priority' => $handoffRequest->priority,
                        'requested_at' => $handoffRequest->requested_at->toISOString(),
                        'assigned_operator' => null  // ✅ Always null - operators must take manually
                    ]
                ], 201);
            } else {
                return response()->json(['error' => 'Failed to create handoff request'], 500);
            }

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('handoff.request.failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to process handoff request'], 500);
        }
    }

    /**
     * 👨‍💼 Assegna handoff a operatore specifico  
     */
    public function assign(Request $request, int $handoffId): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Handoff assign - to be implemented']);
    }

    /**
     * ✅ Risolve handoff
     */
    public function resolve(Request $request, int $handoffId): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Handoff resolve - to be implemented']);
    }

    /**
     * 🔼 Escalation handoff
     */
    public function escalate(Request $request, int $handoffId): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Handoff escalate - to be implemented']);
    }

    /**
     * 📋 Lista handoff pendenti
     */
    public function pending(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tenant_id' => 'required|integer|exists:tenants,id',
                'status' => 'string|in:pending,assigned,resolved,cancelled',
                'priority' => 'string|in:low,normal,high,urgent',
                'limit' => 'integer|min:1|max:100',
                'offset' => 'integer|min:0'
            ]);

            $query = HandoffRequest::with(['conversationSession', 'assignedOperator'])
                                  ->where('tenant_id', $validated['tenant_id']);

            // Apply filters
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            } else {
                $query->where('status', 'pending'); // Default to pending
            }

            if (isset($validated['priority'])) {
                $query->where('priority', $validated['priority']);
            }

            // Order by priority and age
            $query->orderByRaw("CASE 
                WHEN priority = 'urgent' THEN 1 
                WHEN priority = 'high' THEN 2 
                WHEN priority = 'normal' THEN 3 
                WHEN priority = 'low' THEN 4 
                END")
                  ->orderBy('requested_at', 'asc');

            $limit = $validated['limit'] ?? 20;
            $offset = $validated['offset'] ?? 0;

            $handoffs = $query->skip($offset)->take($limit)->get();
            $total = $query->count();

            return response()->json([
                'success' => true,
                'data' => $handoffs->map(function ($handoff) {
                    return [
                        'id' => $handoff->id,
                        'session_id' => $handoff->conversationSession->session_id,
                        'status' => $handoff->status,
                        'priority' => $handoff->priority,
                        'trigger_type' => $handoff->trigger_type,
                        'reason' => $handoff->reason,
                        'requested_at' => $handoff->requested_at->toISOString(),
                        'age_minutes' => $handoff->getAgeInMinutes(),
                        'assigned_operator' => $handoff->assignedOperator ? [
                            'id' => $handoff->assignedOperator->id,
                            'name' => $handoff->assignedOperator->name
                        ] : null,
                        'session_info' => [
                            'user_identifier' => $handoff->conversationSession->user_identifier,
                            'channel' => $handoff->conversationSession->channel,
                            'message_count' => $handoff->conversationSession->message_count_total ?? 0
                        ]
                    ];
                }),
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('handoff.pending.failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to retrieve pending handoffs'], 500);
        }
    }

    /**
     * 📊 Metriche handoff
     */
    public function metrics(Request $request): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Handoff metrics - to be implemented']);
    }
}
