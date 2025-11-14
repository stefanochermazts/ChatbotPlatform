<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'widget_config_id',
        'session_id',
        'user_identifier',
        'channel',
        'user_agent',
        'referrer_url',
        'browser_info',
        'status',
        'handoff_status',
        'assigned_operator_id',
        'assigned_at',
        'handoff_requested_at',
        'handoff_reason',
        'started_at',
        'last_activity_at',
        'ended_at',
        'total_messages',
        'bot_messages',
        'user_messages',
        'operator_messages',
        'satisfaction_score',
        'goal_achieved',
        'resolution_type',
        'metadata',
        'tags',
        'summary',
    ];

    protected $casts = [
        'browser_info' => 'array',
        'metadata' => 'array',
        'tags' => 'array',
        'assigned_at' => 'datetime',
        'handoff_requested_at' => 'datetime',
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'ended_at' => 'datetime',
        'satisfaction_score' => 'decimal:2',
        'goal_achieved' => 'boolean',
        'total_messages' => 'integer',
        'bot_messages' => 'integer',
        'user_messages' => 'integer',
        'operator_messages' => 'integer',
    ];

    protected $dates = [
        'assigned_at',
        'handoff_requested_at',
        'started_at',
        'last_activity_at',
        'ended_at',
    ];

    // 🏢 Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function widgetConfig(): BelongsTo
    {
        return $this->belongsTo(WidgetConfig::class);
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('sent_at');
    }

    public function handoffRequests(): HasMany
    {
        return $this->hasMany(HandoffRequest::class);
    }

    // 🎯 Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeWaitingOperator($query)
    {
        return $query->where('status', 'waiting_operator');
    }

    public function scopeAssignedToOperator($query, int $operatorId)
    {
        return $query->where('assigned_operator_id', $operatorId);
    }

    public function scopeWithHandoffStatus($query, string $status)
    {
        return $query->where('handoff_status', $status);
    }

    // 🔧 Helper Methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isAssigned(): bool
    {
        return $this->status === 'assigned' && ! is_null($this->assigned_operator_id);
    }

    public function hasHandoffRequested(): bool
    {
        return in_array($this->handoff_status, ['handoff_requested', 'handoff_pending', 'handoff_active']);
    }

    public function getDurationInMinutes(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $endTime = $this->ended_at ?? now();

        return $this->started_at->diffInMinutes($endTime);
    }

    public function getInactivityInMinutes(): ?int
    {
        if (! $this->last_activity_at) {
            return null;
        }

        return $this->last_activity_at->diffInMinutes(now());
    }

    public function updateActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    public function incrementMessageCount(string $senderType): void
    {
        $this->increment('total_messages');

        match ($senderType) {
            'user' => $this->increment('user_messages'),
            'bot' => $this->increment('bot_messages'),
            'operator' => $this->increment('operator_messages'),
            default => null
        };
    }

    // 🎯 Session Management
    public function assignToOperator(int $operatorId, ?string $reason = null): bool
    {
        return $this->update([
            'status' => 'assigned',
            'handoff_status' => 'handoff_active',
            'assigned_operator_id' => $operatorId,
            'assigned_at' => now(),
            'handoff_reason' => $reason,
        ]);
    }

    public function endSession(?string $resolutionType = null): bool
    {
        return $this->update([
            'status' => 'resolved',
            'ended_at' => now(),
            'resolution_type' => $resolutionType,
        ]);
    }
}
