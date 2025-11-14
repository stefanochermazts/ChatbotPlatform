<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TenantPolicy
{
    use HandlesAuthorization;

    /**
     * Determina se l'utente può visualizzare tutti i tenant
     */
    public function viewAny(User $user): bool
    {
        // Solo gli admin possono vedere tutti i tenant
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può visualizzare un tenant specifico
     */
    public function view(User $user, Tenant $tenant): bool
    {
        // Gli admin possono vedere tutti i tenant
        if ($user->isAdmin()) {
            return true;
        }

        // Gli utenti possono vedere solo i loro tenant
        return $user->tenants()->where('tenant_id', $tenant->id)->exists();
    }

    /**
     * Determina se l'utente può creare un nuovo tenant
     */
    public function create(User $user): bool
    {
        // Solo gli admin possono creare tenant
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può aggiornare un tenant
     */
    public function update(User $user, Tenant $tenant): bool
    {
        // Gli admin possono modificare tutti i tenant
        if ($user->isAdmin()) {
            return true;
        }

        // I clienti possono modificare solo i loro tenant (limitato)
        return $user->isCustomerForTenant($tenant->id);
    }

    /**
     * Determina se l'utente può eliminare un tenant
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        // Solo gli admin possono eliminare tenant
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può gestire gli utenti di un tenant
     */
    public function manageUsers(User $user, Tenant $tenant): bool
    {
        // Gli admin possono gestire utenti di tutti i tenant
        if ($user->isAdmin()) {
            return true;
        }

        // I clienti non possono gestire utenti
        return false;
    }

    /**
     * Determina se l'utente può accedere alle funzionalità del tenant
     */
    public function access(User $user, Tenant $tenant): bool
    {
        // L'utente deve essere attivo e avere email verificata
        if (! $user->is_active || ! $user->hasVerifiedEmail()) {
            return false;
        }

        // Gli admin possono accedere a tutti i tenant
        if ($user->isAdmin()) {
            return true;
        }

        // Gli altri utenti possono accedere solo ai loro tenant
        return $user->tenants()->where('tenant_id', $tenant->id)->exists();
    }
}
