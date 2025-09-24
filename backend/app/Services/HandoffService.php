<?php

namespace App\Services;

use App\Models\ConversationSession;
use App\Models\ConversationMessage;
use App\Models\HandoffRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandoffService
{
    /**
     * 🤝 Richiede handoff da bot a operatore umano
     */
    public function requestHandoff(
        ConversationSession $session,
        string $triggerType,
        ?string $reason = null,
        array $contextData = [],
        string $priority = 'normal'
    ): HandoffRequest {
        try {
            // 🔒 Verifica che non ci sia già un handoff attivo
            $existingHandoff = $session->handoffRequests()
                                     ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                                     ->first();

            if ($existingHandoff) {
                Log::info('handoff.already_exists', [
                    'session_id' => $session->session_id,
                    'existing_handoff_id' => $existingHandoff->id
                ]);
                return $existingHandoff;
            }

            // 📝 Crea richiesta handoff
            $handoffRequest = HandoffRequest::create([
                'conversation_session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'trigger_type' => $triggerType,
                'reason' => $reason,
                'context_data' => $contextData,
                'priority' => $priority,
                'status' => 'pending',
                'requested_at' => now()
            ]);

            // 🔄 Aggiorna status sessione
            $session->update([
                'handoff_status' => 'handoff_requested',
                'handoff_requested_at' => now(),
                'handoff_reason' => $reason
            ]);

            // 📨 Messaggio di sistema per notificare handoff
            $this->createHandoffSystemMessage($session, $handoffRequest);

            Log::info('handoff.requested', [
                'session_id' => $session->session_id,
                'handoff_id' => $handoffRequest->id,
                'trigger_type' => $triggerType,
                'priority' => $priority
            ]);

            return $handoffRequest;

        } catch (\Exception $e) {
            Log::error('handoff.request_failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 👨‍💼 Assegna handoff a un operatore specifico
     */
    public function assignToOperator(HandoffRequest $handoffRequest, User $operator): bool
    {
        try {
            if (!$operator->isOperator()) {
                throw new \InvalidArgumentException('User is not an operator');
            }

            if (!$operator->canTakeNewConversation()) {
                throw new \InvalidArgumentException('Operator cannot take new conversations');
            }

            // 🔄 Aggiorna handoff request
            $success = $handoffRequest->assignToOperator($operator->id);

            if ($success) {
                // 📊 Incrementa counter conversazioni operatore
                $operator->incrementCurrentConversations();

                // 📨 Messaggio di sistema per conferma assegnazione
                $this->createAssignmentSystemMessage($handoffRequest->conversationSession, $operator);

                Log::info('handoff.assigned', [
                    'handoff_id' => $handoffRequest->id,
                    'operator_id' => $operator->id,
                    'session_id' => $handoffRequest->conversationSession->session_id
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('handoff.assignment_failed', [
                'handoff_id' => $handoffRequest->id,
                'operator_id' => $operator->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * ✅ Risolve un handoff
     */
    public function resolveHandoff(
        HandoffRequest $handoffRequest,
        string $outcome,
        ?string $notes = null,
        ?float $satisfaction = null
    ): bool {
        try {
            $success = $handoffRequest->resolve($outcome, $notes, $satisfaction);

            if ($success) {
                // 📉 Decrementa counter conversazioni operatore
                if ($handoffRequest->assignedOperator) {
                    $handoffRequest->assignedOperator->decrementCurrentConversations();
                }

                // 📨 Messaggio di sistema per conferma risoluzione
                $this->createResolutionSystemMessage($handoffRequest->conversationSession, $outcome);

                Log::info('handoff.resolved', [
                    'handoff_id' => $handoffRequest->id,
                    'outcome' => $outcome,
                    'satisfaction' => $satisfaction
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('handoff.resolution_failed', [
                'handoff_id' => $handoffRequest->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 🔼 Escalation di un handoff
     */
    public function escalateHandoff(HandoffRequest $handoffRequest, array $escalationData = []): ?HandoffRequest
    {
        try {
            $escalatedRequest = $handoffRequest->escalate($escalationData);

            if ($escalatedRequest) {
                // 📨 Messaggio di sistema per notificare escalation
                $this->createEscalationSystemMessage($handoffRequest->conversationSession, $escalatedRequest);

                Log::info('handoff.escalated', [
                    'original_handoff_id' => $handoffRequest->id,
                    'escalated_handoff_id' => $escalatedRequest->id
                ]);
            }

            return $escalatedRequest;

        } catch (\Exception $e) {
            Log::error('handoff.escalation_failed', [
                'handoff_id' => $handoffRequest->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * ⏰ Gestisce timeout dei handoff
     */
    public function handleTimeouts(int $timeoutMinutes = 60): int
    {
        $timeoutCount = 0;

        try {
            $timedOutHandoffs = HandoffRequest::where('status', 'pending')
                                            ->where('requested_at', '<=', now()->subMinutes($timeoutMinutes))
                                            ->get();

            foreach ($timedOutHandoffs as $handoff) {
                $handoff->update([
                    'status' => 'timeout',
                    'resolution_notes' => "Handoff timed out after {$timeoutMinutes} minutes"
                ]);

                // 🔄 Reset session to bot-only
                $handoff->conversationSession->update([
                    'handoff_status' => 'bot_only'
                ]);

                $timeoutCount++;

                Log::warning('handoff.timeout', [
                    'handoff_id' => $handoff->id,
                    'session_id' => $handoff->conversationSession->session_id,
                    'timeout_minutes' => $timeoutMinutes
                ]);
            }

        } catch (\Exception $e) {
            Log::error('handoff.timeout_handling_failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $timeoutCount;
    }

    /**
     * 📈 Ottieni metriche handoff per tenant
     */
    public function getHandoffMetrics(int $tenantId, ?\DateTime $since = null): array
    {
        try {
            $query = HandoffRequest::where('tenant_id', $tenantId);

            if ($since) {
                $query->where('requested_at', '>=', $since);
            }

            $total = $query->count();
            $pending = $query->where('status', 'pending')->count();
            $resolved = $query->where('status', 'resolved')->count();
            $timedOut = $query->where('status', 'timeout')->count();

            $avgWaitTime = $query->whereNotNull('wait_time_seconds')
                                ->avg('wait_time_seconds') ?? 0;

            $avgResolutionTime = $query->whereNotNull('resolution_time_seconds')
                                     ->avg('resolution_time_seconds') ?? 0;

            return [
                'total_handoffs' => $total,
                'pending_handoffs' => $pending,
                'resolved_handoffs' => $resolved,
                'timeout_handoffs' => $timedOut,
                'resolution_rate' => $total > 0 ? ($resolved / $total) * 100 : 0,
                'timeout_rate' => $total > 0 ? ($timedOut / $total) * 100 : 0,
                'avg_wait_time_minutes' => round($avgWaitTime / 60, 2),
                'avg_resolution_time_minutes' => round($avgResolutionTime / 60, 2)
            ];

        } catch (\Exception $e) {
            Log::error('handoff.metrics_failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_handoffs' => 0,
                'pending_handoffs' => 0,
                'resolved_handoffs' => 0,
                'timeout_handoffs' => 0,
                'resolution_rate' => 0,
                'timeout_rate' => 0,
                'avg_wait_time_minutes' => 0,
                'avg_resolution_time_minutes' => 0
            ];
        }
    }

    /**
     * 📨 Crea messaggio di sistema per handoff richiesto
     */
    private function createHandoffSystemMessage(ConversationSession $session, HandoffRequest $handoffRequest): void
    {
        $content = match($handoffRequest->trigger_type) {
            'user_explicit' => "L'utente ha richiesto di parlare con un operatore.",
            'bot_escalation' => "Il sistema ha rilevato la necessità di supporto umano.",
            'sentiment_negative' => "È stato rilevato un sentiment negativo. Richiesta assistenza umana.",
            'timeout_frustration' => "Rilevata frustrazione dell'utente. Richiesta intervento operatore.",
            default => "Richiesta assistenza operatore umano."
        };

        ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'sender_type' => 'system',
            'content' => $content,
            'content_type' => 'system_event',
            'metadata' => [
                'handoff_request_id' => $handoffRequest->id,
                'trigger_type' => $handoffRequest->trigger_type,
                'priority' => $handoffRequest->priority
            ],
            'sent_at' => now(),
            'delivered_at' => now()
        ]);
    }

    /**
     * 📨 Crea messaggio di sistema per assegnazione operatore
     */
    private function createAssignmentSystemMessage(ConversationSession $session, User $operator): void
    {
        $content = "Ciao! Sono {$operator->name}, il tuo operatore. Come posso aiutarti?";

        ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'sender_type' => 'operator',
            'sender_id' => $operator->id,
            'sender_name' => $operator->name,
            'content' => $content,
            'content_type' => 'text',
            'sent_at' => now(),
            'delivered_at' => now()
        ]);

        $session->incrementMessageCount('operator');
    }

    /**
     * 📨 Crea messaggio di sistema per risoluzione
     */
    private function createResolutionSystemMessage(ConversationSession $session, string $outcome): void
    {
        $content = match($outcome) {
            'resolved_by_operator' => "La conversazione è stata risolta dall'operatore.",
            'user_satisfied' => "Grazie per aver utilizzato il nostro servizio!",
            'transferred_back_to_bot' => "Ti rimetto in contatto con l'assistente automatico.",
            default => "La conversazione è stata completata."
        };

        ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'sender_type' => 'system',
            'content' => $content,
            'content_type' => 'system_event',
            'metadata' => ['resolution_outcome' => $outcome],
            'sent_at' => now(),
            'delivered_at' => now()
        ]);
    }

    /**
     * 📨 Crea messaggio di sistema per escalation
     */
    private function createEscalationSystemMessage(ConversationSession $session, HandoffRequest $escalatedRequest): void
    {
        $content = "La tua richiesta è stata inoltrata a un supervisore per un supporto di livello superiore.";

        ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'sender_type' => 'system',
            'content' => $content,
            'content_type' => 'system_event',
            'metadata' => ['escalated_handoff_id' => $escalatedRequest->id],
            'sent_at' => now(),
            'delivered_at' => now()
        ]);
    }
}
