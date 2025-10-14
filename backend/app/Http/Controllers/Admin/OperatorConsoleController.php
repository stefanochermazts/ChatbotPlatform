<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Models\ConversationMessage;
use App\Models\HandoffRequest;
use App\Models\User;
use App\Services\HandoffService;
use App\Services\OperatorRoutingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperatorConsoleController extends Controller
{
    public function __construct(
        private HandoffService $handoffService,
        private OperatorRoutingService $routingService
    ) {}

    /**
     * 🏠 Dashboard principale Operator Console
     */
    public function index(Request $request)
    {
        // 🎯 Tenant scoping for multi-tenant environment
        $tenantId = $request->attributes->get('tenant_id') ?? 5; // Default to San Cesareo dev
        
        $stats = [
            'pending_handoffs' => HandoffRequest::whereHas('conversationSession', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })->where('status', 'pending')->count(),
            'active_conversations' => ConversationSession::where('tenant_id', $tenantId)
                                       ->whereIn('status', ['active', 'assigned'])->count(),
            'available_operators' => User::where('is_operator', true)
                                       ->where('operator_status', 'available')
                                       ->count(),
            'total_conversations_today' => ConversationSession::where('tenant_id', $tenantId)
                                           ->whereDate('started_at', today())->count()
        ];

        return view('admin.operator-console.index', [
            'stats' => $stats,
            'title' => 'Operator Console - Dashboard'
        ]);
    }

    /**
     * 🔔 Polling nuove richieste handoff (JSON)
     */
    public function pollNewHandoffs(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user || !$user->isOperator()) {
            return response()->json(['new' => false], 403);
        }

        // Facoltativo: evitare duplicati lato client
        $sinceId = $request->integer('since_id');

        // Scoping: solo tenant accessibili all'utente
        $tenantIds = $user->tenants()->pluck('tenants.id');

        $handoff = HandoffRequest::with(['tenant', 'conversationSession'])
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'pending')
            ->whereNull('assigned_operator_id')
            ->when($sinceId, fn($q) => $q->where('id', '>', $sinceId))
            ->orderByDesc('requested_at')
            ->first();

        if (!$handoff) {
            return response()->json(['new' => false]);
        }

        return response()->json([
            'new' => true,
            'handoff' => [
                'id' => $handoff->id,
                'priority' => $handoff->priority,
                'reason' => $handoff->reason,
                'requested_at' => optional($handoff->requested_at)->toISOString(),
                'tenant' => [
                    'id' => $handoff->tenant?->id,
                    'name' => $handoff->tenant?->name,
                ],
                'session' => [
                    'id' => $handoff->conversationSession?->id,
                    'session_id' => $handoff->conversationSession?->session_id,
                ],
            ]
        ]);
    }

    /**
     * 💬 Lista conversazioni attive
     */
    public function conversations(Request $request)
    {
        $query = ConversationSession::with(['messages' => function($q) {
            $q->latest('sent_at')->limit(1);
        }, 'assignedOperator', 'tenant'])
        ->whereIn('status', ['active', 'assigned', 'pending']);

        // Filtri
        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_operator_id', $request->assigned_to);
        }

        $conversations = $query->orderBy('last_activity_at', 'desc')
                              ->paginate(20);

        return view('admin.operator-console.conversations', [
            'conversations' => $conversations,
            'title' => 'Conversazioni Attive'
        ]);
    }

    /**
     * 💬 Dettaglio singola conversazione
     */
    public function showConversation(Request $request, ConversationSession $session)
    {
        $session->load(['messages' => function($q) {
            $q->orderBy('sent_at', 'asc');
        }, 'assignedOperator', 'tenant', 'handoffRequests']);

        return view('admin.operator-console.conversation-detail', [
            'session' => $session,
            'title' => 'Conversazione ' . $session->session_id
        ]);
    }

    /**
     * 🤝 Lista handoff richieste
     */
    public function handoffs(Request $request)
    {
        $query = HandoffRequest::with(['conversationSession', 'assignedOperator', 'tenant'])
                              ->where('status', 'pending');

        // Filtri
        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Ordina per priorità e età
        $handoffs = $query->orderByRaw("CASE 
            WHEN priority = 'urgent' THEN 1 
            WHEN priority = 'high' THEN 2 
            WHEN priority = 'normal' THEN 3 
            WHEN priority = 'low' THEN 4 
            END")
            ->orderBy('requested_at', 'asc')
            ->paginate(20);

        return view('admin.operator-console.handoffs', [
            'handoffs' => $handoffs,
            'title' => 'Richieste Handoff'
        ]);
    }

    /**
     * 👨‍💼 Assegna handoff a operatore
     */
    public function assignHandoff(Request $request, HandoffRequest $handoff)
    {
        $request->validate([
            'operator_id' => 'required|integer|exists:users,id'
        ]);

        $operator = User::find($request->operator_id);
        
        if (!$operator->isOperator() || !$operator->isAvailable()) {
            return back()->with('error', 'Operatore non disponibile');
        }

        $success = $this->handoffService->assignHandoff($handoff, $operator);

        if ($success) {
            return back()->with('success', 'Handoff assegnato a ' . $operator->name);
        } else {
            return back()->with('error', 'Errore nell\'assegnazione');
        }
    }

    /**
     * ✅ Risolvi handoff
     */
    public function resolveHandoff(Request $request, HandoffRequest $handoff)
    {
        $request->validate([
            'outcome' => 'required|string|in:resolved,escalated,cancelled',
            'notes' => 'nullable|string|max:1000',
            'satisfaction' => 'nullable|numeric|min:1|max:5'
        ]);

        $success = $this->handoffService->resolveHandoff(
            $handoff,
            $request->outcome,
            $request->notes,
            $request->satisfaction
        );

        if ($success) {
            return back()->with('success', 'Handoff risolto con esito: ' . $request->outcome);
        } else {
            return back()->with('error', 'Errore nella risoluzione');
        }
    }

    /**
     * 👥 Lista operatori
     */
    public function operators(Request $request)
    {
        $query = User::where('is_operator', true)
                    ->with(['assignedConversations' => function($q) {
                        $q->whereIn('status', ['active', 'assigned']);
                    }]);

        // Filtri
        if ($request->filled('status')) {
            $query->where('operator_status', $request->status);
        }

        $operators = $query->orderBy('name')->paginate(20);

        return view('admin.operator-console.operators', [
            'operators' => $operators,
            'title' => 'Gestione Operatori'
        ]);
    }

    /**
     * 🔄 Aggiorna status operatore
     */
    public function updateOperatorStatus(Request $request, User $user)
    {
        $request->validate([
            'status' => 'required|string|in:available,busy,offline,break'
        ]);

        if (!$user->isOperator()) {
            return back()->with('error', 'Utente non è un operatore');
        }

        $user->updateOperatorStatus($request->status);
        
        return back()->with('success', 'Status aggiornato a: ' . $request->status);
    }

    /**
     * 🎯 Take Over - Operatore prende controllo conversazione
     */
    public function takeOverConversation(Request $request, ConversationSession $session)
    {
        \Log::info('takeover.attempt', [
            'session_id' => $session->session_id,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email
        ]);
        
        $operator = Auth::user();
        
        if (!$operator->isOperator()) {
            return back()->with('error', 'Solo gli operatori possono prendere controllo delle conversazioni');
        }

        if (!$operator->isAvailable()) {
            return back()->with('error', 'Operatore non disponibile per nuove conversazioni');
        }

        // Check if conversation is available for takeover
        if ($session->status !== 'active' || !in_array($session->handoff_status, ['bot_only', 'handoff_requested'])) {
            return back()->with('error', 'Conversazione non disponibile per take over');
        }

        try {
            DB::transaction(function () use ($session, $operator) {
                // Update conversation
                $session->update([
                    'status' => 'assigned',
                    'handoff_status' => 'handoff_active',
                    'assigned_operator_id' => $operator->id,
                    'last_activity_at' => now()
                ]);

                // Create handoff request if doesn't exist
                $handoffRequest = HandoffRequest::firstOrCreate([
                    'conversation_session_id' => $session->id,
                    'tenant_id' => $session->tenant_id,
                ], [
                    'trigger_type' => 'manual_operator',
                    'reason' => 'Operatore ha preso controllo diretto della conversazione',
                    'priority' => 'normal',
                    'status' => 'assigned',
                    'assigned_to' => $operator->id,
                    'requested_at' => now(),
                    'assigned_at' => now()
                ]);

                // Update operator current conversations count
                $operator->incrementCurrentConversations();

                // Send system message to conversation
                ConversationMessage::create([
                    'conversation_session_id' => $session->id,
                    'tenant_id' => $session->tenant_id,
                    'sender_type' => 'system',
                    'content' => "👨‍💼 L'operatore {$operator->name} ha preso in carico la conversazione. Ti risponderò personalmente!",
                    'content_type' => 'text',
                    'sent_at' => now(),
                    'delivered_at' => now()
                ]);
            });

            return redirect()->route('admin.operator-console.conversations.show', $session)
                           ->with('success', 'Hai preso controllo della conversazione');

        } catch (\Exception $e) {
            \Log::error('takeover.failed', [
                'session_id' => $session->session_id,
                'operator_id' => $operator->id,
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Errore durante il take over');
        }
    }

    /**
     * 🏁 Release - Operatore rilascia controllo conversazione
     */
    public function releaseConversation(Request $request, ConversationSession $session)
    {
        $operator = Auth::user();
        
        if ($session->assigned_operator_id !== $operator->id) {
            return back()->with('error', 'Non hai il controllo di questa conversazione');
        }

        $request->validate([
            'resolution_note' => 'nullable|string|max:500',
            'transfer_back_to_bot' => 'boolean'
        ]);

        try {
            DB::transaction(function () use ($session, $operator, $request) {
                // Update conversation status
                $newStatus = $request->boolean('transfer_back_to_bot', true) ? 'active' : 'resolved';
                $newHandoffStatus = $request->boolean('transfer_back_to_bot', true) ? 'bot_only' : 'resolved';

                $session->update([
                    'status' => $newStatus,
                    'handoff_status' => $newHandoffStatus,
                    'assigned_operator_id' => null,
                    'last_activity_at' => now(),
                    'closed_at' => $newStatus === 'resolved' ? now() : null
                ]);

                // Resolve handoff request
                $handoffRequest = HandoffRequest::where('conversation_session_id', $session->id)
                                               ->where('status', 'assigned')
                                               ->first();
                if ($handoffRequest) {
                    $handoffRequest->update([
                        'status' => 'resolved',
                        'resolution_outcome' => $newStatus === 'resolved' ? 'resolved' : 'transferred_back',
                        'resolution_notes' => $request->resolution_note,
                        'resolved_at' => now()
                    ]);
                }

                // Update operator current conversations count
                $operator->decrementCurrentConversations();

                // Send system message
                $message = $newStatus === 'resolved' 
                    ? "✅ La conversazione è stata chiusa dall'operatore {$operator->name}. Grazie!"
                    : "🤖 Sono tornato! L'operatore {$operator->name} ha completato l'assistenza. Come posso aiutarti?";

                $systemMessage = ConversationMessage::create([
                    'conversation_session_id' => $session->id,
                    'tenant_id' => $session->tenant_id,
                    'sender_type' => 'system',
                    'content' => $message,
                    'content_type' => 'text',
                    'sent_at' => now(),
                    'delivered_at' => now()
                ]);
            });

            // 📡 Broadcast evento WebSocket anche per il messaggio di sistema
            try {
                if (isset($systemMessage)) {
                    \App\Events\ConversationMessageSent::dispatch($systemMessage);
                }
            } catch (\Exception $e) {
                \Log::warning('release.broadcast_failed', [
                    'session_id' => $session->session_id,
                    'error' => $e->getMessage()
                ]);
            }

            $message = $newStatus === 'resolved' 
                ? 'Conversazione chiusa con successo'
                : 'Conversazione trasferita al bot';
                
            return redirect()->route('admin.operator-console.conversations')
                           ->with('success', $message);

        } catch (\Exception $e) {
            \Log::error('release.failed', [
                'session_id' => $session->session_id,
                'operator_id' => $operator->id,
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Errore durante il rilascio');
        }
    }

    /**
     * 💬 Invia messaggio come operatore
     */
    public function sendMessage(Request $request, ConversationSession $session)
    {
        $operator = Auth::user();
        
        // Verifica che l'operatore abbia controllo della conversazione
        if ($session->assigned_operator_id !== $operator->id) {
            return response()->json(['error' => 'Non hai il controllo di questa conversazione'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:2000',
            'content_type' => 'string|in:text,markdown'
        ]);

        try {
            // Crea il messaggio
            $message = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'sender_type' => 'operator',
                'sender_id' => $operator->id,
                'sender_name' => $operator->name,
                'content' => $request->content,
                'content_type' => $request->input('content_type', 'text'),
                'sent_at' => now(),
                'delivered_at' => now()
            ]);

            // Aggiorna ultima attività sessione
            $session->update([
                'last_activity_at' => now(),
                'message_count_operator' => ($session->message_count_operator ?? 0) + 1,
                'message_count_total' => ($session->message_count_total ?? 0) + 1
            ]);

            // Invia via API al widget (stesso endpoint usato dal bot)
            $this->sendMessageToWidget($session, $message);

            // 📡 Broadcast evento WebSocket per real-time updates
            try {
                \App\Events\ConversationMessageSent::dispatch($message);
                \Log::info('operator.message_broadcasted', [
                    'session_id' => $session->session_id,
                    'message_id' => $message->id
                ]);
            } catch (\Exception $e) {
                \Log::warning('operator.broadcast_failed', [
                    'session_id' => $session->session_id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage()
                ]);
            }

            // 🎯 ALWAYS return JSON for AJAX requests
            return response()->json([
                'success' => true,
                'message' => [
                    'id' => $message->id,
                    'content' => $message->content,
                    'sender_name' => $message->sender_name,
                    'sent_at' => $message->sent_at->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('operator.message_send_failed', [
                'session_id' => $session->session_id,
                'operator_id' => $operator->id,
                'error' => $e->getMessage()
            ]);
            
            // 🎯 ALWAYS return JSON for AJAX requests
            return response()->json([
                'success' => false,
                'error' => 'Errore nell\'invio del messaggio: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📱 Invia messaggio al widget via API Messages
     */
    private function sendMessageToWidget(ConversationSession $session, ConversationMessage $message)
    {
        try {
            // Usa l'API Messages per sincronizzare con il widget
            $response = \Http::post(url('/api/v1/conversations/messages/send'), [
                'session_id' => $session->session_id,
                'content' => $message->content,
                'content_type' => $message->content_type,
                'sender_type' => 'operator',
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender_name
            ]);

            \Log::info('operator.message_synced_to_widget', [
                'session_id' => $session->session_id,
                'message_id' => $message->id,
                'response_status' => $response->status()
            ]);

        } catch (\Exception $e) {
            \Log::warning('operator.widget_sync_failed', [
                'session_id' => $session->session_id,
                'message_id' => $message->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🗑️ Elimina conversazione (solo admin)
     */
    public function deleteConversation(Request $request, ConversationSession $session)
    {
        // Solo admin possono eliminare conversazioni
        if (!auth()->user()->isAdmin()) {
            return back()->with('error', 'Solo gli amministratori possono eliminare conversazioni');
        }

        try {
            DB::transaction(function () use ($session) {
                // Se c'è un operatore assegnato, aggiorna i suoi contatori
                if ($session->assigned_operator_id) {
                    $operator = User::find($session->assigned_operator_id);
                    if ($operator) {
                        $operator->decrementCurrentConversations();
                    }
                }

                // Elimina messaggi associati
                ConversationMessage::where('conversation_session_id', $session->id)->delete();
                
                // Elimina handoff requests associati  
                HandoffRequest::where('conversation_session_id', $session->id)->delete();
                
                // Elimina la sessione
                $session->delete();
            });

            \Log::info('conversation.deleted', [
                'session_id' => $session->session_id,
                'deleted_by' => auth()->id(),
                'admin_email' => auth()->user()->email
            ]);

            return redirect()->route('admin.operator-console.conversations')
                           ->with('success', 'Conversazione eliminata con successo');

        } catch (\Exception $e) {
            \Log::error('conversation.delete_failed', [
                'session_id' => $session->session_id,
                'admin_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Errore durante l\'eliminazione della conversazione');
        }
    }
}