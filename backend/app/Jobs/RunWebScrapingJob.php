<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWebScrapingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly ?int $scraperConfigId = null,
    ) {
        $this->onQueue('scraping');
    }

    public function handle(WebScraperService $scraper): void
    {
        try {
            $result = $scraper->scrapeForTenant($this->tenantId, $this->scraperConfigId);
            
            \Log::info("Web scraping completato", [
                'tenant_id' => $this->tenantId,
                'scraper_config_id' => $this->scraperConfigId,
                'urls_visited' => $result['urls_visited'] ?? 0,
                'documents_saved' => $result['documents_saved'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Errore durante web scraping", [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error("Job web scraping fallito", [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage()
        ]);
    }
}

