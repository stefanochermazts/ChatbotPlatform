<?php

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Log;

class ScraperLogger
{
    private const CHANNEL = 'scraper';
    
    /**
     * 🚀 Log dell'avvio di una sessione di scraping
     */
    public static function sessionStarted(string $sessionId, int $tenantId, ?string $configName = null): void
    {
        $context = [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'config_name' => $configName,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("🚀 [SCRAPING-START] Sessione iniziata", $context);
    }
    
    /**
     * 📄 Log del processing di un URL
     */
    public static function urlProcessing(string $sessionId, string $url, int $depth = 0): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'depth' => $depth,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("📄 [URL-PROCESSING] Processando URL", $context);
    }
    
    /**
     * ✅ Log del successo di processing di un URL
     */
    public static function urlSuccess(string $sessionId, string $url, string $status, int $contentLength = 0): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'status' => $status, // 'new', 'updated', 'skipped'
            'content_length' => $contentLength,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("✅ [URL-SUCCESS] URL processato con successo", $context);
    }
    
    /**
     * ❌ Log dell'errore di processing di un URL
     */
    public static function urlError(string $sessionId, string $url, string $error): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'error' => $error,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->error("❌ [URL-ERROR] Errore processing URL", $context);
    }
    
    /**
     * 🌐 Log del rendering JavaScript
     */
    public static function jsRenderStart(string $sessionId, string $url): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("🌐 [JS-RENDER-START] Avvio rendering JavaScript", $context);
    }
    
    /**
     * ✅ Log del successo rendering JavaScript
     */
    public static function jsRenderSuccess(string $sessionId, string $url, int $contentLength, float $durationMs): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'content_length' => $contentLength,
            'duration_ms' => $durationMs,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("✅ [JS-RENDER-SUCCESS] Rendering JavaScript completato", $context);
    }
    
    /**
     * ❌ Log dell'errore rendering JavaScript
     */
    public static function jsRenderError(string $sessionId, string $url, string $error): void
    {
        $context = [
            'session_id' => $sessionId,
            'url' => $url,
            'error' => $error,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->error("❌ [JS-RENDER-ERROR] Errore rendering JavaScript", $context);
    }
    
    /**
     * 📊 Log delle statistiche di sessione
     */
    public static function sessionStats(string $sessionId, array $stats): void
    {
        $context = [
            'session_id' => $sessionId,
            'stats' => $stats,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("📊 [SESSION-STATS] Statistiche sessione", $context);
    }
    
    /**
     * 🏁 Log della conclusione di una sessione
     */
    public static function sessionCompleted(string $sessionId, array $finalStats, float $durationMs): void
    {
        $context = [
            'session_id' => $sessionId,
            'final_stats' => $finalStats,
            'duration_ms' => $durationMs,
            'timestamp' => now()->toISOString()
        ];
        
        Log::channel(self::CHANNEL)->info("🏁 [SCRAPING-COMPLETED] Sessione completata", $context);
    }
    
    /**
     * 💥 Log di errori critici
     */
    public static function criticalError(string $sessionId, string $error, array $context = []): void
    {
        $fullContext = array_merge([
            'session_id' => $sessionId,
            'error' => $error,
            'timestamp' => now()->toISOString()
        ], $context);
        
        Log::channel(self::CHANNEL)->error("💥 [CRITICAL-ERROR] Errore critico", $fullContext);
    }
    
    /**
     * 📋 Log generico per debugging
     */
    public static function debug(string $sessionId, string $message, array $context = []): void
    {
        $fullContext = array_merge([
            'session_id' => $sessionId,
            'timestamp' => now()->toISOString()
        ], $context);
        
        Log::channel(self::CHANNEL)->debug("🔍 [DEBUG] $message", $fullContext);
    }
    
    /**
     * ⚠️ Log di warning
     */
    public static function warning(string $sessionId, string $message, array $context = []): void
    {
        $fullContext = array_merge([
            'session_id' => $sessionId,
            'timestamp' => now()->toISOString()
        ], $context);
        
        Log::channel(self::CHANNEL)->warning("⚠️ [WARNING] $message", $fullContext);
    }
}


