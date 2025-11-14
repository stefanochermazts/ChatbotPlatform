<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    /**
     * Mostra la dashboard del cliente per un tenant specifico
     */
    public function show(Request $request, Tenant $tenant)
    {
        // Verifica che l'utente abbia accesso a questo tenant
        $user = $request->user();

        if (! $user->tenants()->where('tenant_id', $tenant->id)->exists()) {
            abort(403, 'Non hai accesso a questo tenant.');
        }

        // Carica informazioni base del tenant
        $tenant->load(['knowledgeBases', 'forms', 'widgetConfig']);

        // Statistiche base
        $stats = [
            'knowledge_bases' => $tenant->knowledgeBases()->count(),
            'documents' => \App\Models\Document::where('tenant_id', $tenant->id)->count(),
            'forms' => $tenant->forms()->count(),
            'form_submissions' => $tenant->formSubmissions()->count(),
        ];

        // Ruolo dell'utente per questo tenant
        $userRole = $user->getRoleForTenant($tenant->id);

        return view('customer.dashboard', compact('tenant', 'stats', 'userRole'));
    }

    /**
     * Redirect alla dashboard del primo tenant dell'utente
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $firstTenant = $user->tenants()->first();

        if (! $firstTenant) {
            // Se l'utente non ha tenant associati, mostra una pagina di errore
            return view('customer.no-tenants');
        }

        return redirect()->route('tenant.dashboard', $firstTenant->id);
    }
}
