<?php

namespace App\Jobs;

use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScrapeUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minuti per URL

    public $tries = 2;

    /**
     * Crea una nuova istanza del job.
     */
    public function __construct(
        public string $url,
        public int $depth,
        public int $configId,
        public int $tenantId,
        public string $sessionId
    ) {
        $this->onQueue('scraping');
    }

    /**
     * Esegue il job di scraping per un singolo URL.
     */
    public function handle(WebScraperService $scraper): void
    {
        \Log::info('🚀 [SCRAPE-JOB-START] Inizio scraping URL', [
            'url' => $this->url,
            'depth' => $this->depth,
            'tenant_id' => $this->tenantId,
            'session_id' => $this->sessionId,
        ]);

        $tenant = Tenant::find($this->tenantId);
        $config = ScraperConfig::find($this->configId);

        if (! $tenant) {
            \Log::error('❌ [SCRAPE-JOB-ERROR] Tenant non trovato', [
                'tenant_id' => $this->tenantId,
                'url' => $this->url,
            ]);

            return;
        }

        if (! $config) {
            \Log::error('❌ [SCRAPE-JOB-ERROR] Config non trovata', [
                'config_id' => $this->configId,
                'url' => $this->url,
            ]);

            return;
        }

        try {
            // Imposta session ID per logging coerente
            $scraper->setSessionId($this->sessionId);

            // Scrappa il singolo URL
            $scraper->scrapeSingleUrlForParallel(
                $this->url,
                $this->depth,
                $config,
                $tenant
            );

            \Log::info('✅ [SCRAPE-JOB-SUCCESS] URL scrappato con successo', [
                'url' => $this->url,
                'session_id' => $this->sessionId,
            ]);
        } catch (\Exception $e) {
            \Log::error('❌ [SCRAPE-JOB-ERROR] Errore durante scraping', [
                'url' => $this->url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Rilancia per triggera retry
        }
    }

    /**
     * Gestisce il fallimento del job dopo tutti i retry.
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('💥 [SCRAPE-JOB-FAILED] Job fallito definitivamente', [
            'url' => $this->url,
            'depth' => $this->depth,
            'tenant_id' => $this->tenantId,
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
            'tries' => $this->tries,
        ]);
    }

    /**
     * Ottieni i tag per il job (utile per monitoring in Horizon).
     */
    public function tags(): array
    {
        return [
            'scraping',
            'tenant:'.$this->tenantId,
            'depth:'.$this->depth,
            'session:'.substr($this->sessionId, 0, 8),
        ];
    }
}
