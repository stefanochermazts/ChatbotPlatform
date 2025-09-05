<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppConfigController extends Controller
{
    /**
     * Lista delle configurazioni WhatsApp per tutti i tenant
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // Auto-scoping per clienti
        if (!$user->isAdmin()) {
            $tenants = $user->tenants()->wherePivot('role', 'customer')->orderBy('name')->get();
        } else {
            // Admin vede tutti i tenant
            $tenants = Tenant::orderBy('name')->get();
        }
        
        // Arricchisci i tenant con info WhatsApp
        $tenants = $tenants->map(function ($tenant) {
            $config = $tenant->getWhatsAppConfig();
            $tenant->whatsapp_status = $config['is_active'] ? 'active' : 'inactive';
            $tenant->whatsapp_number = $config['phone_number'];
            $tenant->messages_count = $tenant->vonageMessages()->count();
            return $tenant;
        });

        return view('admin.whatsapp-config.index', compact('tenants'));
    }

    /**
     * Mostra la configurazione WhatsApp per un tenant
     */
    public function show(Tenant $tenant)
    {
        $this->checkTenantAccess($tenant);
        
        $config = $tenant->getWhatsAppConfig();
        
        return view('admin.whatsapp-config.show', compact('tenant', 'config'));
    }

    /**
     * Aggiorna la configurazione WhatsApp
     */
    public function update(Request $request, Tenant $tenant)
    {
        $this->checkTenantAccess($tenant);
        
        $validated = $request->validate([
            'phone_number' => [
                'nullable', 
                'string', 
                'regex:/^\+?[1-9]\d{1,14}$/',
                Rule::unique('tenants', 'whatsapp_config->phone_number')->ignore($tenant->id)
            ],
            'is_active' => 'boolean',
            'welcome_message' => 'nullable|string|max:1000',
            'business_hours.enabled' => 'boolean',
            'business_hours.timezone' => 'nullable|string|in:' . implode(',', timezone_identifiers_list()),
            'business_hours.monday.start' => 'nullable|date_format:H:i',
            'business_hours.monday.end' => 'nullable|date_format:H:i',
            'business_hours.tuesday.start' => 'nullable|date_format:H:i',
            'business_hours.tuesday.end' => 'nullable|date_format:H:i',
            'business_hours.wednesday.start' => 'nullable|date_format:H:i',
            'business_hours.wednesday.end' => 'nullable|date_format:H:i',
            'business_hours.thursday.start' => 'nullable|date_format:H:i',
            'business_hours.thursday.end' => 'nullable|date_format:H:i',
            'business_hours.friday.start' => 'nullable|date_format:H:i',
            'business_hours.friday.end' => 'nullable|date_format:H:i',
            'business_hours.saturday.start' => 'nullable|date_format:H:i',
            'business_hours.saturday.end' => 'nullable|date_format:H:i',
            'business_hours.sunday.closed' => 'boolean',
            'business_hours.sunday.start' => 'nullable|date_format:H:i',
            'business_hours.sunday.end' => 'nullable|date_format:H:i',
            'auto_response.enabled' => 'boolean',
            'auto_response.response_delay' => 'nullable|integer|min:0|max:60',
        ]);

        // Prepara la configurazione
        $currentConfig = $tenant->getWhatsAppConfig();
        
        $whatsappConfig = [
            'phone_number' => $validated['phone_number'] ?? null,
            'is_active' => $validated['is_active'] ?? false,
            'welcome_message' => $validated['welcome_message'] ?? $currentConfig['welcome_message'],
            'business_hours' => [
                'enabled' => $validated['business_hours']['enabled'] ?? false,
                'timezone' => $validated['business_hours']['timezone'] ?? 'Europe/Rome',
                'monday' => [
                    'start' => $validated['business_hours']['monday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['monday']['end'] ?? '18:00'
                ],
                'tuesday' => [
                    'start' => $validated['business_hours']['tuesday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['tuesday']['end'] ?? '18:00'
                ],
                'wednesday' => [
                    'start' => $validated['business_hours']['wednesday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['wednesday']['end'] ?? '18:00'
                ],
                'thursday' => [
                    'start' => $validated['business_hours']['thursday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['thursday']['end'] ?? '18:00'
                ],
                'friday' => [
                    'start' => $validated['business_hours']['friday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['friday']['end'] ?? '18:00'
                ],
                'saturday' => [
                    'start' => $validated['business_hours']['saturday']['start'] ?? '09:00',
                    'end' => $validated['business_hours']['saturday']['end'] ?? '13:00'
                ],
                'sunday' => [
                    'closed' => $validated['business_hours']['sunday']['closed'] ?? true,
                    'start' => $validated['business_hours']['sunday']['start'] ?? null,
                    'end' => $validated['business_hours']['sunday']['end'] ?? null
                ]
            ],
            'auto_response' => [
                'enabled' => $validated['auto_response']['enabled'] ?? true,
                'response_delay' => $validated['auto_response']['response_delay'] ?? 1
            ]
        ];

        // Aggiorna il tenant
        $tenant->update(['whatsapp_config' => $whatsappConfig]);

        return redirect()
            ->route('admin.whatsapp-config.show', $tenant)
            ->with('ok', 'Configurazione WhatsApp aggiornata con successo!');
    }

    /**
     * Test della configurazione WhatsApp
     */
    public function test(Request $request, Tenant $tenant)
    {
        $this->checkTenantAccess($tenant);
        
        if (!$tenant->hasWhatsAppConfig()) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp non configurato per questo tenant'
            ], 400);
        }

        $config = $tenant->getWhatsAppConfig();
        
        // Verifica configurazione Vonage
        $vonageApiKey = config('services.vonage.api_key');
        $vonageApiSecret = config('services.vonage.api_secret');
        
        if (!$vonageApiKey || !$vonageApiSecret) {
            return response()->json([
                'success' => false,
                'message' => 'Credenziali Vonage non configurate'
            ], 400);
        }

        try {
            // Test API Vonage (verifica account)
            $response = \Illuminate\Support\Facades\Http::withBasicAuth($vonageApiKey, $vonageApiSecret)
                ->get('https://rest.nexmo.com/account/get-balance');

            if ($response->successful()) {
                $balance = $response->json();
                return response()->json([
                    'success' => true,
                    'message' => 'Configurazione WhatsApp valida',
                    'details' => [
                        'phone_number' => $config['phone_number'],
                        'vonage_balance' => $balance['value'] ?? 'N/A',
                        'webhook_urls' => [
                            'inbound' => config('services.vonage.webhook_base_url') . '/api/v1/vonage/whatsapp/inbound',
                            'status' => config('services.vonage.webhook_base_url') . '/api/v1/vonage/whatsapp/status'
                        ]
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Errore nella connessione a Vonage: ' . $response->body()
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore nel test: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Controlla se l'utente ha accesso al tenant
     */
    private function checkTenantAccess(Tenant $tenant)
    {
        $user = auth()->user();
        
        if (!$user->isAdmin()) {
            $userTenantIds = $user->tenants()->wherePivot('role', 'customer')->pluck('tenant_id')->toArray();
            if (!in_array($tenant->id, $userTenantIds)) {
                abort(403, 'Non hai accesso a questo tenant.');
            }
        }
    }
}
