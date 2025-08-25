<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_submission_id',
        'admin_user_id',
        'response_content',
        'response_type',
        'email_subject',
        'email_sent',
        'email_sent_at',
        'email_error',
        'closes_submission',
        'attachments',
        'internal_notes',
        // Threading fields
        'thread_id',
        'is_thread_starter',
        'parent_response_id',
        'email_message_id',
        'email_references',
        'admin_notified',
        'admin_notified_at',
        'user_notified',
        'user_notified_at',
        'user_read',
        'user_read_at',
        'priority',
        'tags',
    ];

    protected $casts = [
        'email_sent' => 'boolean',
        'email_sent_at' => 'datetime',
        'closes_submission' => 'boolean',
        'attachments' => 'array',
        // Threading casts
        'is_thread_starter' => 'boolean',
        'admin_notified' => 'boolean',
        'admin_notified_at' => 'datetime',
        'user_notified' => 'boolean',
        'user_notified_at' => 'datetime',
        'user_read' => 'boolean',
        'user_read_at' => 'datetime',
        'tags' => 'array',
    ];

    /**
     * Tipi di risposta
     */
    public const TYPE_WEB = 'web';
    public const TYPE_EMAIL = 'email';
    public const TYPE_AUTO = 'auto';

    public const TYPES = [
        self::TYPE_WEB => 'Interfaccia Web',
        self::TYPE_EMAIL => 'Email',
        self::TYPE_AUTO => 'Automatica',
    ];

    /**
     * Priorità risposta
     */
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'Bassa',
        self::PRIORITY_NORMAL => 'Normale',
        self::PRIORITY_HIGH => 'Alta',
        self::PRIORITY_URGENT => 'Urgente',
    ];

    /**
     * Relazione con FormSubmission
     */
    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }

    /**
     * Relazione con User (admin)
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Relazione con risposta parent (threading)
     */
    public function parentResponse(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'parent_response_id');
    }

    /**
     * Relazione con risposte child (threading)
     */
    public function childResponses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FormResponse::class, 'parent_response_id')->orderBy('created_at');
    }

    /**
     * Relazione con tutte le risposte del thread
     */
    public function threadResponses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FormResponse::class, 'thread_id', 'thread_id')->orderBy('created_at');
    }

    /**
     * Scope per tipo di risposta
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('response_type', $type);
    }

    /**
     * Scope per risposte email
     */
    public function scopeEmailResponses($query)
    {
        return $query->where('response_type', self::TYPE_EMAIL);
    }

    /**
     * Scope per risposte web
     */
    public function scopeWebResponses($query)
    {
        return $query->where('response_type', self::TYPE_WEB);
    }

    /**
     * Scope per email inviate
     */
    public function scopeEmailSent($query)
    {
        return $query->where('email_sent', true);
    }

    /**
     * Scope per email non inviate
     */
    public function scopeEmailPending($query)
    {
        return $query->where('response_type', self::TYPE_EMAIL)
                    ->where('email_sent', false);
    }

    /**
     * Scope per priorità
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope per risposte urgenti
     */
    public function scopeUrgent($query)
    {
        return $query->where('priority', self::PRIORITY_URGENT);
    }

    /**
     * Scope per thread starters
     */
    public function scopeThreadStarters($query)
    {
        return $query->where('is_thread_starter', true);
    }

    /**
     * Scope per risposte di un thread specifico
     */
    public function scopeInThread($query, string $threadId)
    {
        return $query->where('thread_id', $threadId);
    }

    /**
     * Scope per risposte non notificate agli admin
     */
    public function scopeAdminUnnotified($query)
    {
        return $query->where('admin_notified', false);
    }

    /**
     * Scope per risposte non lette dall'utente
     */
    public function scopeUserUnread($query)
    {
        return $query->where('user_read', false);
    }

    /**
     * Ottieni tipo formattato
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->response_type] ?? $this->response_type;
    }

    /**
     * Ottieni icona per tipo
     */
    public function getTypeIconAttribute(): string
    {
        return match($this->response_type) {
            self::TYPE_WEB => '💻',
            self::TYPE_EMAIL => '📧',
            self::TYPE_AUTO => '🤖',
            default => '❓'
        };
    }

    /**
     * Verifica se è una risposta email
     */
    public function isEmailResponse(): bool
    {
        return $this->response_type === self::TYPE_EMAIL;
    }

    /**
     * Verifica se è una risposta web
     */
    public function isWebResponse(): bool
    {
        return $this->response_type === self::TYPE_WEB;
    }

    /**
     * Verifica se è una risposta automatica
     */
    public function isAutoResponse(): bool
    {
        return $this->response_type === self::TYPE_AUTO;
    }

    /**
     * Verifica se l'email è stata inviata
     */
    public function isEmailSent(): bool
    {
        return $this->email_sent;
    }

    /**
     * Verifica se c'è stato un errore nell'invio email
     */
    public function hasEmailError(): bool
    {
        return !empty($this->email_error);
    }

    /**
     * Verifica se questa risposta chiude la sottomissione
     */
    public function closesSubmission(): bool
    {
        return $this->closes_submission;
    }

    /**
     * Marca email come inviata
     */
    public function markEmailSent(): void
    {
        $this->update([
            'email_sent' => true,
            'email_sent_at' => now(),
            'email_error' => null,
        ]);
    }

    /**
     * Marca errore invio email
     */
    public function markEmailError(string $error): void
    {
        $this->update([
            'email_sent' => false,
            'email_error' => $error,
        ]);
    }

    /**
     * Ottieni colore status email
     */
    public function getEmailStatusColorAttribute(): string
    {
        if (!$this->isEmailResponse()) {
            return 'gray';
        }

        if ($this->hasEmailError()) {
            return 'red';
        }

        if ($this->isEmailSent()) {
            return 'green';
        }

        return 'yellow'; // Pending
    }

    /**
     * Ottieni status email formattato
     */
    public function getEmailStatusLabelAttribute(): string
    {
        if (!$this->isEmailResponse()) {
            return 'N/A';
        }

        if ($this->hasEmailError()) {
            return 'Errore';
        }

        if ($this->isEmailSent()) {
            return 'Inviata';
        }

        return 'In coda';
    }

    /**
     * Ottieni admin che ha risposto
     */
    public function getAdminNameAttribute(): string
    {
        if ($this->isAutoResponse()) {
            return 'Sistema automatico';
        }

        return $this->adminUser?->name ?? 'Admin sconosciuto';
    }

    /**
     * Ottieni preview del contenuto
     */
    public function getContentPreviewAttribute(): string
    {
        $content = strip_tags($this->response_content);
        return mb_strlen($content) > 100 
            ? mb_substr($content, 0, 100) . '...'
            : $content;
    }

    /**
     * Ottieni numero di allegati
     */
    public function getAttachmentsCountAttribute(): int
    {
        return is_array($this->attachments) ? count($this->attachments) : 0;
    }

    // =================================================================
    // 🧵 THREADING METHODS
    // =================================================================

    /**
     * Verifica se è il primo messaggio del thread
     */
    public function isThreadStarter(): bool
    {
        return $this->is_thread_starter;
    }

    /**
     * Verifica se fa parte di un thread
     */
    public function isInThread(): bool
    {
        return !empty($this->thread_id);
    }

    /**
     * Ottieni priorità formattata
     */
    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    /**
     * Ottieni icona per priorità
     */
    public function getPriorityIconAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_LOW => '🟢',
            self::PRIORITY_NORMAL => '🟡',
            self::PRIORITY_HIGH => '🟠',
            self::PRIORITY_URGENT => '🔴',
            default => '⚪'
        };
    }

    /**
     * Ottieni colore per priorità
     */
    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_LOW => 'green',
            self::PRIORITY_NORMAL => 'yellow',
            self::PRIORITY_HIGH => 'orange',
            self::PRIORITY_URGENT => 'red',
            default => 'gray'
        };
    }

    /**
     * Genera thread ID per nuova conversazione
     */
    public static function generateThreadId(): string
    {
        return 'thread_' . uniqid() . '_' . time();
    }

    /**
     * Marca come notificato agli admin
     */
    public function markAdminNotified(): void
    {
        $this->update([
            'admin_notified' => true,
            'admin_notified_at' => now(),
        ]);
    }

    /**
     * Marca come notificato all'utente
     */
    public function markUserNotified(): void
    {
        $this->update([
            'user_notified' => true,
            'user_notified_at' => now(),
        ]);
    }

    /**
     * Marca come letto dall'utente
     */
    public function markUserRead(): void
    {
        $this->update([
            'user_read' => true,
            'user_read_at' => now(),
        ]);
    }

    /**
     * Ottieni numero risposte nel thread
     */
    public function getThreadResponsesCountAttribute(): int
    {
        if (!$this->thread_id) {
            return 0;
        }
        return self::where('thread_id', $this->thread_id)->count();
    }

    /**
     * Ottieni ultima risposta del thread
     */
    public function getLastThreadResponseAttribute(): ?FormResponse
    {
        if (!$this->thread_id) {
            return null;
        }
        return self::where('thread_id', $this->thread_id)->latest()->first();
    }

    /**
     * Verifica se l'admin deve essere notificato
     */
    public function shouldNotifyAdmin(): bool
    {
        return !$this->admin_notified && $this->response_type !== self::TYPE_AUTO;
    }

    /**
     * Verifica se l'utente deve essere notificato
     */
    public function shouldNotifyUser(): bool
    {
        return !$this->user_notified && $this->response_type === self::TYPE_EMAIL;
    }

    /**
     * Ottieni status thread formattato
     */
    public function getThreadStatusAttribute(): string
    {
        if (!$this->isInThread()) {
            return 'Messaggio singolo';
        }

        $count = $this->getThreadResponsesCountAttribute();
        return "Thread con {$count} " . ($count === 1 ? 'messaggio' : 'messaggi');
    }

    /**
     * Genera Message-ID per email threading
     */
    public function generateEmailMessageId(): string
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST) ?: 'chatbotplatform.com';
        return "<response-{$this->id}-" . time() . "@{$domain}>";
    }

    /**
     * Genera References header per email threading
     */
    public function generateEmailReferences(): string
    {
        $references = [];
        
        // Aggiungi Message-ID della submission originale se disponibile
        if ($this->formSubmission && $this->formSubmission->email_message_id) {
            $references[] = $this->formSubmission->email_message_id;
        }
        
        // Aggiungi Message-ID del parent se disponibile
        if ($this->parentResponse && $this->parentResponse->email_message_id) {
            $references[] = $this->parentResponse->email_message_id;
        }
        
        return implode(' ', $references);
    }
}