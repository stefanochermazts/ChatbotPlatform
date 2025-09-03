<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunWebScrapingJob;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Http\Request;

class ScraperAdminController extends Controller
{
    public function edit(Tenant $tenant)
    {
        $configs = ScraperConfig::where('tenant_id', $tenant->id)->orderBy('id')->get();
        $config = $configs->first() ?? new ScraperConfig(['tenant_id' => $tenant->id]);
        return view('admin.scraper.edit', compact('tenant', 'config', 'configs'));
    }

    public function update(Request $request, Tenant $tenant)
    {
        // 🐛 DEBUG: Log tutti i dati ricevuti per tracciare il problema
        \Log::info('ScraperAdmin: Dati ricevuti nel form', [
            'tenant_id' => $tenant->id,
            'form_data' => $request->all(),
            'id_field' => $request->get('id'),
            'has_id' => $request->has('id'),
            'id_empty' => empty($request->get('id')),
            // 🐛 DEBUG: Valori specifici dei checkbox
            'checkbox_values' => [
                'enabled' => $request->boolean('enabled'),
                'render_js' => $request->boolean('render_js'),
                'respect_robots' => $request->boolean('respect_robots'),
                'skip_known_urls' => $request->boolean('skip_known_urls'),
            ]
        ]);

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:scraper_configs,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'seed_urls' => ['nullable', 'string'],
            'allowed_domains' => ['nullable', 'string'],
            'sitemap_urls' => ['nullable', 'string'],
            'include_patterns' => ['nullable', 'string'],
            'exclude_patterns' => ['nullable', 'string'],
            'link_only_patterns' => ['nullable', 'string'],
            'max_depth' => ['nullable', 'integer', 'min:0', 'max:10'],
            'render_js' => ['nullable', 'boolean'],
            'respect_robots' => ['nullable', 'boolean'],
            'rate_limit_rps' => ['nullable', 'integer', 'min:0', 'max:10'],
            'auth_headers' => ['nullable', 'string'],
            'target_knowledge_base_id' => ['nullable', 'integer', 'exists:knowledge_bases,id'],
            'enabled' => ['nullable', 'boolean'],
            'interval_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'skip_known_urls' => ['nullable', 'boolean'],
            'recrawl_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $payload = [
            'name' => $data['name'] ?? 'Scraper',
            'seed_urls' => $this->toArray($data['seed_urls'] ?? ''),
            'allowed_domains' => $this->toArray($data['allowed_domains'] ?? ''),
            'sitemap_urls' => $this->toArray($data['sitemap_urls'] ?? ''),
            'include_patterns' => $this->toArray($data['include_patterns'] ?? ''),
            'exclude_patterns' => $this->toArray($data['exclude_patterns'] ?? ''),
            'link_only_patterns' => $this->toArray($data['link_only_patterns'] ?? ''),
            'max_depth' => (int) ($data['max_depth'] ?? 2),
            'render_js' => $request->boolean('render_js'),  // ✅ Fix checkbox
            'respect_robots' => $request->boolean('respect_robots'),  // ✅ Fix checkbox  
            'rate_limit_rps' => (int) ($data['rate_limit_rps'] ?? 1),
            'auth_headers' => $this->parseHeaders($data['auth_headers'] ?? ''),
            'target_knowledge_base_id' => isset($data['target_knowledge_base_id']) && $data['target_knowledge_base_id'] !== ''
                ? (int) $data['target_knowledge_base_id']
                : null,
            'enabled' => $request->boolean('enabled'),  // ✅ Fix checkbox
            'interval_minutes' => isset($data['interval_minutes']) && $data['interval_minutes'] !== '' ? (int) $data['interval_minutes'] : null,
            'skip_known_urls' => $request->boolean('skip_known_urls'),  // ✅ Fix checkbox
            'recrawl_days' => isset($data['recrawl_days']) && $data['recrawl_days'] !== '' ? (int) $data['recrawl_days'] : null,
        ];

