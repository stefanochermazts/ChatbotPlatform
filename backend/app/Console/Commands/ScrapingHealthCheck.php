<?php

namespace App\Console\Commands;

use App\Services\Scraper\ScrapingHealthMonitor;
use Illuminate\Console\Command;

class ScrapingHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraping:health-check 
                           {--tenant= : Check health for specific tenant}
                           {--reset-errors= : Reset error counters for tenant}
                           {--enable-scraping= : Re-enable scraping for tenant}
                           {--cleanup : Clean up old error counters}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor and manage scraping health status';

    /**
     * Execute the console command.
     */
    public function handle(ScrapingHealthMonitor $monitor)
    {
        if ($this->option('cleanup')) {
            $cleaned = $monitor->cleanupOldCounters();
            $this->info("Cleanup completato. Rimossi {$cleaned} contatori obsoleti.");
            return;
        }

        if ($tenantId = $this->option('reset-errors')) {
            $monitor->resetErrorCounters((int) $tenantId);
            $this->info("Contatori errori azzerati per tenant {$tenantId}");
            return;
        }

        if ($tenantId = $this->option('enable-scraping')) {
            $enabled = $monitor->enableScraping((int) $tenantId);
            if ($enabled) {
                $this->info("Scraping riabilitato per tenant {$tenantId}");
            } else {
                $this->warn("Scraping non era disabilitato per tenant {$tenantId}");
            }
            return;
        }

        if ($tenantId = $this->option('tenant')) {
            $this->showTenantHealth($monitor, (int) $tenantId);
        } else {
            $this->showOverallHealth($monitor);
        }
    }

    protected function showTenantHealth(ScrapingHealthMonitor $monitor, int $tenantId)
    {
        $this->info("📊 Stato scraping per tenant {$tenantId}");
        $this->line('');

        $stats = $monitor->getErrorStats($tenantId);

        if ($stats['is_disabled']) {
            $this->error("❌ Scraping DISABILITATO fino a: {$stats['disabled_until']}");
        } else {
            $this->info("✅ Scraping ATTIVO");
        }

        $this->line('');
        $this->info("Errori per categoria:");
        
        $headers = ['Tipo', 'Conteggio'];
        $rows = [];
        
        foreach ($stats as $type => $count) {
            if (in_array($type, ['is_disabled', 'disabled_until'])) continue;
            
            $emoji = $this->getErrorEmoji($type, $count);
            $rows[] = [$emoji . ' ' . ucfirst(str_replace('_', ' ', $type)), $count];
        }

        $this->table($headers, $rows);

        if ($stats['total'] >= 5) {
            $this->warn("⚠️  ATTENZIONE: Soglia errori superata ({$stats['total']}/5)");
        }
    }

    protected function showOverallHealth(ScrapingHealthMonitor $monitor)
    {
        $this->info("🔍 Report completo stato scraping");
        $this->line('');

        $report = $monitor->getHealthReport();

        // Statistiche code
        $this->info("📋 Statistiche Code:");
        $queueData = [
            ['Coda', 'Job in attesa'],
            ['Scraping', $report['queue_stats']['scraping_pending']],
            ['Ingestion', $report['queue_stats']['ingestion_pending']],
            ['Job falliti totali', $report['queue_stats']['failed_total']],
            ['Job scraping falliti', $report['queue_stats']['failed_scraping']],
        ];
        $this->table([], $queueData);

        $this->line('');

        // Errori recenti
        if (!empty($report['failed_jobs']['recent_failures'])) {
            $this->warn("❌ Job falliti recenti:");
            foreach (array_slice($report['failed_jobs']['recent_failures'], 0, 3) as $failure) {
                $this->line("• {$failure['job']} - {$failure['failed_at']}");
                $this->line("  {$failure['error_preview']}");
            }
        } else {
            $this->info("✅ Nessun job fallito recente");
        }

        $this->line('');

        // Categorie errori
        if (!empty($report['failed_jobs']['error_categories'])) {
            $this->info("📊 Categorie errori:");
            foreach ($report['failed_jobs']['error_categories'] as $category => $count) {
                $this->line("• " . ucfirst($category) . ": {$count}");
            }
        }

        $this->line('');
        $this->info("🕒 Report generato: {$report['timestamp']}");
    }

    protected function getErrorEmoji(string $type, int $count): string
    {
        if ($count == 0) return '✅';
        if ($count >= 5) return '🔴';
        if ($count >= 3) return '🟡';
        return '🟠';
    }
}
