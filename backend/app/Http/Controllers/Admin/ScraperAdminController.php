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
        $config = ScraperConfig::firstOrNew(['tenant_id' => $tenant->id]);
        return view('admin.scraper.edit', compact('tenant', 'config'));
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'seed_urls' => ['nullable', 'string'],
            'allowed_domains' => ['nullable', 'string'],
            'sitemap_urls' => ['nullable', 'string'],
            'include_patterns' => ['nullable', 'string'],
            'exclude_patterns' => ['nullable', 'string'],
            'max_depth' => ['nullable', 'integer', 'min:0', 'max:10'],
            'render_js' => ['nullable', 'boolean'],
            'respect_robots' => ['nullable', 'boolean'],
            'rate_limit_rps' => ['nullable', 'integer', 'min:0', 'max:10'],
            'auth_headers' => ['nullable', 'string'],
        ]);

        $payload = [
            'seed_urls' => $this->toArray($data['seed_urls'] ?? ''),
            'allowed_domains' => $this->toArray($data['allowed_domains'] ?? ''),
            'sitemap_urls' => $this->toArray($data['sitemap_urls'] ?? ''),
            'include_patterns' => $this->toArray($data['include_patterns'] ?? ''),
            'exclude_patterns' => $this->toArray($data['exclude_patterns'] ?? ''),
            'max_depth' => (int) ($data['max_depth'] ?? 2),
            'render_js' => (bool) ($data['render_js'] ?? false),
            'respect_robots' => (bool) ($data['respect_robots'] ?? true),
            'rate_limit_rps' => (int) ($data['rate_limit_rps'] ?? 1),
            'auth_headers' => $this->parseHeaders($data['auth_headers'] ?? ''),
        ];

        $config = ScraperConfig::firstOrNew(['tenant_id' => $tenant->id]);
        $config->fill($payload + ['tenant_id' => $tenant->id]);
        $config->save();

        return back()->with('ok', 'Configurazione scraper aggiornata');
    }

    public function run(Tenant $tenant)
    {
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        
        if (!$config || empty($config->seed_urls)) {
            return back()->with('error', 'Configurazione scraper non trovata o incompleta');
        }

        // Avvia scraping in background
        RunWebScrapingJob::dispatch($tenant->id);
        
        return back()->with('ok', 'Scraping avviato in background. Controlla i log per il progresso.');
    }

    public function runSync(Tenant $tenant, WebScraperService $scraper)
    {
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        
        if (!$config || empty($config->seed_urls)) {
            return back()->with('error', 'Configurazione scraper non trovata o incompleta');
        }

        try {
            $result = $scraper->scrapeForTenant($tenant->id);
            
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
}

