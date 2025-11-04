<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Ruoli disponibili
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_AGENT = 'agent';

    public const ROLES = [
        self::ROLE_ADMIN => 'Amministratore',
        self::ROLE_CUSTOMER => 'Cliente',
        self::ROLE_AGENT => 'Agente',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'is_active',
        'last_login_at',
        // 👨‍💼 Operator fields per Agent Console
        'user_type',
        'is_operator',
        'operator_status',
        'operator_skills',
        'operator_permissions',
        'max_concurrent_conversations',
        'current_conversations',
        'work_schedule',
        'timezone',
        'last_seen_at',
        'status_updated_at',
        'total_conversations_handled',
        'average_response_time_minutes',
        'average_resolution_time_minutes',
        'customer_satisfaction_avg',
        'console_preferences',
        'notification_settings',
        'operator_metadata'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            // 👨‍💼 Operator casts per Agent Console
            'is_operator' => 'boolean',
            'operator_skills' => 'array',
            'operator_permissions' => 'array',
            'max_concurrent_conversations' => 'integer',
            'current_conversations' => 'integer',
            'work_schedule' => 'array',
            'last_seen_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'total_conversations_handled' => 'integer',
            'average_response_time_minutes' => 'decimal:2',
            'average_resolution_time_minutes' => 'decimal:2',
            'customer_satisfaction_avg' => 'decimal:2',
            'console_preferences' => 'array',
            'notification_settings' => 'array',
            'operator_metadata' => 'array'
        ];
    }

    /**
     * Relazione con i tenant
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Verifica se l'utente ha un ruolo specifico per un tenant
     */
    public function hasRoleForTenant(string $role, int $tenantId): bool
    {
        return $this->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('role', $role)
            ->exists();
    }

    /**
     * Verifica se l'utente è admin
     */
    public function isAdmin(): bool
    {
        return $this->tenants()
            ->wherePivot('role', self::ROLE_ADMIN)
            ->exists();
    }

    /**
     * Verifica se l'utente è admin per un tenant specifico
     */
    public function isAdminForTenant(int $tenantId): bool
    {
        return $this->hasRoleForTenant(self::ROLE_ADMIN, $tenantId);
    }

    /**
     * Verifica se l'utente è cliente per un tenant specifico
     */
    public function isCustomerForTenant(int $tenantId): bool
    {
        return $this->hasRoleForTenant(self::ROLE_CUSTOMER, $tenantId);
    }

    /**
     * Ottieni tutti i tenant per cui l'utente è admin
     */
    public function adminTenants()
    {
        return $this->tenants()->wherePivot('role', self::ROLE_ADMIN);
    }

    /**
     * Ottieni tutti i tenant per cui l'utente è cliente
     */
    public function customerTenants()
    {
        return $this->tenants()->wherePivot('role', self::ROLE_CUSTOMER);
    }

    /**
     * Ottieni il ruolo per un tenant specifico
     */
    public function getRoleForTenant(int $tenantId): ?string
    {
        $tenant = $this->tenants()->where('tenant_id', $tenantId)->first();
        return $tenant?->pivot?->role;
    }

    /**
     * Scope per utenti attivi
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope per utenti con email verificata
     */
    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    // 🎯 Agent Console Relationships

    /**
     * Conversazioni assegnate come operatore
     */
    public function assignedConversations()
    {
        return $this->hasMany(ConversationSession::class, 'assigned_operator_id');
    }

    /**
     * Messaggi inviati come operatore
     */
    public function operatorMessages()
    {
        return $this->hasMany(ConversationMessage::class, 'sender_id')
                   ->where('sender_type', 'operator');
    }

    /**
     * Richieste di handoff assegnate
     */
    public function assignedHandoffRequests()
    {
        return $this->hasMany(HandoffRequest::class, 'assigned_operator_id');
    }

    // 🎯 Operator Scopes

    /**
     * Scope per operatori
     */
    public function scopeOperators($query)
    {
        return $query->where('is_operator', true);
    }

    /**
     * Scope per operatori disponibili
     */
    public function scopeAvailableOperators($query)
    {
        return $query->where('is_operator', true)
                    ->where('operator_status', 'available');
    }

    /**
     * Scope per operatori online
     */
    public function scopeOnlineOperators($query)
    {
        return $query->where('is_operator', true)
                    ->whereIn('operator_status', ['available', 'busy'])
                    ->where('last_seen_at', '>=', now()->subMinutes(5));
    }

    // 🔧 Operator Helper Methods

    /**
     * Verifica se l'utente è un operatore
     */
    public function isOperator(): bool
    {
        return $this->is_operator === true;
    }

    /**
     * Verifica se l'operatore è disponibile
     */
    public function isAvailable(): bool
    {
        return $this->isOperator() && $this->operator_status === 'available';
    }

    /**
     * Verifica se l'operatore è online
     */
    public function isOnline(): bool
    {
        return $this->isOperator() && 
               in_array($this->operator_status, ['available', 'busy']) &&
               $this->last_seen_at && 
               $this->last_seen_at->diffInMinutes(now()) <= 5;
    }

    /**
     * Verifica se l'operatore può gestire nuove conversazioni
     */
    public function canTakeNewConversation(): bool
    {
        return $this->isAvailable() && 
               $this->current_conversations < $this->max_concurrent_conversations;
    }

    /**
     * Aggiorna lo status dell'operatore
     */
    public function updateOperatorStatus(string $status): bool
    {
        if (!$this->isOperator()) return false;

        return $this->update([
            'operator_status' => $status,
            'status_updated_at' => now(),
            'last_seen_at' => now()
        ]);
    }

    /**
     * Aggiorna l'ultimo accesso
     */
    public function updateLastSeen(): bool
    {
        return $this->update(['last_seen_at' => now()]);
    }

    /**
     * Incrementa il contatore conversazioni correnti
     */
    public function incrementCurrentConversations(): void
    {
        $this->increment('current_conversations');
    }

    /**
     * Decrementa il contatore conversazioni correnti
     */
    public function decrementCurrentConversations(): void
    {
        if ($this->current_conversations > 0) {
            $this->decrement('current_conversations');
        }
    }

    /**
     * Ottieni le conversazioni attive dell'operatore
     */
    public function getActiveConversations()
    {
        return $this->assignedConversations()
                   ->whereIn('status', ['active', 'assigned'])
                   ->with(['messages' => function($query) {
                       $query->latest('sent_at')->limit(1);
                   }]);
    }

    /**
     * Ottieni le richieste di handoff pendenti per questo operatore
     */
    public function getPendingHandoffRequests()
    {
        return $this->assignedHandoffRequests()
                   ->whereIn('status', ['assigned', 'in_progress'])
                   ->orderBy('priority')
                   ->orderBy('requested_at');
    }

    /**
     * Ottieni le competenze come array
     */
    public function getSkillsArray(): array
    {
        return is_array($this->operator_skills) ? $this->operator_skills : [];
    }

    /**
     * Verifica se l'operatore ha una specifica competenza
     */
    public function hasSkill(string $skill): bool
    {
        return in_array($skill, $this->getSkillsArray());
    }

    /**
     * Ottieni le metriche dell'operatore
     */
    public function getOperatorMetrics(): array
    {
        return [
            'total_conversations' => $this->total_conversations_handled,
            'current_conversations' => $this->current_conversations,
            'max_conversations' => $this->max_concurrent_conversations,
            'avg_response_time' => $this->average_response_time_minutes,
            'avg_resolution_time' => $this->average_resolution_time_minutes,
            'customer_satisfaction' => $this->customer_satisfaction_avg,
            'utilization_rate' => $this->max_concurrent_conversations > 0 
                ? ($this->current_conversations / $this->max_concurrent_conversations) * 100 
                : 0
        ];
    }
}
