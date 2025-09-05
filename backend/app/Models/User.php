<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'is_active',
        'last_login_at',
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
}
