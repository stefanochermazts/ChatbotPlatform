<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class ConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_session_id',
        'tenant_id',
        'sender_type',
        'sender_id',
        'sender_name',
        'content',
        'content_type',
        'citations',
        'intent',
        'confidence_score',
        'prompt_used',
        'parent_message_id',
        'is_helpful',
        'response_time_ms',
        'rag_metadata',
        'metadata',
        'message_id',
        'is_edited',
        'edited_at',
        'edit_reason',
        'sent_at',
        'delivered_at',
        'read_at'
    ];

    protected $casts = [
        'citations' => 'array',
        'rag_metadata' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'decimal:4',
        'is_helpful' => 'boolean',
        'is_edited' => 'boolean',
        'response_time_ms' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'edited_at' => 'datetime'
    ];

    protected $dates = [
        'sent_at',
        'delivered_at',
        'read_at',
        'edited_at'
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

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'parent_message_id');
    }

    // 🎯 Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForSession($query, int $sessionId)
    {
        return $query->where('conversation_session_id', $sessionId);
    }

    public function scopeBySenderType($query, string $senderType)
    {
        return $query->where('sender_type', $senderType);
    }

    public function scopeByOperator($query, int $operatorId)
    {
        return $query->where('sender_type', 'operator')->where('sender_id', $operatorId);
    }

    public function scopeOrderedByTime($query)
    {
        return $query->orderBy('sent_at');
    }

    public function scopeWithCitations($query)
    {
        return $query->whereNotNull('citations');
    }

    public function scopeHelpful($query, bool $helpful = true)
    {
        return $query->where('is_helpful', $helpful);
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('sent_at', '>=', now()->subMinutes($minutes));
    }

    // 🔧 Helper Methods
    public function isFromUser(): bool
    {
        return $this->sender_type === 'user';
    }

    public function isFromBot(): bool
    {
        return $this->sender_type === 'bot';
    }

    public function isFromOperator(): bool
    {
        return $this->sender_type === 'operator';
    }

    public function isSystemMessage(): bool
    {
        return $this->sender_type === 'system';
    }

    public function hasBeenRead(): bool
    {
        return !is_null($this->read_at);
    }

    public function hasBeenDelivered(): bool
    {
        return !is_null($this->delivered_at);
    }

    public function hasCitations(): bool
    {
        return !empty($this->citations);
    }

    public function getResponseTimeInSeconds(): ?float
    {
        return $this->response_time_ms ? $this->response_time_ms / 1000 : null;
    }

    public function getCitationsCount(): int
    {
        return is_array($this->citations) ? count($this->citations) : 0;
    }

    // 🎯 Message Actions
    public function markAsDelivered(): bool
    {
        if ($this->delivered_at) return true;
        
        return $this->update(['delivered_at' => now()]);
    }

    public function markAsRead(): bool
    {
        if ($this->read_at) return true;
        
        return $this->update(['read_at' => now()]);
    }

    public function markAsHelpful(bool $helpful = true): bool
    {
        return $this->update(['is_helpful' => $helpful]);
    }

    public function editContent(string $newContent, string $reason = null): bool
    {
        return $this->update([
            'content' => $newContent,
            'is_edited' => true,
            'edited_at' => now(),
            'edit_reason' => $reason
        ]);
    }

    // 🎨 Formatting Helpers
    public function getFormattedContent(): string
    {
        if ($this->content_type === 'markdown') {
            // In futuro: convertire markdown in HTML
            return $this->content;
        }
        
        return $this->content;
    }

    public function getDisplayName(): string
    {
        if ($this->sender_name) {
            return $this->sender_name;
        }

        return match($this->sender_type) {
            'user' => 'Utente',
            'bot' => 'Assistente AI',
            'operator' => $this->sender ? $this->sender->name : 'Operatore',
            'system' => 'Sistema',
            default => 'Sconosciuto'
        };
    }
}
