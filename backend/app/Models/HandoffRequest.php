<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_session_id',
        'tenant_id',
        'requesting_message_id',
        'trigger_type',
        'reason',
        'user_message',
        'context_data',
        'priority',
        'routing_criteria',
        'preferred_operator_id',
        'status',
        'assigned_operator_id',
        'assigned_at',
        'assignment_attempts',
        'requested_at',
        'first_response_at',
        'resolved_at',
        'wait_time_seconds',
        'resolution_time_seconds',
        'resolution_outcome',
        'resolution_notes',
        'user_satisfaction',
        'feedback_data',
        'metadata',
        'tags',
        'is_escalated',
        'escalated_to_request_id',
    ];

    protected $casts = [
        'context_data' => 'array',
        'routing_criteria' => 'array',
        'feedback_data' => 'array',
        'metadata' => 'array',
        'tags' => 'array',
        'user_satisfaction' => 'decimal:2',
        'is_escalated' => 'boolean',
        'assignment_attempts' => 'integer',
        'wait_time_seconds' => 'integer',
        'resolution_time_seconds' => 'integer',
        'requested_at' => 'datetime',
        'assigned_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $dates = [
        'requested_at',
        'assigned_at',
        'first_response_at',
        'resolved_at',
    ];

    // 🏢 Relationships
    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requestingMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'requesting_message_id');
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function escalatedToRequest(): BelongsTo
    {
        return $this->belongsTo(HandoffRequest::class, 'escalated_to_request_id');
    }

    // 🎯 Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    public function scopeForOperator($query, int $operatorId)
    {
        return $query->where('assigned_operator_id', $operatorId);
    }

    public function scopeByTriggerType($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    public function scopeOverdue($query, int $minutes = 60)
    {
        return $query->where('status', 'pending')
            ->where('requested_at', '<=', now()->subMinutes($minutes));
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderByRaw("
            CASE priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'normal' THEN 3 
                WHEN 'low' THEN 4 
            END
        ")->orderBy('requested_at');
    }

    // 🔧 Helper Methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAssigned(): bool
    {
        return $this->status === 'assigned' && ! is_null($this->assigned_operator_id);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function isHighPriority(): bool
    {
        return in_array($this->priority, ['high', 'urgent']);
    }

    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    public function hasBeenAssigned(): bool
    {
        return ! is_null($this->assigned_operator_id) && ! is_null($this->assigned_at);
    }

    public function getWaitTimeInMinutes(): ?int
    {
        if (! $this->assigned_at || ! $this->requested_at) {
            return null;
        }

        return $this->requested_at->diffInMinutes($this->assigned_at);
    }

    public function getResolutionTimeInMinutes(): ?int
    {
        if (! $this->resolved_at || ! $this->requested_at) {
            return null;
        }

        return $this->requested_at->diffInMinutes($this->resolved_at);
    }

    public function getAgeInMinutes(): int
    {
        return $this->requested_at->diffInMinutes(now());
    }

    public function isOverdue(int $thresholdMinutes = 60): bool
    {
        return $this->isPending() && $this->getAgeInMinutes() > $thresholdMinutes;
    }

    // 🎯 Handoff Management
    public function assignToOperator(int $operatorId): bool
    {
        $success = $this->update([
            'status' => 'assigned',
            'assigned_operator_id' => $operatorId,
            'assigned_at' => now(),
            'wait_time_seconds' => (int) $this->requested_at->diffInSeconds(now()),  // ✅ Cast to int
        ]);

        if ($success) {
            $this->increment('assignment_attempts');

            // Update session status
            $this->conversationSession->update([
                'status' => 'assigned',
                'handoff_status' => 'handoff_active',
                'assigned_operator_id' => $operatorId,
                'assigned_at' => now(),
            ]);
        }

        return $success;
    }

    public function markInProgress(): bool
    {
        return $this->update(['status' => 'in_progress']);
    }

    public function resolve(string $outcome, ?string $notes = null, ?float $satisfaction = null): bool
    {
        $success = $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_outcome' => $outcome,
            'resolution_notes' => $notes,
            'user_satisfaction' => $satisfaction,
            'resolution_time_seconds' => (int) $this->requested_at->diffInSeconds(now()),  // ✅ Cast to int
        ]);

        if ($success) {
            // Update session status
            $this->conversationSession->update([
                'status' => 'resolved',
                'handoff_status' => 'handoff_completed',
                'ended_at' => now(),
                'resolution_type' => 'operator_resolved',
            ]);
        }

        return $success;
    }

    public function escalate(array $escalationData = []): ?HandoffRequest
    {
        $escalatedRequest = static::create([
            'conversation_session_id' => $this->conversation_session_id,
            'tenant_id' => $this->tenant_id,
            'trigger_type' => 'manual_operator',
            'reason' => 'Escalation from request #'.$this->id,
            'priority' => 'high', // Escalation sempre high priority
            'status' => 'pending',
            'requested_at' => now(),
            'context_data' => array_merge($this->context_data ?? [], $escalationData),
            'metadata' => [
                'escalated_from' => $this->id,
                'original_trigger' => $this->trigger_type,
                'escalation_reason' => $escalationData['reason'] ?? 'Standard escalation',
            ],
        ]);

        if ($escalatedRequest) {
            $this->update([
                'status' => 'escalated',
                'is_escalated' => true,
                'escalated_to_request_id' => $escalatedRequest->id,
            ]);
        }

        return $escalatedRequest;
    }

    public function cancel(?string $reason = null): bool
    {
        return $this->update([
            'status' => 'cancelled',
            'resolution_notes' => $reason,
        ]);
    }

    // 🎨 Display Helpers
    public function getPriorityColor(): string
    {
        return match ($this->priority) {
            'urgent' => 'red',
            'high' => 'orange',
            'normal' => 'blue',
            'low' => 'gray',
            default => 'gray'
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'assigned' => 'blue',
            'in_progress' => 'green',
            'resolved' => 'green',
            'escalated' => 'purple',
            'cancelled' => 'gray',
            'timeout' => 'red',
            'failed' => 'red',
            default => 'gray'
        };
    }

    public function getTriggerTypeLabel(): string
    {
        return match ($this->trigger_type) {
            'user_explicit' => 'Richiesta utente',
            'bot_escalation' => 'Escalation automatica',
            'intent_complex' => 'Query complessa',
            'sentiment_negative' => 'Sentiment negativo',
            'timeout_frustration' => 'Timeout conversazione',
            'manual_operator' => 'Intervento manuale',
            'system_rule' => 'Regola automatica',
            default => 'Sconosciuto'
        };
    }
}
