<?php

namespace App\Jobs;

use App\Jobs\Concerns\HandlesFailureGracefully;
use App\Models\Tenant;
use App\Services\Scraper\ScrapingHealthMonitor;
use App\Services\Scraper\WebScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWebScrapingJob implements ShouldQueue
{
    use Dispatchable, HandlesFailureGracefully, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 7200; // 2 ore per scraping ricorsivi complessi con JavaScript

    public $backoff = [300, 600, 1200]; // 5min, 10min, 20min retry delays

    public function __construct(
        private readonly int $tenantId,
        private readonly ?int $scraperConfigId = null,
        private readonly ?bool $useParallel = null, // 🆕 Flag per modalità parallela (nullable)
    ) {
        $this->onQueue('scraping');
    }

    public function handle(WebScraperService $scraper, ScrapingHealthMonitor $monitor): void
    {
        // Verifica se lo scraping è disabilitato per questo tenant
        if ($monitor->isScrapingDisabled($this->tenantId)) {
            \Log::warning('Scraping saltato - temporaneamente disabilitato', [
                'tenant_id' => $this->tenantId,
                'scraper_config_id' => $this->scraperConfigId,
            ]);

            return;
        }

        try {
            // 🚀 Determina modalità: parallela (default in produzione) o sequenziale
            $useParallel = $this->useParallel ?? (app()->environment('production') || config('app.scraper_parallel_mode', true));

            if ($useParallel) {
                \Log::info('🚀 [PARALLEL-MODE] Avvio scraping parallelo', [
                    'tenant_id' => $this->tenantId,
                    'scraper_config_id' => $this->scraperConfigId,
                    'environment' => app()->environment(),
                ]);

                $result = $scraper->scrapeForTenantParallel($this->tenantId, $this->scraperConfigId);
            } else {
                \Log::info('📝 [SEQUENTIAL-MODE] Avvio scraping sequenziale', [
                    'tenant_id' => $this->tenantId,
                    'scraper_config_id' => $this->scraperConfigId,
                ]);

                $result = $scraper->scrapeForTenant($this->tenantId, $this->scraperConfigId);
            }

            \Log::info('Web scraping completato', [
                'tenant_id' => $this->tenantId,
                'scraper_config_id' => $this->scraperConfigId,
                'mode' => $useParallel ? 'parallel' : 'sequential',
                'urls_visited' => $result['urls_visited'] ?? 0,
                'documents_saved' => $result['documents_saved'] ?? 0,
            ]);

            // Reset contatori errori se il job ha successo
            if (($result['urls_visited'] ?? 0) > 0) {
                $monitor->resetErrorCounters($this->tenantId);
            }

        } catch (\Exception $e) {
            \Log::error('Errore durante web scraping', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Implementazione richiesta dal trait HandlesFailureGracefully
     */
    protected function getTenantId(): int
    {
        return $this->tenantId;
    }
}
