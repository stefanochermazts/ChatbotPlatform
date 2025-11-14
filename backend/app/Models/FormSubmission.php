<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_form_id',
        'tenant_id',
        'session_id',
        'user_email',
        'user_name',
        'form_data',
        'chat_context',
        'status',
        'submitted_at',
        'ip_address',
        'user_agent',
        'trigger_type',
        'trigger_value',
        'confirmation_email_sent',
        'confirmation_email_sent_at',
        'admin_notification_sent',
        'admin_notification_sent_at',
        // Thread tracking fields
        'responses_count',
        'last_response_at',
        'last_response_by',
        'has_active_conversation',
        'conversation_priority',
        'first_response_time_minutes',
        'avg_response_time_minutes',
    ];

    protected $casts = [
        'form_data' => 'array',
        'chat_context' => 'array',
        'submitted_at' => 'datetime',
        'confirmation_email_sent' => 'boolean',
        'confirmation_email_sent_at' => 'datetime',
        'admin_notification_sent' => 'boolean',
        'admin_notification_sent_at' => 'datetime',
        // Thread tracking casts
        'last_response_at' => 'datetime',
        'has_active_conversation' => 'boolean',
        'responses_count' => 'integer',
        'first_response_time_minutes' => 'float',
        'avg_response_time_minutes' => 'float',
    ];

    /**
     * Status disponibili
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING => 'In attesa',
        self::STATUS_RESPONDED => 'Risposta inviata',
        self::STATUS_CLOSED => 'Chiusa',
    ];

    /**
     * Trigger types
     */
    public const TRIGGER_KEYWORD = 'keyword';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_QUESTION = 'question';

    /**
     * Relazione con TenantForm
     */
    public function tenantForm(): BelongsTo
    {
        return $this->belongsTo(TenantForm::class);
    }

    /**
     * Relazione con Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relazione con FormResponse
     */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class)->orderBy('created_at');
    }

    /**
     * Relazione con l'ultimo admin che ha risposto
     */
    public function lastResponseByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'last_response_by');
    }

    /**
     * Scope per status
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeResponded($query)
    {
        return $query->where('status', self::STATUS_RESPONDED);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    /**
     * Scope per tenant
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope per sessione
     */
    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope per conversazioni attive
     */
    public function scopeActiveConversations($query)
    {
        return $query->where('has_active_conversation', true);
    }

    /**
     * Scope per priorità conversazione
     */
    public function scopeByConversationPriority($query, string $priority)
    {
        return $query->where('conversation_priority', $priority);
    }

    /**
     * Scope per conversazioni urgenti
     */
    public function scopeUrgentConversations($query)
    {
        return $query->where('conversation_priority', 'urgent');
    }

    /**
     * Scope per conversazioni con risposta recente
     */
    public function scopeRecentActivity($query, int $hours = 24)
    {
        return $query->where('last_response_at', '>=', now()->subHours($hours));
    }

    /**
     * Ottieni status formattato
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Ottieni colore per status
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_RESPONDED => 'blue',
            self::STATUS_CLOSED => 'gray',
            default => 'gray'
        };
    }

    /**
     * Verifica se è in attesa di risposta
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Verifica se ha già ricevuto risposta
     */
    public function isResponded(): bool
    {
        return $this->status === self::STATUS_RESPONDED;
    }

    /**
     * Verifica se è chiusa
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Marca come risposta inviata
     */
    public function markAsResponded(): void
    {
        $this->update(['status' => self::STATUS_RESPONDED]);
    }

    /**
     * Chiudi la sottomissione
     */
    public function close(): void
    {
        $this->update(['status' => self::STATUS_CLOSED]);
    }

    /**
     * Ottieni dati form formattati per visualizzazione
     */
    public function getFormattedDataAttribute(): array
    {
        $formatted = [];

        if (! $this->form_data) {
            return $formatted;
        }

        // Ottieni i campi del form per formattare correttamente
        $fields = $this->tenantForm->fields()->get()->keyBy('name');

        foreach ($this->form_data as $fieldName => $value) {
            $field = $fields->get($fieldName);
            $label = $field ? $field->label : $fieldName;

            // Formatta il valore basandosi sul tipo di campo
            if ($field && $field->type === 'checkbox' && is_array($value)) {
                $value = implode(', ', $value);
            }

            $formatted[] = [
                'label' => $label,
                'value' => $value,
                'field_name' => $fieldName,
                'field_type' => $field?->type ?? 'text',
            ];
        }

        return $formatted;
    }

    /**
     * Ottieni numero di risposte
     */
    public function getResponsesCountAttribute(): int
    {
        return $this->responses()->count();
    }

    /**
     * Ottieni ultima risposta
     */
    public function getLastResponseAttribute(): ?FormResponse
    {
        return $this->responses()->latest()->first();
    }

    /**
     * Verifica se l'email di conferma è stata inviata
     */
    public function isConfirmationEmailSent(): bool
    {
        return $this->confirmation_email_sent;
    }

    /**
     * Verifica se la notifica admin è stata inviata
     */
    public function isAdminNotificationSent(): bool
    {
        return $this->admin_notification_sent;
    }

    /**
     * Marca email di conferma come inviata
     */
    public function markConfirmationEmailSent(): void
    {
        $this->update([
            'confirmation_email_sent' => true,
            'confirmation_email_sent_at' => now(),
        ]);
    }

    /**
     * Marca notifica admin come inviata
     */
    public function markAdminNotificationSent(): void
    {
        $this->update([
            'admin_notification_sent' => true,
            'admin_notification_sent_at' => now(),
        ]);
    }

    /**
     * Ottieni trigger formattato
     */
    public function getTriggerDescriptionAttribute(): string
    {
        $type = match ($this->trigger_type) {
            self::TRIGGER_KEYWORD => 'Parola chiave',
            self::TRIGGER_AUTO => 'Automatico',
            self::TRIGGER_MANUAL => 'Manuale',
            self::TRIGGER_QUESTION => 'Domanda',
            default => 'Sconosciuto'
        };

        if ($this->trigger_value) {
            $type .= ": {$this->trigger_value}";
        }

        return $type;
    }

    // =================================================================
    // 🧵 THREAD TRACKING METHODS
    // =================================================================

    /**
     * Verifica se ha conversazione attiva
     */
    public function hasActiveConversation(): bool
    {
        return $this->has_active_conversation;
    }

    /**
     * Attiva conversazione
     */
    public function activateConversation(string $priority = 'normal'): void
    {
        $this->update([
            'has_active_conversation' => true,
            'conversation_priority' => $priority,
        ]);
    }

    /**
     * Disattiva conversazione
     */
    public function deactivateConversation(): void
    {
        $this->update([
            'has_active_conversation' => false,
        ]);
    }

    /**
     * Aggiorna statistiche dopo nuova risposta
     */
    public function updateResponseStats(FormResponse $response): void
    {
        $now = now();
        $responsesCount = $this->responses()->count();

        // Calcola tempo prima risposta se è la prima
        $firstResponseTime = null;
        if ($responsesCount === 1) {
            $firstResponseTime = $this->submitted_at->diffInMinutes($now);
        }

        // Calcola tempo medio risposta
        $avgResponseTime = $this->calculateAverageResponseTime();

        $this->update([
            'responses_count' => $responsesCount,
            'last_response_at' => $now,
            'last_response_by' => $response->admin_user_id,
            'first_response_time_minutes' => $firstResponseTime ?? $this->first_response_time_minutes,
            'avg_response_time_minutes' => $avgResponseTime,
            'has_active_conversation' => ! $response->closes_submission,
        ]);
    }

    /**
     * Calcola tempo medio di risposta
     */
    private function calculateAverageResponseTime(): int
    {
        $responses = $this->responses()->get();
        if ($responses->isEmpty()) {
            return 0;
        }

        $totalMinutes = 0;
        $previousTime = $this->submitted_at;

        foreach ($responses as $response) {
            $totalMinutes += $previousTime->diffInMinutes($response->created_at);
            $previousTime = $response->created_at;
        }

        return intval($totalMinutes / $responses->count());
    }

    /**
     * Ottieni priorità conversazione formattata
     */
    public function getConversationPriorityLabelAttribute(): string
    {
        return match ($this->conversation_priority) {
            'low' => 'Bassa',
            'normal' => 'Normale',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            default => 'Non definita'
        };
    }

    /**
     * Ottieni icona priorità conversazione
     */
    public function getConversationPriorityIconAttribute(): string
    {
        return match ($this->conversation_priority) {
            'low' => '🟢',
            'normal' => '🟡',
            'high' => '🟠',
            'urgent' => '🔴',
            default => '⚪'
        };
    }

    /**
     * Ottieni colore priorità conversazione
     */
    public function getConversationPriorityColorAttribute(): string
    {
        return match ($this->conversation_priority) {
            'low' => 'green',
            'normal' => 'yellow',
            'high' => 'orange',
            'urgent' => 'red',
            default => 'gray'
        };
    }

    /**
     * Ottieni tempo trascorso dall'ultima risposta
     */
    public function getTimeSinceLastResponseAttribute(): string
    {
        if (! $this->last_response_at) {
            return 'Nessuna risposta';
        }

        return $this->last_response_at->diffForHumans();
    }

    /**
     * Ottieni tempo risposta formattato
     */
    public function getFirstResponseTimeFormattedAttribute(): string
    {
        if (! $this->first_response_time_minutes) {
            return 'N/A';
        }

        $hours = intval($this->first_response_time_minutes / 60);
        $minutes = $this->first_response_time_minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * Ottieni tempo medio risposta formattato
     */
    public function getAvgResponseTimeFormattedAttribute(): string
    {
        if (! $this->avg_response_time_minutes) {
            return 'N/A';
        }

        $hours = intval($this->avg_response_time_minutes / 60);
        $minutes = $this->avg_response_time_minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * Verifica se richiede attenzione urgente
     */
    public function needsUrgentAttention(): bool
    {
        // Conversazione urgente senza risposta da più di 1 ora
        if ($this->conversation_priority === 'urgent' &&
            $this->has_active_conversation &&
            $this->last_response_at &&
            $this->last_response_at->diffInHours(now()) > 1) {
            return true;
        }

        // Conversazione normale senza risposta da più di 24 ore
        if ($this->has_active_conversation &&
            $this->last_response_at &&
            $this->last_response_at->diffInHours(now()) > 24) {
            return true;
        }

        return false;
    }

    /**
     * Ottieni status conversazione
     */
    public function getConversationStatusAttribute(): string
    {
        if (! $this->has_active_conversation) {
            return 'Chiusa';
        }

        if ($this->needsUrgentAttention()) {
            return 'Richiede attenzione';
        }

        return 'Attiva';
    }
}
