<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Middleware per auto-scoping del tenant per utenti clienti
 *
 * Se l'utente è un cliente e accede a una route admin che richiede un tenant,
 * questo middleware:
 * 1. Verifica che l'utente abbia accesso al tenant
 * 2. Inietta automaticamente il tenant nella richiesta
 * 3. Filtra i dati solo per quel tenant
 */
class AutoTenantScope
{
    public function handle(Request $request, Closure $next, ?string $role = null)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Debug logging
        \Log::info('AutoTenantScope - Route: '.$request->route()->getName().', User: '.$user->email.', IsAdmin: '.($user->isAdmin() ? 'yes' : 'no'));

        // Se l'utente è admin, procedi normalmente senza scoping
        if ($user->isAdmin()) {
            \Log::info('AutoTenantScope - Admin access, no scoping applied');

            return $next($request);
        }

        // Se l'utente è cliente, applica auto-scoping
        if ($user->tenants()->wherePivot('role', 'customer')->exists()) {
            \Log::info('AutoTenantScope - Customer access, applying scoping');

            return $this->handleCustomerAccess($request, $next, $user);
        }

        // Se l'utente non ha un ruolo riconosciuto, blocca l'accesso
        \Log::warning('AutoTenantScope - Access denied for user: '.$user->email);
        abort(403, 'Accesso non autorizzato.');
    }

    private function handleCustomerAccess(Request $request, Closure $next, $user)
    {
        // Ottieni il tenant dalla route se specificato
        $tenantFromRoute = $request->route('tenant');

        \Log::info('AutoTenantScope - TenantFromRoute: '.($tenantFromRoute ? $tenantFromRoute->id : 'null'));

        if ($tenantFromRoute) {
            // Verifica che l'utente abbia accesso a questo tenant
            if (! $user->tenants()->where('tenant_id', $tenantFromRoute->id)->exists()) {
                \Log::warning('AutoTenantScope - User does not have access to tenant: '.$tenantFromRoute->id);
                abort(403, 'Non hai accesso a questo tenant.');
            }

            \Log::info('AutoTenantScope - Setting scoped_tenant_id to: '.$tenantFromRoute->id);
            // Imposta il tenant corrente nel request per i controller
            $request->merge(['scoped_tenant_id' => $tenantFromRoute->id]);

        } else {
            // Se non c'è un tenant nella route, prendi il primo tenant dell'utente
            $userTenants = $user->tenants()->wherePivot('role', 'customer')->get();

            if ($userTenants->isEmpty()) {
                abort(403, 'Non sei associato a nessun tenant.');
            }

            // Se l'utente ha più tenant, potremmo dover gestire la selezione
            // Per ora, usa il primo tenant
            $firstTenant = $userTenants->first();

            \Log::info('AutoTenantScope - Using first tenant: '.$firstTenant->id);

            // Reindirizza alle route con tenant specifico se necessario
            if ($this->routeRequiresTenant($request)) {
                \Log::info('AutoTenantScope - Redirecting to tenant route');

                return $this->redirectToTenantRoute($request, $firstTenant);
            }

            // Imposta il tenant corrente nel request
            $request->merge(['scoped_tenant_id' => $firstTenant->id]);
        }

        return $next($request);
    }

    private function routeRequiresTenant(Request $request): bool
    {
        $routeName = $request->route()->getName();

        // Route admin che richiedono un tenant specifico
        $tenantRequiredRoutes = [
            'admin.tenants.documents.index',
            'admin.tenants.documents.upload',
            'admin.tenants.knowledge-bases.index',
            'admin.tenants.knowledge-bases.create',
            'admin.tenants.knowledge-bases.store',
            'admin.tenants.knowledge-bases.show',
            'admin.tenants.knowledge-bases.edit',
            'admin.tenants.knowledge-bases.update',
            'admin.tenants.knowledge-bases.destroy',
            'admin.tenants.forms.index',
            'admin.tenants.forms.create',
            'admin.tenants.forms.store',
            'admin.tenants.forms.show',
            'admin.tenants.forms.edit',
            'admin.tenants.forms.update',
            'admin.tenants.forms.destroy',
            'admin.tenants.widget-config.show',
            'admin.tenants.widget-config.update',
            'admin.tenants.widget-config.preview',
        ];

        return in_array($routeName, $tenantRequiredRoutes);
    }

    private function redirectToTenantRoute(Request $request, $tenant)
    {
        $routeName = $request->route()->getName();
        $routeParams = $request->route()->parameters();

        // Aggiungi il tenant ai parametri
        $routeParams['tenant'] = $tenant->id;

        return redirect()->route($routeName, $routeParams);
    }
}
