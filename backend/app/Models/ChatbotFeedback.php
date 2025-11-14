<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotFeedback extends Model
{
    protected $table = 'chatbot_feedback';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'user_question',
        'bot_response',
        'response_metadata',
        'rating',
        'comment',
        'session_id',
        'conversation_id',
        'message_id',
        'user_agent_data',
        'ip_address',
        'page_url',
        'feedback_given_at',
    ];

    protected $casts = [
        'response_metadata' => 'array',
        'user_agent_data' => 'array',
        'feedback_given_at' => 'datetime',
    ];

    // Enum per i rating
    public const RATING_NEGATIVE = 'negative';

    public const RATING_NEUTRAL = 'neutral';

    public const RATING_POSITIVE = 'positive';

    public const RATINGS = [
        self::RATING_NEGATIVE => '😡 Negativo',
        self::RATING_NEUTRAL => '😐 Neutro',
        self::RATING_POSITIVE => '😊 Positivo',
    ];

    /**
     * Relazione con il tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relazione con l'utente (se autenticato)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope per filtrare per tenant
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope per filtrare per rating
     */
    public function scopeWithRating($query, string $rating)
    {
        return $query->where('rating', $rating);
    }

    /**
     * Accessor per il testo del rating
     */
    public function getRatingTextAttribute(): string
    {
        return self::RATINGS[$this->rating] ?? 'Sconosciuto';
    }

    /**
     * Accessor per emoji del rating
     */
    public function getRatingEmojiAttribute(): string
    {
        return match ($this->rating) {
            self::RATING_NEGATIVE => '😡',
            self::RATING_NEUTRAL => '😐',
            self::RATING_POSITIVE => '😊',
            default => '❓'
        };
    }
}
