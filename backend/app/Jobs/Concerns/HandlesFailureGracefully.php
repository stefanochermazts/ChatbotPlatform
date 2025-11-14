<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trait per gestire i fallimenti dei job in modo elegante
 * senza bloccare l'intera coda di scraping
 */
trait HandlesFailureGracefully
{
    /**
     * Soglia massima di job falliti per tenant prima di fermare lo scraping
     */
    protected int $maxFailedJobsThreshold = 5;

    /**
     * TTL in cache per il contatore di errori (in secondi)
     */
    protected int $errorCountTtl = 3600; // 1 ora

    /**
     * Gestisce il fallimento del job senza bloccarne altri
     */
    public function failed(\Throwable $exception): void
    {
        $errorType = $this->categorizeError($exception);
        $tenantId = $this->getTenantId();

        // 🔑 Gestisci UUID duplicato per failed_jobs
        try {
            Log::warning('Job fallito ma continuando elaborazione', [
                'job_class' => static::class,
                'tenant_id' => $tenantId,
                'error_type' => $errorType,
                'error_message' => $exception->getMessage(),
                'attempts' => $this->getJobAttempts(),
            ]);
        } catch (\Illuminate\Database\QueryException $logException) {
            // Se anche il log fallisce per UUID duplicate, ignora silenziosamente
            if (str_contains($logException->getMessage(), 'failed_jobs_uuid_unique')) {
                // Job già registrato come fallito, continua senza duplicare
                return;
            }
            // Altri errori di DB, rilancia
            throw $logException;
        }

        // Incrementa contatore errori per questo tenant
        $errorCount = $this->incrementErrorCount($tenantId, $errorType);

        // Se superiamo la soglia, logghiamo un warning critico
        if ($errorCount >= $this->maxFailedJobsThreshold) {
            Log::critical('Soglia errori superata per tenant', [
                'tenant_id' => $tenantId,
                'error_count' => $errorCount,
                'threshold' => $this->maxFailedJobsThreshold,
                'error_type' => $errorType,
                'recommendation' => 'Verificare configurazione scraper e connettività',
            ]);

            // Opzionalmente, possiamo disabilitare temporaneamente lo scraping
            $this->temporarilyDisableScraping($tenantId);
        }

        // Log dettagliato solo se necessario (per debug)
        if ($errorCount <= 2) {
            Log::debug('Dettaglio errore job', [
                'job_class' => static::class,
                'tenant_id' => $tenantId,
                'error_trace' => $exception->getTraceAsString(),
            ]);
        }
    }

    /**
     * Categorizza il tipo di errore per statistiche migliori
     */
    protected function categorizeError(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $class = get_class($exception);

        if (str_contains($class, 'MaxAttemptsExceeded')) {
            return 'max_attempts';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'curl')) {
            return 'network_timeout';
        }

        if (str_contains($message, 'timed out') || str_contains($class, 'TimeoutExceeded')) {
            return 'job_timeout';
        }

        if (str_contains($message, '404') || str_contains($message, '403')) {
            return 'http_error';
        }

        if (str_contains($message, 'robots.txt')) {
            return 'robots_blocked';
        }

        if (str_contains($message, 'memory') || str_contains($message, 'Fatal error')) {
            return 'memory_limit';
        }

        return 'unknown';
    }

    /**
     * Incrementa il contatore di errori per il tenant
     */
    protected function incrementErrorCount(int $tenantId, string $errorType): int
    {
        $cacheKey = "scraping_errors_{$tenantId}_{$errorType}";
        $currentCount = Cache::get($cacheKey, 0);
        $newCount = $currentCount + 1;

        Cache::put($cacheKey, $newCount, $this->errorCountTtl);

        // Mantieni anche un contatore generale
        $generalKey = "scraping_errors_{$tenantId}_total";
        $totalCount = Cache::get($generalKey, 0) + 1;
        Cache::put($generalKey, $totalCount, $this->errorCountTtl);

        return $newCount;
    }

    /**
     * Disabilita temporaneamente lo scraping per il tenant
     */
    protected function temporarilyDisableScraping(int $tenantId): void
    {
        $disableKey = "scraping_disabled_{$tenantId}";
        $disableUntil = now()->addHours(2); // Disabilita per 2 ore

        Cache::put($disableKey, $disableUntil->toISOString(), 7200); // 2 ore

        Log::warning('Scraping temporaneamente disabilitato', [
            'tenant_id' => $tenantId,
            'disabled_until' => $disableUntil->toISOString(),
            'reason' => 'Troppi errori consecutivi',
        ]);
    }

    /**
     * Verifica se lo scraping è disabilitato per il tenant
     */
    protected function isScrapingDisabled(int $tenantId): bool
    {
        $disableKey = "scraping_disabled_{$tenantId}";

        return Cache::has($disableKey);
    }

    /**
     * Ottiene l'ID del tenant (da implementare nel job specifico)
     */
    abstract protected function getTenantId(): int;

    /**
     * Ottiene il numero di tentativi correnti (usa quello di Laravel)
     */
    protected function getJobAttempts(): int
    {
        return method_exists($this, 'attempts') ? $this->attempts() : 0;
    }
}
