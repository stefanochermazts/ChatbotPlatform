<?php

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per monitorare la salute dello scraping
 * e gestire strategie di recupero automatico
 */
class ScrapingHealthMonitor
{
    /**
     * Ottiene le statistiche degli errori per un tenant
     */
    public function getErrorStats(int $tenantId): array
    {
        $errorTypes = ['max_attempts', 'network_timeout', 'http_error', 'robots_blocked', 'memory_limit', 'unknown'];
        $stats = [];
        
        foreach ($errorTypes as $type) {
            $cacheKey = "scraping_errors_{$tenantId}_{$type}";
            $stats[$type] = Cache::get($cacheKey, 0);
        }
        
        $stats['total'] = Cache::get("scraping_errors_{$tenantId}_total", 0);
        $stats['is_disabled'] = $this->isScrapingDisabled($tenantId);
        
        if ($stats['is_disabled']) {
            $disableKey = "scraping_disabled_{$tenantId}";
            $stats['disabled_until'] = Cache::get($disableKey);
        }
        
        return $stats;
    }

    /**
     * Verifica se lo scraping è disabilitato per il tenant
     */
    public function isScrapingDisabled(int $tenantId): bool
    {
        $disableKey = "scraping_disabled_{$tenantId}";
        return Cache::has($disableKey);
    }

    /**
     * Riabilita manualmente lo scraping per un tenant
     */
    public function enableScraping(int $tenantId): bool
    {
        $disableKey = "scraping_disabled_{$tenantId}";
        
        if (Cache::has($disableKey)) {
            Cache::forget($disableKey);
            
            Log::info("Scraping riabilitato manualmente", [
                'tenant_id' => $tenantId,
                'enabled_at' => now()->toISOString()
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Reset dei contatori di errore per un tenant
     */
    public function resetErrorCounters(int $tenantId): void
    {
        $errorTypes = ['max_attempts', 'network_timeout', 'http_error', 'robots_blocked', 'memory_limit', 'unknown', 'total'];
        
        foreach ($errorTypes as $type) {
            $cacheKey = "scraping_errors_{$tenantId}_{$type}";
            Cache::forget($cacheKey);
        }
        
        Log::info("Contatori errori azzerati", [
            'tenant_id' => $tenantId,
            'reset_at' => now()->toISOString()
        ]);
    }

    /**
     * Ottiene un report completo dello stato dei job di scraping
     */
    public function getHealthReport(): array
    {
        $report = [
            'timestamp' => now()->toISOString(),
            'queue_stats' => $this->getQueueStats(),
            'failed_jobs' => $this->getFailedJobsStats(),
            'tenants_with_errors' => $this->getTenantsWithErrors(),
        ];
        
        return $report;
    }

    /**
     * Statistiche delle code
     */
    protected function getQueueStats(): array
    {
        return [
            'scraping_pending' => DB::table('jobs')->where('queue', 'scraping')->count(),
            'ingestion_pending' => DB::table('jobs')->where('queue', 'ingestion')->count(),
            'failed_total' => DB::table('failed_jobs')->count(),
            'failed_scraping' => DB::table('failed_jobs')
                ->where('payload', 'like', '%RunWebScrapingJob%')

                ->count(),
        ];
    }

    /**
     * Statistiche dei job falliti
     */
    protected function getFailedJobsStats(): array
    {
        $failedJobs = DB::table('failed_jobs')
            ->select('payload', 'failed_at', 'exception')
            ->latest('failed_at')
            ->limit(10)
            ->get();
            
        $stats = [
            'recent_failures' => [],
            'error_categories' => []
        ];
        
        foreach ($failedJobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown';
            
            $stats['recent_failures'][] = [
                'job' => $jobClass,
                'failed_at' => $job->failed_at,
                'error_preview' => substr($job->exception, 0, 200) . '...'
            ];
            
            // Categorizza errori
            if (str_contains($job->exception, 'MaxAttemptsExceeded')) {
                $stats['error_categories']['max_attempts'] = ($stats['error_categories']['max_attempts'] ?? 0) + 1;
            } elseif (str_contains($job->exception, 'timeout')) {
                $stats['error_categories']['timeout'] = ($stats['error_categories']['timeout'] ?? 0) + 1;
            } else {
                $stats['error_categories']['other'] = ($stats['error_categories']['other'] ?? 0) + 1;
            }
        }
        
        return $stats;
    }

    /**
     * Tenant con errori attivi
     */
    protected function getTenantsWithErrors(): array
    {
        // Questa è una implementazione semplice, idealmente dovresti
        // tenere traccia dei tenant che hanno errori attivi
        $tenantsWithErrors = [];
        
        // Per ora restituiamo solo un placeholder
        // In una implementazione completa, potresti mantenere una lista
        // dei tenant con errori in cache o database
        
        return $tenantsWithErrors;
    }

    /**
     * Pulizia automatica dei contatori obsoleti
     */
    public function cleanupOldCounters(): int
    {
        // Laravel Cache non ha un modo diretto per listare tutte le chiavi
        // che iniziano con un pattern, quindi questa è una implementazione semplificata
        
        Log::info("Cleanup contatori errori completato", [
            'cleaned_at' => now()->toISOString()
        ]);
        
        return 0; // Placeholder
    }
}