        if (!empty($data['id'])) {
            // UPDATE: Aggiorna configurazione esistente
            $config = ScraperConfig::where('tenant_id', $tenant->id)->findOrFail((int) $data['id']);
            \Log::info('ScraperAdmin: Aggiornamento configurazione esistente', [
                'tenant_id' => $tenant->id,
                'config_id' => $data['id'],
                'config_name' => $payload['name']
            ]);
            $config->fill($payload + ['tenant_id' => $tenant->id]);
            $config->save();
        } else {
            // CREATE: Crea nuova configurazione
            \Log::info('ScraperAdmin: Creazione nuova configurazione', [
                'tenant_id' => $tenant->id,
                'config_name' => $payload['name'],
                'reason' => 'ID non presente nel form'
            ]);
            $config = new ScraperConfig($payload + ['tenant_id' => $tenant->id]);
            $config->save();
        }

        return back()->with('ok', 'Configurazione scraper aggiornata');
    }

    public function destroy(Tenant $tenant, ScraperConfig $scraperConfig)
    {
        // Verifica che lo scraper appartenga al tenant
        if ($scraperConfig->tenant_id !== $tenant->id) {
            abort(403, 'Non autorizzato');
        }

        $scraperName = $scraperConfig->name;
        $scraperConfig->delete();

        return back()->with('ok', "Scraper '{$scraperName}' eliminato con successo");
    }

    public function run(Tenant $tenant)
    {
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        
        if (!$config || empty($config->seed_urls)) {
            return back()->with('error', 'Configurazione scraper non trovata o incompleta');
        }

        // Avvia scraping in background
        RunWebScrapingJob::dispatch($tenant->id, (int) request('id'));
        
        return back()->with('ok', 'Scraping avviato in background. Controlla i log per il progresso.');
    }

    public function runSync(Tenant $tenant, WebScraperService $scraper)
    {
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        
        if (!$config || empty($config->seed_urls)) {
            return back()->with('error', 'Configurazione scraper non trovata o incompleta');
        }

        try {
            $result = $scraper->scrapeForTenant($tenant->id, (int) request('id'));
            
            if (isset($result['error'])) {
                return back()->with('error', $result['error']);
            }
            
            $message = "Scraping completato: {$result['urls_visited']} URLs visitati, {$result['documents_saved']} documenti processati";
            if (isset($result['stats'])) {
                $stats = $result['stats'];
                $message .= " (Nuovi: {$stats['new']}, Aggiornati: {$stats['updated']}, Invariati: {$stats['skipped']})";
            }
            return back()->with('ok', $message);
            
        } catch (\Exception $e) {
            return back()->with('error', 'Errore durante scraping: ' . $e->getMessage());
        }
    }

    private function toArray(string $multiline): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $multiline) ?: [])));
    }

    private function parseHeaders(string $multiline): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', $multiline) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $headers[$k] = $v;
        }
        return $headers;
    }

    /**
     * 🎯 NUOVA FUNZIONALITÀ: Scraping di un singolo URL tramite interfaccia admin
     */
    public function scrapeSingleUrl(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'url' => ['required', 'url'],
            'force' => ['sometimes', 'boolean'],
            'knowledge_base_id' => ['nullable', 'integer', 'exists:knowledge_bases,id']
        ]);

        try {
            $scraperService = new WebScraperService();
            
            $result = $scraperService->scrapeSingleUrl(
                $data['tenant_id'],
                $data['url'],
                $data['force'] ?? false,
                $data['knowledge_base_id'] ?? null
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Scraping completato con successo!',
                    'data' => [
                        'url' => $result['url'],
                        'saved_count' => $result['saved_count'],
                        'stats' => $result['stats'],
                        'document' => $result['document'] ?? null
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'existing_document' => $result['existing_document'] ?? null
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante lo scraping: ' . $e->getMessage()
            ], 500);
        }
    }
}

