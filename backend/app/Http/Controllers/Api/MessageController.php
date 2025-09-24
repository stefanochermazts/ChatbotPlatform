<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    /**
     * 📨 Invia un nuovo messaggio in una conversazione
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string|exists:conversation_sessions,session_id',
                'content' => 'required|string|max:4000',
                'content_type' => 'string|in:text,markdown,html',
                'sender_type' => 'required|string|in:user,operator,system',
                'sender_id' => 'nullable|integer|exists:users,id',
                'sender_name' => 'nullable|string|max:255',
                'parent_message_id' => 'nullable|integer|exists:conversation_messages,id',
                'metadata' => 'nullable|array'
            ]);

            // 🔍 Trova la sessione
            $session = ConversationSession::where('session_id', $validated['session_id'])->first();

            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            }

            if (!$session->isActive() && $session->status !== 'assigned') {
                return response()->json(['error' => 'Session is not active'], 400);
            }

            // 📝 Crea il messaggio
            $message = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'sender_type' => $validated['sender_type'],
                'sender_id' => $validated['sender_id'] ?? null,
                'sender_name' => $validated['sender_name'] ?? null,
                'content' => $validated['content'],
                'content_type' => $validated['content_type'] ?? 'text',
                'parent_message_id' => $validated['parent_message_id'] ?? null,
                'metadata' => $validated['metadata'] ?? [],
                'sent_at' => now(),
                'delivered_at' => now()
            ]);

            // 📊 Aggiorna contatori sessione
            $session->incrementMessageCount($validated['sender_type']);
            $session->updateActivity();

            return response()->json([
                'success' => true,
                'message' => [
                    'id' => $message->id,
                    'content' => $message->content,
                    'content_type' => $message->content_type,
                    'sender_type' => $message->sender_type,
                    'sender_name' => $message->getDisplayName(),
                    'sent_at' => $message->sent_at->toISOString(),
                    'delivered_at' => $message->delivered_at->toISOString()
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('message.send.failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    /**
     * 📥 Recupera messaggi di una conversazione
     */
    public function index(Request $request, string $sessionId): JsonResponse
    {
        \Log::info('message.index.called', [
            'session_id' => $sessionId,
            'headers' => $request->headers->all()
        ]);
        
        try {
            $validated = $request->validate([
                'limit' => 'integer|min:1|max:100',
                'offset' => 'integer|min:0',
                'since' => 'nullable|date'
            ]);

            $session = ConversationSession::where('session_id', $sessionId)->first();

            if (!$session) {
                return response()->json(['error' => 'Session not found'], 404);
            }

            $query = $session->messages()->orderBy('sent_at', 'asc');

            if (isset($validated['since'])) {
                $query->where('sent_at', '>=', $validated['since']);
            }

            $limit = $validated['limit'] ?? 50;
            $offset = $validated['offset'] ?? 0;

            $messages = $query->offset($offset)->limit($limit)->get();

            return response()->json([
                'success' => true,
                'messages' => $messages->map(function($message) {
                    return [
                        'id' => $message->id,
                        'content' => $message->content,
                        'content_type' => $message->content_type,
                        'sender_type' => $message->sender_type,
                        'sender_name' => $message->getDisplayName(),
                        'citations' => $message->citations,
                        'sent_at' => $message->sent_at->toISOString(),
                        'is_helpful' => $message->is_helpful,
                        'parent_message_id' => $message->parent_message_id
                    ];
                }),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => $session->message_count_total ?? 0
                ],
                // 🎯 Agent Console: Include conversation status for widget sync
                'conversation' => [
                    'status' => $session->status,
                    'handoff_status' => $session->handoff_status,
                    'assigned_operator_id' => $session->assigned_operator_id,
                    'last_activity_at' => $session->last_activity_at?->toISOString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve messages'], 500);
        }
    }

    /**
     * 👍 Marca un messaggio come utile/non utile
     */
    public function feedback(Request $request, int $messageId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'is_helpful' => 'required|boolean'
            ]);

            $message = ConversationMessage::find($messageId);

            if (!$message) {
                return response()->json(['error' => 'Message not found'], 404);
            }

            $message->markAsHelpful($validated['is_helpful']);

            return response()->json([
                'success' => true,
                'message' => 'Feedback recorded successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to record feedback'], 500);
        }
    }

    /**
     * ✏️ Modifica un messaggio (solo operatori)
     */
    public function edit(Request $request, int $messageId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'content' => 'required|string|max:4000',
                'edit_reason' => 'nullable|string|max:500'
            ]);

            $message = ConversationMessage::find($messageId);

            if (!$message) {
                return response()->json(['error' => 'Message not found'], 404);
            }

            if (!$message->isFromOperator() && !$message->isSystemMessage()) {
                return response()->json(['error' => 'Only operator and system messages can be edited'], 403);
            }

            $message->editContent($validated['content'], $validated['edit_reason']);

            return response()->json([
                'success' => true,
                'message' => [
                    'id' => $message->id,
                    'content' => $message->content,
                    'is_edited' => $message->is_edited,
                    'edited_at' => $message->edited_at->toISOString(),
                    'edit_reason' => $message->edit_reason
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to edit message'], 500);
        }
    }
}
