<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'metadata',
        'languages',
        'default_language',
        'custom_system_prompt',
        'custom_context_template',
        'extra_intent_keywords',
        'custom_synonyms',
        'multi_kb_search',
        'rag_settings',
        'rag_profile',
        'whatsapp_config',
    ];

    protected $casts = [
        'metadata' => 'array',
        'languages' => 'array',
        'whatsapp_config' => 'array',
        'extra_intent_keywords' => 'array',
        'custom_synonyms' => 'array',
        'multi_kb_search' => 'boolean',
        'rag_settings' => 'array',
    ];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function widgetConfig(): HasOne
    {
        return $this->hasOne(WidgetConfig::class);
    }

    /**
     * Get the API key for widget integration
     */
    public function getWidgetApiKey(): ?string
    {
        // Prefer the stored (encrypted) key if available; fallback to null
        $apiKey = $this->apiKeys()->whereNull('revoked_at')->latest()->first();
        if (!$apiKey) {
            return null;
        }
        return $apiKey->key ?? null;
    }

    /**
     * Get tenant forms for this tenant
     */
    public function forms(): HasMany
    {
        return $this->hasMany(TenantForm::class);
    }

    /**
     * Get form submissions for this tenant
     */
    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    /**
     * Relazione con i messaggi Vonage
     */
    public function vonageMessages(): HasMany
    {
        return $this->hasMany(VonageMessage::class);
    }

    /**
     * Verifica se il tenant ha configurazione WhatsApp attiva
     */
    public function hasWhatsAppConfig(): bool
    {
        return !empty($this->whatsapp_config['phone_number']) && 
               ($this->whatsapp_config['is_active'] ?? false);
    }

    /**
     * Ottieni il numero WhatsApp del tenant
     */
    public function getWhatsAppNumber(): ?string
    {
        return $this->whatsapp_config['phone_number'] ?? null;
    }

    /**
     * Ottieni la configurazione completa WhatsApp
     */
    public function getWhatsAppConfig(): array
    {
        return $this->whatsapp_config ?: [
            'phone_number' => null,
            'is_active' => false,
            'welcome_message' => null,
            'business_hours' => [
                'enabled' => false,
                'timezone' => 'Europe/Rome',
                'monday' => ['start' => '09:00', 'end' => '18:00'],
                'tuesday' => ['start' => '09:00', 'end' => '18:00'],
                'wednesday' => ['start' => '09:00', 'end' => '18:00'],
                'thursday' => ['start' => '09:00', 'end' => '18:00'],
                'friday' => ['start' => '09:00', 'end' => '18:00'],
                'saturday' => ['start' => '09:00', 'end' => '13:00'],
                'sunday' => ['closed' => true]
            ],
            'auto_response' => [
                'enabled' => true,
                'response_delay' => 1 // secondi
            ]
        ];
    }

    // 🎯 Agent Console Relationships

    /**
     * Sessioni di conversazione del tenant
     */
    public function conversationSessions(): HasMany
    {
        return $this->hasMany(ConversationSession::class);
    }

    /**
     * Messaggi delle conversazioni del tenant
     */
    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /**
     * Richieste di handoff del tenant
     */
    public function handoffRequests(): HasMany
    {
        return $this->hasMany(HandoffRequest::class);
    }

    // 🎯 Agent Console Scopes & Helpers

    /**
     * Ottieni le conversazioni attive del tenant
     */
    public function getActiveConversations()
    {
        return $this->conversationSessions()
                   ->whereIn('status', ['active', 'assigned'])
                   ->with(['messages' => function($query) {
                       $query->latest('sent_at')->limit(1);
                   }, 'assignedOperator']);
    }

    /**
     * Ottieni le richieste di handoff pendenti del tenant
     */
    public function getPendingHandoffRequests()
    {
        return $this->handoffRequests()
                   ->where('status', 'pending')
                   ->orderBy('priority')
                   ->orderBy('requested_at');
    }

    /**
     * Ottieni le metriche conversazioni del tenant
     */
    public function getConversationMetrics(): array
    {
        $totalSessions = $this->conversationSessions()->count();
        $activeSessions = $this->conversationSessions()->where('status', 'active')->count();
        $resolvedSessions = $this->conversationSessions()->where('status', 'resolved')->count();
        $pendingHandoffs = $this->handoffRequests()->where('status', 'pending')->count();

        return [
            'total_sessions' => $totalSessions,
            'active_sessions' => $activeSessions,
            'resolved_sessions' => $resolvedSessions,
            'pending_handoffs' => $pendingHandoffs,
            'resolution_rate' => $totalSessions > 0 ? ($resolvedSessions / $totalSessions) * 100 : 0,
            'handoff_rate' => $totalSessions > 0 ? ($this->handoffRequests()->count() / $totalSessions) * 100 : 0
        ];
    }
}



