<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            abort(401, 'Utente non autenticato.');
        }

        // Ottieni il tenant dalla route
        $tenant = $request->route('tenant');
        
        if (!$tenant) {
            abort(404, 'Tenant non trovato.');
        }

        // Se l'ID è passato come parametro, recupera il modello
        if (is_numeric($tenant)) {
            $tenant = Tenant::findOrFail($tenant);
            $request->route()->setParameter('tenant', $tenant);
        }

        // Gli admin possono accedere a tutti i tenant
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Verifica che l'utente abbia accesso al tenant
        if (!$user->tenants()->where('tenant_id', $tenant->id)->exists()) {
            abort(403, 'Non hai accesso a questo tenant.');
        }

        return $next($request);
    }
}
