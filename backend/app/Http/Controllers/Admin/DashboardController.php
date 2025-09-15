<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Services\RAG\TenantRagConfigService;

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
        $tenants = Tenant::orderBy('name')->get();

        // Selezione opzionale di un tenant per visualizzare la config per-tenant
        $selectedTenantId = (int) ($request->query('tenant_id') ?: 0);
        $selectedTenant = $selectedTenantId ? Tenant::find($selectedTenantId) : null;

        if ($selectedTenant) {
            // Configurazione per-tenant (unione di defaults + profilo + overrides del tenant)
            $cfgSvc = app(TenantRagConfigService::class);
            $tenantCfg = $cfgSvc->getConfig($selectedTenant->id);
            $rag = [
                'features' => $tenantCfg['features'] ?? config('rag.features', []),
                'hybrid' => $tenantCfg['hybrid'] ?? config('rag.hybrid', []),
                'reranker' => $tenantCfg['reranker'] ?? config('rag.reranker', []),
                'multiquery' => $tenantCfg['multiquery'] ?? config('rag.multiquery', []),
                'context' => $tenantCfg['context'] ?? config('rag.context', []),
                'cache' => $tenantCfg['cache'] ?? config('rag.cache', []),
                'telemetry' => $tenantCfg['telemetry'] ?? config('rag.telemetry', []),
            ];
            $ragScope = 'tenant';
        } else {
            // Configurazione globale (defaults)
            $rag = [
                'features' => config('rag.features', []),
                'hybrid' => config('rag.hybrid', []),
                'reranker' => config('rag.reranker', []),
                'multiquery' => config('rag.multiquery', []),
                'context' => config('rag.context', []),
                'cache' => config('rag.cache', []),
                'telemetry' => config('rag.telemetry', []),
            ];
            $ragScope = 'global';
        }
        
        return view('admin.dashboard', compact('tenantCount', 'tenants', 'rag', 'ragScope', 'selectedTenant'));
    }
}

