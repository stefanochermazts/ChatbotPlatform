<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Se l'utente è un cliente, reindirizza alla dashboard del tenant
        if (!$user->isAdmin()) {
            $scopedTenantId = $request->get('scoped_tenant_id');
            
            if ($scopedTenantId) {
                $tenant = Tenant::find($scopedTenantId);
                return redirect()->route('tenant.dashboard', $tenant);
            }
            
            // Fallback: prendi il primo tenant dell'utente
            $firstTenant = $user->tenants()->wherePivot('role', 'customer')->first();
            if ($firstTenant) {
                return redirect()->route('tenant.dashboard', $firstTenant);
            }
            
            abort(403, 'Non sei associato a nessun tenant.');
        }
        
        // Dashboard admin normale
        $tenantCount = Tenant::count();
        
        // Carica configurazioni RAG per visualizzazione
        $rag = [
            'features' => config('rag.features', []),
            'hybrid' => config('rag.hybrid', []),
            'reranker' => config('rag.reranker', []),
            'multiquery' => config('rag.multiquery', []),
            'context' => config('rag.context', []),
            'cache' => config('rag.cache', []),
            'telemetry' => config('rag.telemetry', []),
        ];
        
        return view('admin.dashboard', compact('tenantCount', 'rag'));
    }
}

