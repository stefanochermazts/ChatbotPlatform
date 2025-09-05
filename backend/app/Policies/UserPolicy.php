<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determina se l'utente può visualizzare altri utenti
     */
    public function viewAny(User $user): bool
    {
        // Solo gli admin possono visualizzare la lista utenti
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può visualizzare un altro utente
     */
    public function view(User $user, User $model): bool
    {
        // Gli utenti possono vedere se stessi
        if ($user->id === $model->id) {
            return true;
        }

        // Gli admin possono vedere tutti gli utenti
        if ($user->isAdmin()) {
            return true;
        }

        // I clienti possono vedere solo utenti dello stesso tenant
        $userTenants = $user->tenants()->pluck('tenant_id')->toArray();
        $modelTenants = $model->tenants()->pluck('tenant_id')->toArray();
        
        return !empty(array_intersect($userTenants, $modelTenants));
    }

    /**
     * Determina se l'utente può creare un nuovo utente
     */
    public function create(User $user): bool
    {
        // Solo gli admin possono creare utenti
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può aggiornare un altro utente
     */
    public function update(User $user, User $model): bool
    {
        // Gli utenti possono modificare se stessi (limitato)
        if ($user->id === $model->id) {
            return true;
        }

        // Solo gli admin possono modificare altri utenti
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può eliminare un altro utente
     */
    public function delete(User $user, User $model): bool
    {
        // Non si può eliminare se stessi
        if ($user->id === $model->id) {
            return false;
        }

        // Solo gli admin possono eliminare utenti
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può gestire i ruoli di un altro utente
     */
    public function manageRoles(User $user, User $model): bool
    {
        // Non si possono modificare i propri ruoli
        if ($user->id === $model->id) {
            return false;
        }

        // Solo gli admin possono gestire i ruoli
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può gestire le associazioni tenant (senza modificare ruoli)
     */
    public function manageTenantAssociations(User $user, User $model): bool
    {
        // Gli admin possono gestire le associazioni tenant di tutti
        return $user->isAdmin();
    }

    /**
     * Determina se l'utente può accedere al pannello admin
     */
    public function accessAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active && $user->hasVerifiedEmail();
    }

    /**
     * Determina se l'utente può gestire un tenant specifico
     */
    public function manageTenant(User $user, int $tenantId): bool
    {
        return $user->isAdminForTenant($tenantId) || $user->isCustomerForTenant($tenantId);
    }

    /**
     * Determina se l'utente può invitare altri utenti per un tenant
     */
    public function inviteUser(User $user, int $tenantId): bool
    {
        // Solo admin globali o admin del tenant possono invitare
        return $user->isAdmin() || $user->isAdminForTenant($tenantId);
    }
}
