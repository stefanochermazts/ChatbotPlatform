<?php

namespace App\Services\Scraper;

use App\Models\Document;
use App\Models\ScraperConfig;
use App\Models\ScraperProgress;
use App\Models\Tenant;
use App\Jobs\IngestUploadedDocumentJob;
use App\Services\Scraper\ContentQualityAnalyzer;
use App\Services\Scraper\JavaScriptRenderer;
use App\Services\Scraper\ScraperLogger;
use League\HTMLToMarkdown\HtmlConverter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;

class WebScraperService
{
    private array $visitedUrls = [];
    private array $results = [];
    private array $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];
    private float $startTime;
    private int $maxExecutionTime = 6000; // 100 minuti (prima del timeout del job)
    private ?ContentQualityAnalyzer $qualityAnalyzer = null;
    private ?ScraperProgress $progress = null;
    private array $urlsQueue = [];
    private string $sessionId;
    private ?ScraperConfig $currentConfig = null; // Current scraper config for tenant patterns
    
    public function __construct()
    {
        $this->qualityAnalyzer = new ContentQualityAnalyzer();
        $this->sessionId = 'scraper_' . uniqid();
    }

    public function scrapeForTenant(int $tenantId, ?int $scraperConfigId = null): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $config = $scraperConfigId
            ? ScraperConfig::where('tenant_id', $tenantId)->where('id', $scraperConfigId)->first()
            : ScraperConfig::where('tenant_id', $tenantId)->first();
            
        // Store config for pattern access
        $this->currentConfig = $config;
        
        if (!$config || empty($config->seed_urls)) {
            return ['error' => 'Nessuna configurazione scraper trovata o seed URLs vuoti'];
        }

        // 🚀 Log avvio sessione di scraping
        $this->startTime = microtime(true);
        ScraperLogger::sessionStarted($this->sessionId, $tenantId, $config->name);
        
        // Inizializza progress tracking
        $this->initializeProgress($tenantId, $scraperConfigId);
        
        $this->visitedUrls = [];
        $this->results = [];
        $this->stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];
        $this->urlsQueue = [];

        // Scraping da sitemap se presente
        if (!empty($config->sitemap_urls)) {
            foreach ($config->sitemap_urls as $sitemapUrl) {
                $this->scrapeSitemap($sitemapUrl, $config, $tenant);
            }
        }

        // Scraping ricorsivo dai seed URLs
        foreach ($config->seed_urls as $seedUrl) {
            $this->scrapeRecursive($seedUrl, $config, $tenant, 0);
        }

        // 🆕 SALVATAGGIO PROGRESSIVO: I documenti sono già stati salvati durante lo scraping
        // Il count include nuovi + aggiornati (documenti effettivamente salvati/processati)
        $savedCount = $this->stats['new'] + $this->stats['updated'];
        
        \Log::info("📊 [PROGRESSIVE-SAVE] Riepilogo salvataggio progressivo", [
            'session_id' => $this->sessionId,
            'total_documents_processed' => count($this->results),
            'new' => $this->stats['new'],
            'updated' => $this->stats['updated'],
            'skipped' => $this->stats['skipped'],
            'total_saved' => $savedCount
        ]);

        // 🏁 Log completamento sessione
        $finalStats = [
            'urls_visited' => count($this->visitedUrls),
            'documents_saved' => $savedCount,
            'new' => $this->stats['new'],
            'updated' => $this->stats['updated'],
            'skipped' => $this->stats['skipped']
        ];
        ScraperLogger::sessionCompleted($this->sessionId, $finalStats, 0); // Duration calcolata altrove

        return [
            'success' => true,
            'urls_visited' => count($this->visitedUrls),
            'documents_saved' => $savedCount,
            'stats' => $this->stats,
            'results' => $this->results
        ];
    }

    /**
     * 🚀 Modalità PARALLELA: Scraping con job distribuiti in coda
     * Versione ottimizzata per ambienti produzione con Horizon
     */
    public function scrapeForTenantParallel(int $tenantId, ?int $scraperConfigId = null): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $config = $scraperConfigId
            ? ScraperConfig::where('tenant_id', $tenantId)->where('id', $scraperConfigId)->first()
            : ScraperConfig::where('tenant_id', $tenantId)->first();
            
        // Store config for pattern access
        $this->currentConfig = $config;
        
        if (!$config || empty($config->seed_urls)) {
            return ['error' => 'Nessuna configurazione scraper trovata o seed URLs vuoti'];
        }

        // 🚀 Log avvio sessione di scraping parallelo
        $this->startTime = microtime(true);
        ScraperLogger::sessionStarted($this->sessionId, $tenantId, $config->name . ' (Parallel)');
        
        // Inizializza progress tracking
        $this->initializeProgress($tenantId, $scraperConfigId);
        
        $this->visitedUrls = [];
        $this->stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];

        \Log::info("🚀 [PARALLEL-SCRAPING] Inizio scraping parallelo", [
            'session_id' => $this->sessionId,
            'tenant_id' => $tenantId,
            'config_id' => $scraperConfigId,
            'seed_urls_count' => count($config->seed_urls)
        ]);

        // Scraping da sitemap se presente (sequenziale per estrazione URL)
        if (!empty($config->sitemap_urls)) {
            foreach ($config->sitemap_urls as $sitemapUrl) {
                $this->scrapeSitemap($sitemapUrl, $config, $tenant);
            }
        }

        // 🚀 Scraping PARALLELO dai seed URLs
        // Invece di processare sequenzialmente, dispatcha job per ogni URL
        foreach ($config->seed_urls as $seedUrl) {
            $this->scrapeRecursiveParallel($seedUrl, $config, $tenant, 0);
        }

        // 🎯 In modalità parallela, i documenti vengono salvati dai job worker
        // Qui ritorniamo solo le metriche iniziali
        $duration = microtime(true) - $this->startTime;
        
        $jobsDispatched = count($this->visitedUrls);
        
        \Log::info("🚀 [PARALLEL-SCRAPING] Job dispatchati", [
            'session_id' => $this->sessionId,
            'jobs_count' => $jobsDispatched,
            'duration_seconds' => round($duration, 2)
        ]);

        // Finalizza progress
        $this->finalizeProgress('dispatched');
        
        // Log finale sessione
        ScraperLogger::sessionCompleted(
            $this->sessionId,
            $jobsDispatched, // URL da processare
            0, // Documenti salvati verranno aggiornati dai worker
            $duration
        );

        return [
            'urls_visited' => $jobsDispatched,
            'documents_saved' => 0, // Verranno salvati dai worker
            'stats' => $this->stats,
            'duration_seconds' => round($duration, 2),
            'mode' => 'parallel',
            'jobs_dispatched' => $jobsDispatched
        ];
    }



    private function scrapeRecursive(string $url, ScraperConfig $config, Tenant $tenant, int $depth): void
    {
        // ⏰ Controllo timeout preventivo
        if (isset($this->startTime) && (microtime(true) - $this->startTime) > $this->maxExecutionTime) {


            \Log::warning("🕐 Scraping stopped - max execution time reached", [
                'session_id' => $this->sessionId,
                'elapsed_seconds' => round(microtime(true) - $this->startTime),
                'max_seconds' => $this->maxExecutionTime,
                'urls_processed' => count($this->visitedUrls)
            ]);
            return;
        }
        
        // Controlli di base
        if ($depth > $config->max_depth) return;
        if (in_array($url, $this->visitedUrls)) return;
        if (!$this->isUrlAllowed($url, $config)) return;

        $this->visitedUrls[] = $url;
        
        // 📄 Log processing URL
        ScraperLogger::urlProcessing($this->sessionId, $url, $depth);

        // Rate limiting
        if ($config->rate_limit_rps > 0) {
            usleep(1000000 / $config->rate_limit_rps); // Converti RPS in microsecondi
        }

        try {
            $content = $this->fetchUrl($url, $config);
            
            // 🐛 DEBUG: Log per capire cosa viene restituito da fetchUrl
            \Log::debug("🔍 [FETCH-DEBUG] fetchUrl returned", [
                'url' => $url,
                'content_is_null' => $content === null,
                'content_is_empty' => empty($content),
                'content_length' => $content ? strlen($content) : 0
            ]);
            
            if (!$content) {
                \Log::warning("⚠️ [FETCH-FAILED] fetchUrl returned empty content", ['url' => $url]);
                return;
            }

            // Determina se questa pagina è "link-only" in base alla configurazione
            $isLinkOnly = $this->isLinkOnlyUrl($url, $config);
            
            \Log::debug("🔍 [LINK-ONLY-CHECK]", [
                'url' => $url,
                'is_link_only' => $isLinkOnly
            ]);

            if (!$isLinkOnly) {
                // Politica: se skip_known_urls attivo, e abbiamo già un documento per questo URL (e non è da recrawllare), salta
                $shouldSkip = $this->shouldSkipKnownUrl($url, $tenant, $config);
                
                \Log::debug("🔍 [SKIP-CHECK]", [
                    'url' => $url,
                    'should_skip' => $shouldSkip,
                    'skip_known_urls_enabled' => $config->skip_known_urls ?? false
                ]);
                
                if ($shouldSkip) {
                    $this->stats['skipped']++;
                    ScraperLogger::urlSuccess($this->sessionId, $url, 'skipped', 0);
                    \Log::info('Skip URL già noto', ['url' => $url]);
                } else {
                \Log::debug("🚀 [EXTRACTION-START] Starting content extraction", ['url' => $url]);
                // Estrai contenuto principale
                $extractedContent = $this->extractContent($content, $url);
                if ($extractedContent) {
                    // 🆕 SALVATAGGIO PROGRESSIVO: Salva il documento immediatamente
                    $result = [
                        'url' => $url,
                        'title' => $extractedContent['title'],
                        'content' => $extractedContent['content'],
                        'depth' => $depth,
                        'quality_analysis' => $extractedContent['quality_analysis'] ?? null
                    ];
                    
                    // Aggiungi ai risultati per statistiche
                    $this->results[] = $result;
                    
                    // 🚀 SALVA E AVVIA INGESTION IMMEDIATAMENTE
                    try {
                        $this->saveAndIngestSingleResult($result, $tenant, $config);
                        \Log::info("✅ [PROGRESSIVE-SAVE] Documento salvato e ingestion avviata", [
                            'session_id' => $this->sessionId,
                            'url' => $url,
                            'title' => $extractedContent['title']
                        ]);
                    } catch (\Exception $e) {
                        \Log::error("❌ [PROGRESSIVE-SAVE] Errore salvataggio progressivo", [
                            'session_id' => $this->sessionId,
                            'url' => $url,
                            'error' => $e->getMessage()
                        ]);
                        // Non bloccare lo scraping, continua con gli altri URL
                    }
                }
                }
            }

            // Estrai link per ricorsione (solo se depth < max_depth)
            if ($depth < $config->max_depth) {
                $links = $this->extractLinks($content, $url);
                foreach ($links as $link) {
                    $this->scrapeRecursive($link, $config, $tenant, $depth + 1);
                }
            }

        } catch (\Exception $e) {
            // ❌ GESTIONE ERRORE ROBUSTO: Log dettagliato ma continua con altri URL
            ScraperLogger::urlError($this->sessionId, $url, $e->getMessage());
            \Log::error("❌ [SCRAPING-ERROR] Errore processamento URL - SCARTO PAGINA E CONTINUO", [
                'session_id' => $this->sessionId,
                'url' => $url,
                'depth' => $depth,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_urls_processed' => count($this->visitedUrls)
            ]);
            // Non rilancia l'eccezione - continua con le altre pagine
        }
    }

    private function scrapeSitemap(string $sitemapUrl, ScraperConfig $config, Tenant $tenant): void
    {
        try {
            $response = Http::timeout($config->timeout ?? 60)->get($sitemapUrl);
            if (!$response->successful()) return;

            $xml = simplexml_load_string($response->body());
            if (!$xml) return;

            foreach ($xml->url as $urlElement) {
                $pageUrl = (string) $urlElement->loc;
                if ($this->isUrlAllowed($pageUrl, $config)) {
                    $this->scrapeRecursive($pageUrl, $config, $tenant, 0);
                }
            }
        } catch (\Exception $e) {
            \Log::warning("Errore parsing sitemap: {$sitemapUrl}", ['error' => $e->getMessage()]);
        }
    }

    private function fetchUrl(string $url, ScraperConfig $config): ?string
    {
        // 🚀 NEW: JavaScript rendering support per SPA (Angular, React, Vue)
        if ($config->render_js) {
            \Log::info("🌐 [JS-RENDER] Using JavaScript rendering for SPA", ['url' => $url]);
            return $this->fetchUrlWithJS($url, $config);
        }

        // Metodo standard HTTP per siti statici
        $timeout = $config->timeout ?? 60;
        
        $httpBuilder = Http::timeout($timeout)
            ->withUserAgent('ChatbotPlatform/1.0 (+https://example.com/bot)')
            ->withHeaders($config->auth_headers ?? []);

        $response = $httpBuilder->get($url);
        
        if (!$response->successful()) {
            return null;
        }

        $content = $response->body();
        
        // Gestione robusta dell'encoding UTF-8
        $content = $this->ensureUtf8Encoding($content, $response);
        
        return $content;
    }

    /**
     * 🌐 Fetch URL con rendering JavaScript per SPA (Angular, React, Vue)
     */
    private function fetchUrlWithJS(string $url, ScraperConfig $config): ?string
    {
        // 🌐 Log avvio rendering JavaScript
        ScraperLogger::jsRenderStart($this->sessionId, $url);
        
        $startTime = microtime(true);
        $renderer = new JavaScriptRenderer();
        // ⚡ CONFIGURABLE: Usa timeout configurabili per JavaScript rendering
        $timeout = max($config->js_timeout ?? $config->timeout ?? 30, 30);
        
        // 🔧 Prepara configurazione timeout per JavaScript
        $jsConfig = [
            'js_navigation_timeout' => $config->js_navigation_timeout ?? 30,
            'js_content_wait' => $config->js_content_wait ?? 15,
            'js_scroll_delay' => $config->js_scroll_delay ?? 2,
            'js_final_wait' => $config->js_final_wait ?? 8,
        ];
        
        $content = $renderer->renderUrl($url, $timeout, $jsConfig);
        
        if ($content) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            ScraperLogger::jsRenderSuccess($this->sessionId, $url, strlen($content), $duration);
        } else {
            ScraperLogger::jsRenderError($this->sessionId, $url, "Rendering failed - no content returned");
        }
        
        return $content;
    }

    /**
     * Assicura che il contenuto sia correttamente codificato in UTF-8
     */
    private function ensureUtf8Encoding(string $content, $response): string
    {
        // 1. Rileva encoding dal Content-Type header
        $contentType = $response->header('Content-Type', '');
        $detectedCharset = null;
        
        if (preg_match('/charset=([^;\s]+)/i', $contentType, $matches)) {
            $detectedCharset = strtolower(trim($matches[1], '"\''));
        }
        
        // 2. Rileva encoding dal meta tag HTML se presente
        if (!$detectedCharset && preg_match('/<meta[^>]+charset\s*=\s*["\']?([^"\'>\s]+)/i', $content, $matches)) {
            $detectedCharset = strtolower($matches[1]);
        }
        
        // 3. Auto-detect encoding se non specificato
        if (!$detectedCharset) {
            $detected = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($detected) {
                $detectedCharset = strtolower($detected);
            }
        }
        
        // 4. Override per Windows-1252 smart quotes (spesso mal-dichiarato come ISO-8859-1)
        if ($detectedCharset === 'iso-8859-1') {
            // Cerca bytes tipici di Windows-1252 smart quotes che non esistono in ISO-8859-1
            $windows1252Bytes = ["\x91", "\x92", "\x93", "\x94", "\x96", "\x97", "\x85"];
            $hasSmartQuotes = false;
            
            foreach ($windows1252Bytes as $byte) {
                if (strpos($content, $byte) !== false) {
                    $hasSmartQuotes = true;
                    break;
                }
            }
            
            if ($hasSmartQuotes) {
                $detectedCharset = 'windows-1252';
                \Log::debug("Detected Windows-1252 smart quotes, overriding declared ISO-8859-1");
            }
        }
        
        // 5. Converti a UTF-8 se necessario
        if ($detectedCharset && $detectedCharset !== 'utf-8') {
            $converted = mb_convert_encoding($content, 'UTF-8', $detectedCharset);
            if ($converted !== false) {
                $content = $converted;
                \Log::debug("Converted encoding from {$detectedCharset} to UTF-8");
            }
        }
        
        // 6. Verifica finale e pulizia caratteri problematici
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Rimuovi caratteri non validi UTF-8
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            \Log::warning("Removed invalid UTF-8 characters from content");
        }
        
        // 7. Fix caratteri malformati comuni da encoding doppio o errato
        $encodingFixes = [
            // Caratteri latini base
            'Ã¬' => 'ì',
            'Ã ' => 'à',
            'Ã¹' => 'ù',
            'Ã¨' => 'è',
            'Ã©' => 'é',
            'Ã²' => 'ò',
            // Apostrofi e virgolette malformati (problema comune da encoding doppio)
            'â€™' => "'",
            'â€œ' => '"',
            'â€' => '"',
            'â€˜' => "'",
            'â€š' => "'",
            'â€¦' => '...',
            // Trattini
            'â€"' => '–',
            'â€"' => '—',
        ];
        
        // 8. Normalizza smart quotes Unicode ad ASCII per compatibilità
        $unicodeNormalization = [
            mb_chr(8217, 'UTF-8') => "'",   // U+2019 RIGHT SINGLE QUOTATION MARK
            mb_chr(8216, 'UTF-8') => "'",   // U+2018 LEFT SINGLE QUOTATION MARK 
            mb_chr(8220, 'UTF-8') => '"',   // U+201C LEFT DOUBLE QUOTATION MARK
            mb_chr(8221, 'UTF-8') => '"',   // U+201D RIGHT DOUBLE QUOTATION MARK
            mb_chr(8218, 'UTF-8') => "'",   // U+201A SINGLE LOW-9 QUOTATION MARK
            mb_chr(8222, 'UTF-8') => '"',   // U+201E DOUBLE LOW-9 QUOTATION MARK
            mb_chr(8230, 'UTF-8') => '...', // U+2026 HORIZONTAL ELLIPSIS
            mb_chr(8211, 'UTF-8') => '-',   // U+2013 EN DASH
            mb_chr(8212, 'UTF-8') => '--',  // U+2014 EM DASH
        ];
        
        $originalLength = strlen($content);
        
        // Applica fix per encoding malformati
        foreach ($encodingFixes as $wrong => $correct) {
            $content = str_replace($wrong, $correct, $content);
        }
        
        // Applica normalizzazione Unicode a ASCII
        foreach ($unicodeNormalization as $unicode => $ascii) {
            $content = str_replace($unicode, $ascii, $content);
        }
        
        $newLength = strlen($content);
        if ($newLength !== $originalLength) {
            \Log::debug("Fixed malformed encoding and normalized Unicode characters", [
                'original_length' => $originalLength,
                'new_length' => $newLength,
                'changes' => $originalLength - $newLength
            ]);
        }
        
        return $content;
    }

    private function extractContent(string $html, string $url): ?array
    {
        // 🚀 NEW: Check if this is a JavaScript-rendered SPA site that needs special handling
        $isJavaScriptSite = $this->isJavaScriptRenderedSite($html, $url);
        
        if ($isJavaScriptSite) {
            \Log::debug("🌐 JavaScript SPA detected - using HTML-to-Markdown approach", ['url' => $url]);
            return $this->extractFromJavaScriptSite($html, $url);
        }
        
        // 🧠 ENHANCED: Analisi qualità avanzata per strategia ottimale (for non-JS sites)
        $analysis = $this->qualityAnalyzer->analyzeContent($html, $url);
        // Pass URL and HTML to analysis for intelligent strategy detection
        $analysis['url'] = $url;
        $analysis['html'] = $html;
        
        // Determine extraction strategy with full context
        $analysis['extraction_strategy'] = $this->determineExtractionStrategy($analysis);
        
        \Log::debug("🧠 Enhanced Content Analysis", [
            'url' => $url,
            'content_type' => $analysis['content_type'],
            'quality_score' => $analysis['quality_score'],
            'extraction_strategy' => $analysis['extraction_strategy'],
            'processing_priority' => $analysis['processing_priority']
        ]);
        
        // EARLY RETURN: Skip contenuto di bassa qualità
        if ($analysis['extraction_strategy'] === 'skip_low_quality') {
            \Log::info("⚠️ Skipping low quality content", [
                'url' => $url,
                'quality_score' => $analysis['quality_score'],
                'reason' => 'Below minimum quality threshold'
            ]);
            return null;
        }
        
        // STEP 1: Estrazione basata su strategia intelligente
        $extractedContent = $this->executeExtractionStrategy($html, $url, $analysis);
        
        // 🚀 NEW: Se l'estrazione normale fallisce, prova Readability.php come fallback
        if (!$extractedContent || strlen($extractedContent['content']) < 100) {
            \Log::warning("⚠️ Normal extraction failed/insufficient, trying Readability.php fallback", [
                'url' => $url,
                'normal_content_length' => $extractedContent ? strlen($extractedContent['content']) : 0,
                'html_length' => strlen($html)
            ]);
            
            $readabilityResult = $this->extractWithReadability($html, $url);
            if ($readabilityResult && strlen($readabilityResult['content']) > 100) {
                \Log::info("✅ Readability.php fallback successful", [
                    'url' => $url,
                    'method' => 'readability_fallback',
                    'content_length' => strlen($readabilityResult['content']),
                    'content_preview' => substr($readabilityResult['content'], 0, 200)
                ]);
                return $readabilityResult;
            }
        }
        
        if (!$extractedContent) {
            \Log::warning("❌ All extraction methods failed", ['url' => $url]);
            return null;
        }
        
        // STEP 2: Post-processing con quality score
        $extractedContent['quality_analysis'] = [
            'content_type' => $analysis['content_type'],
            'quality_score' => $analysis['quality_score'],
            'business_relevance' => $analysis['business_relevance'],
            'processing_priority' => $analysis['processing_priority'],
            'extraction_method' => $analysis['extraction_strategy']
        ];
        
        return $extractedContent;
    }

    /**
     * 🎯 Determina strategia di estrazione ottimale con context URL
     */
    private function determineExtractionStrategy(array $analysis): string
    {
        // Automatic strategy selection based on content analysis (no hardcoded domains)
        
        // Tabelle complesse = metodo manuale
        if ($analysis['has_complex_tables']) {
            return 'manual_dom_primary';
        }
        
        // Contenuto testuale di qualità = Readability
        if ($analysis['content_type'] === 'article_content' && $analysis['quality_score'] > 0.5) {
            return 'readability_primary';
        }
        
        // Dati strutturati = metodo ibrido
        if ($analysis['has_structured_data'] && $analysis['business_relevance'] > 0.6) {
            return 'hybrid_structured';
        }
        
        // Bassa qualità = skip o extraction minimal
        if ($analysis['quality_score'] < 0.3) {
            return 'skip_low_quality';
        }
        
        return 'hybrid_default';
    }
    
    /**
     * 🎯 Esegue strategia di estrazione basata su analisi qualità
     */
    private function executeExtractionStrategy(string $html, string $url, array $analysis): ?array
    {
        $strategy = $analysis['extraction_strategy'];
        
        switch ($strategy) {
            case 'manual_dom_primary':
                \Log::debug("📋 Using manual DOM extraction (tables/structured data)", ['url' => $url]);
                $result = $this->extractWithManualDOM($html, $url, null, null, null);
                break;
                
            case 'readability_primary':
                \Log::debug("📖 Using Readability extraction (article content)", ['url' => $url]);
                $result = $this->extractWithReadability($html, $url);
                // Fallback se Readability fallisce
                if (!$result || strlen($result['content']) < 100) {
                    $result = $this->extractWithManualDOM($html, $url, null, null, null);
                }
                break;
                
            case 'hybrid_structured':
                \Log::debug("🔄 Using hybrid extraction (structured info)", ['url' => $url]);
                // Prova prima manual DOM per preservare struttura
                $result = $this->extractWithManualDOM($html, $url, null, null, null);
                if (!$result || strlen($result['content']) < 150) {
                    // Fallback a Readability
                    $result = $this->extractWithReadability($html, $url);
                }
                break;
                
            case 'hybrid_default':
            default:
                \Log::debug("⚖️ Using hybrid extraction (default)", ['url' => $url]);
                // Strategia legacy migliorata
                if ($analysis['has_complex_tables'] || $analysis['text_ratio'] < 0.6) {
                    $result = $this->extractWithManualDOM($html, $url, null, null, null);
                } else {
                    $result = $this->extractWithReadability($html, $url);
                    // Fallback se Readability fallisce
                    if (!$result || strlen($result['content']) < 150) {
                        $result = $this->extractWithManualDOM($html, $url, null, null, null);
                    }
                }
                break;
        }
        
        return $result;
    }

    /**
     * Analizza il tipo di contenuto per scegliere la strategia di estrazione ottimale
     */
    private function analyzeContentType(string $html): array
    {
        $analysis = [
            'has_complex_tables' => false,
            'text_ratio' => 0.0,
            'has_forms' => false,
            'has_structured_data' => false,
            'content_type' => 'unknown'
        ];
        
        // Verifica presenza di tabelle complesse
        $tableCount = substr_count($html, '<table');
        $responsiveTableCount = substr_count($html, 'hidden-xs') + substr_count($html, 'visible-xs');
        
        if ($tableCount > 0) {
            // Ha tabelle responsive o con molte celle
            $cellCount = substr_count($html, '<td') + substr_count($html, '<th');
            if ($responsiveTableCount > 5 || $cellCount > 15) {
                $analysis['has_complex_tables'] = true;
                $analysis['content_type'] = 'data_table';
            }
        }
        
        // Calcola rapporto testo/markup
        $textContent = strip_tags($html);
        $textLength = strlen(trim($textContent));
        $htmlLength = strlen($html);
        
        $analysis['text_ratio'] = $htmlLength > 0 ? $textLength / $htmlLength : 0;
        
        // Verifica altri pattern di dati strutturati
        $structuredPatterns = [
            'phone' => '/\b(?:\d{2,4}[\s\-]?){2,4}\d{2,4}\b/', // Numeri telefono
            'address' => '/\b(?:via|piazza|corso|viale)\s+[^,\n]{5,50}/i', // Indirizzi
            'hours' => '/\b\d{1,2}:\d{2}\s*[-–]\s*\d{1,2}:\d{2}\b/', // Orari
        ];
        
        $structuredCount = 0;
        foreach ($structuredPatterns as $pattern) {
            if (preg_match($pattern, $html)) {
                $structuredCount++;
            }
        }
        
        if ($structuredCount >= 2) {
            $analysis['has_structured_data'] = true;
            if (!$analysis['has_complex_tables']) {
                $analysis['content_type'] = 'structured_info';
            }
        }
        
        // Verifica se è principalmente contenuto testuale
        if ($analysis['text_ratio'] > 0.7 && !$analysis['has_complex_tables']) {
            $analysis['content_type'] = 'article_text';
        }
        
        // Verifica presenza di form
        if (substr_count($html, '<form') > 0) {
            $analysis['has_forms'] = true;
            $analysis['content_type'] = 'interactive_page';
        }
        
        return $analysis;
    }

    /**
     * Estrazione intelligente con Readability.php
     */
    private function extractWithReadability(string $html, string $url): ?array
    {
        try {
            // 🚀 ENHANCED: Pre-processing HTML più robusto per Readability.php
            $preprocessedHtml = $this->preprocessHtmlForReadability($html);
            
            // 🔧 FIX: Assicura che l'HTML sia valido per Readability.php
            $preprocessedHtml = $this->ensureValidHtmlForReadability($preprocessedHtml);
            
            \Log::debug("📖 Readability.php preprocessing completed", [
                'url' => $url,
                'original_length' => strlen($html),
                'preprocessed_length' => strlen($preprocessedHtml),
                'html_preview' => substr($preprocessedHtml, 0, 200)
            ]);
            
            // Configura Readability con impostazioni ottimizzate
            $readabilityConfig = new Configuration([
                'FixRelativeURLs' => true,
                'OriginalURL' => $url,
                'RemoveEmpty' => false, // Mantieni celle vuote delle tabelle
                'SummonCthulhu' => true, // Algoritmo più aggressivo
                'DisableJSONLD' => false, // Mantieni structured data
            ]);
            
            // Crea istanza Readability
            $readability = new Readability($readabilityConfig);
            
            // Parse e estrai contenuto
            $readability->parse($preprocessedHtml);
            
            // Ottieni risultati
            $title = $readability->getTitle();
            $content = $readability->getContent();
            
            if (!$content) {
                return null;
            }
            
            // Post-processing del contenuto estratto
            $content = $this->postProcessReadabilityContent($content, $url);
            
            // Converti HTML estratto in Markdown
            $markdownContent = $this->htmlToMarkdown($content, $url);
            
            // Cleanup finale
            $markdownContent = $this->cleanupContent($markdownContent);
            
            // Verifica qualità estrazione
            $qualityScore = $this->assessExtractionQuality($markdownContent, $html);
            
            // Soglia ridotta da 0.3 a 0.1 per siti comunali con tabelle difficili da estrarre
            if (strlen($markdownContent) < 50 || $qualityScore < 0.1) {
                \Log::debug("Readability.php qualità insufficiente", [
                    'url' => $url,
                    'content_length' => strlen($markdownContent),
                    'quality_score' => $qualityScore
                ]);
                return null;
            }
            
            return [
                'title' => $title ?: parse_url($url, PHP_URL_HOST),
                'content' => $markdownContent
            ];
            
        } catch (\Exception $e) {
            \Log::warning("Readability.php fallito", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Metodo di estrazione manuale (fallback)
     */
    private function extractWithManualDOM(string $html, string $url, $dom, $title, $mainContent): ?array
    {
        \Log::debug("🎯 Using unified extraction approach (same as JS rendering)", ['url' => $url]);
        
        // 🚀 STEP 1: Clean HTML first (same as JS rendering)
        $cleanHtml = $this->cleanHtmlForMarkdown($html);
        
        // 🔧 FIX: Previeni divisione per zero
        $cleaningLoss = strlen($html) > 0 
            ? round((1 - (strlen($cleanHtml) / strlen($html))) * 100, 2)
            : 0;
        \Log::debug("🧽 HTML cleaning completed", [
            'url' => $url,
            'original_html_length' => strlen($html),
            'clean_html_length' => strlen($cleanHtml),
            'cleaning_loss_percentage' => $cleaningLoss . '%'
        ]);
        
        // 🚀 STEP 2: Parse to DOM and use proven convertToMarkdown method
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        
        // Load cleaned HTML 
        $loadSuccess = $dom->loadHTML('<html><body>' . $cleanHtml . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        
        libxml_clear_errors();
        
        // Extract title if not provided
        if (!$title) {
            $titleNodes = $dom->getElementsByTagName('title');
            $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : parse_url($url, PHP_URL_HOST);
        }
        
        // 🚀 STEP 3: Use proven convertToMarkdown method on the body
        $bodyElements = $dom->getElementsByTagName('body');
        if ($bodyElements->length > 0) {
            $markdownContent = $this->convertToMarkdown($bodyElements->item(0), $url);
        } else {
            $markdownContent = '';
        }
        
        // 🔧 FIX: Previeni divisione per zero
        $conversionLoss = strlen($cleanHtml) > 0 
            ? round((1 - (strlen($markdownContent) / strlen($cleanHtml))) * 100, 2)
            : 0;
        \Log::debug("📝 Markdown conversion completed", [
            'url' => $url,
            'clean_html_length' => strlen($cleanHtml),
            'markdown_length' => strlen($markdownContent),
            'conversion_loss_percentage' => $conversionLoss . '%'
        ]);
        
        // 🚀 STEP 4: Clean up the markdown (same as JS rendering)
        $preCleanupLength = strlen($markdownContent);
        $markdownContent = $this->cleanupContent($markdownContent);
        
        // 🔧 FIX: Previeni divisione per zero se il contenuto è vuoto
        $cleanupLoss = $preCleanupLength > 0 
            ? round((1 - (strlen($markdownContent) / $preCleanupLength)) * 100, 2)
            : 0;
        
        \Log::debug("🧽 Markdown cleanup completed", [
            'url' => $url,
            'pre_cleanup_length' => $preCleanupLength,
            'final_markdown_length' => strlen($markdownContent),
            'cleanup_loss_percentage' => $cleanupLoss . '%'
        ]);
        
        if (strlen($markdownContent) < 50) {
            \Log::warning("⚠️ Extracted content too short", [
                'url' => $url,
                'final_length' => strlen($markdownContent)
            ]);
            return null;
        }
        
        return [
            'title' => $title ?: 'Untitled',
            'content' => $markdownContent
        ];
    }

    /**
     * Converte HTML pulito di Readability in Markdown
     */
    private function htmlToMarkdown(string $html, string $baseUrl): string
    {
        // Crea DOM dal contenuto pulito di Readability
        $dom = new \DOMDocument();
        $dom->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        
        $bodyElements = $dom->getElementsByTagName('body');
        if ($bodyElements->length > 0) {
            return $this->convertToMarkdown($bodyElements->item(0), $baseUrl);
        }
        
        return '';
    }

    /**
     * Pre-processing HTML per ottimizzare l'estrazione di Readability.php
     */
    private function preprocessHtmlForReadability(string $html): string
    {
        // Converte classi responsive Bootstrap in contenuto più visibile
        // Rimuovi elementi mobile-only se esistono equivalenti desktop
        $html = preg_replace('/<[^>]*class="[^"]*visible-xs[^"]*"[^>]*>.*?<\/[^>]+>/is', '', $html);
        
        // Migliora le celle hidden-xs per renderle più visibili a Readability
        $html = preg_replace('/class="[^"]*hidden-xs[^"]*"/', 'class="desktop-content"', $html);
        
        // Assicura encoding UTF-8 corretto
        if (mb_detect_encoding($html, 'UTF-8', true) === false) {
            $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html));
        }
        
        return $html;
    }
    
    /**
     * 🔧 Assicura che l'HTML sia valido per Readability.php
     */
    private function ensureValidHtmlForReadability(string $html): string
    {
        // Se l'HTML non ha tag html/body, aggiungili
        if (!preg_match('/<html[^>]*>/i', $html)) {
            $html = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        }
        
        // Rimuovi tag malformati o incompleti che possono causare problemi
        $html = preg_replace('/<[^>]*$/', '', $html); // Tag incompleti alla fine
        $html = preg_replace('/^[^<]*</', '<', $html); // Contenuto prima del primo tag
        
        // Rimuovi caratteri di controllo problematici
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html);
        
        // Assicura che tutti i tag siano chiusi correttamente
        $html = preg_replace('/<br\s*\/?>/i', '<br>', $html);
        $html = preg_replace('/<hr\s*\/?>/i', '<hr>', $html);
        $html = preg_replace('/<img([^>]*?)\s*\/?>/i', '<img$1>', $html);
        
        // Fix encoding issues comuni
        $html = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;'], [' ', '&', '<', '>', '"'], $html);
        
        return $html;
    }

    /**
     * Post-processing del contenuto estratto da Readability.php
     */
    private function postProcessReadabilityContent(string $content, string $url): string
    {
        // Fix URL duplicati (https://...\/https://...)
        $baseUrl = rtrim($url, '/');
        $parsedUrl = parse_url($baseUrl);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $correctBase = "$scheme://$host";
        
        // Correggi URL malformati
        $content = preg_replace(
            '/href="https?:\/\/[^"]*\\' . preg_quote($correctBase, '/') . '/',
            'href="' . $correctBase,
            $content
        );
        
        // I fix di encoding sono ora gestiti in ensureUtf8Encoding()
        
        return $content;
    }

    /**
     * Valuta la qualità dell'estrazione confrontando contenuto estratto con HTML originale
     */
    private function assessExtractionQuality(string $markdownContent, string $originalHtml): float
    {
        $score = 0.0;
        
        // Verifica presenza di tabelle (importante per questo sito)
        if (strpos($originalHtml, '<table') !== false) {
            if (strpos($markdownContent, '|') !== false) {
                $score += 0.3; // Tabelle estratte
            } else {
                // Non penalizziamo troppo le tabelle mancanti - alcuni siti hanno tabelle layout
                $score += 0.0; // Neutro invece di penalizzare -0.2
            }
        }
        
        // Verifica presenza di numeri di telefono (pattern comune)
        $phonePattern = '/\b(?:\d{2,4}[\s\-]?){2,4}\d{2,4}\b/';
        preg_match_all($phonePattern, $originalHtml, $originalPhones);
        preg_match_all($phonePattern, $markdownContent, $extractedPhones);
        
        $phoneRatio = count($originalPhones[0]) > 0 ? 
            count($extractedPhones[0]) / count($originalPhones[0]) : 1.0;
        $score += $phoneRatio * 0.4;
        
        // Verifica presenza di link
        $originalLinks = substr_count($originalHtml, '<a ');
        $extractedLinks = substr_count($markdownContent, '](');
        
        $linkRatio = $originalLinks > 0 ? $extractedLinks / $originalLinks : 1.0;
        $score += $linkRatio * 0.2;
        
        // Verifica lunghezza relativa del contenuto
        $htmlTextLength = strlen(strip_tags($originalHtml));
        $markdownLength = strlen($markdownContent);
        
        if ($htmlTextLength > 0) {
            $lengthRatio = $markdownLength / $htmlTextLength;
            if ($lengthRatio > 0.3 && $lengthRatio < 0.8) {
                $score += 0.1; // Buon rapporto contenuto/rumore
            }
        }
        
        // Bonus per contenuto sostanzioso anche se qualità bassa
        if ($markdownLength > 200) {
            $score += 0.15; // Incentiva contenuto lungo
        }
        
        return max(0.0, min(1.0, $score));
    }

    private function extractTextFromNode(\DOMNode $node): string
    {
        return $this->convertToMarkdown($node);
    }

    /**
     * Converte un nodo DOM in Markdown preservando i link e la formattazione
     * Segue le regole di https://www.markdownguide.org/basic-syntax/
     */
    private function convertToMarkdown(\DOMNode $node, string $baseUrl = ''): string
    {
        $markdown = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                // Testo normale - escape caratteri speciali Markdown
                $text = $child->textContent;
                $text = $this->escapeMarkdownChars($text);
                $markdown .= $text;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);
                
                switch ($tagName) {
                    case 'a':
                        // Link: [text](url) o [text](url "title")
                        $markdown .= $this->convertLinkToMarkdown($child, $baseUrl);
                        break;
                        
                    case 'strong':
                    case 'b':
                        // Bold: **text**
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "**{$innerText}**";
                        break;
                        
                    case 'em':
                    case 'i':
                        // Italic: *text*
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "*{$innerText}*";
                        break;
                        
                    case 'h1':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n# {$innerText}\n\n";
                        break;
                        
                    case 'h2':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n## {$innerText}\n\n";
                        break;
                        
                    case 'h3':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n### {$innerText}\n\n";
                        break;
                        
                    case 'h4':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n#### {$innerText}\n\n";
                        break;
                        
                    case 'h5':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n##### {$innerText}\n\n";
                        break;
                        
                    case 'h6':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n###### {$innerText}\n\n";
                        break;
                        
                    case 'p':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n\n{$innerText}\n\n";
                        break;
                        
                    case 'br':
                        $markdown .= "  \n"; // Two spaces + newline for line break
                        break;
                        
                    case 'li':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n- {$innerText}";
                        break;
                        
                    case 'ul':
                    case 'ol':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= "\n{$innerText}\n";
                        break;
                        
                    case 'blockquote':
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $lines = explode("\n", trim($innerText));
                        $quotedLines = array_map(fn($line) => "> " . $line, $lines);
                        $markdown .= "\n\n" . implode("\n", $quotedLines) . "\n\n";
                        break;
                        
                    case 'code':
                        $innerText = $child->textContent;
                        $markdown .= "`{$innerText}`";
                        break;
                        
                    case 'pre':
                        $innerText = $child->textContent;
                        $markdown .= "\n\n```\n{$innerText}\n```\n\n";
                        break;
                        
                    case 'table':
                        // Tabella completa - preserva struttura markdown
                        \Log::info("🔧 [TABLE-CONVERT] Processing <table> tag", [
                            'baseUrl' => $baseUrl,
                            'table_innerHTML_preview' => substr($child->textContent, 0, 200)
                        ]);
                        $tableMarkdown = $this->convertTableToMarkdown($child, $baseUrl);
                        if (!empty($tableMarkdown)) {
                            \Log::info("✅ [TABLE-CONVERT] Table converted successfully", [
                                'markdown_length' => strlen($tableMarkdown),
                                'markdown_preview' => substr($tableMarkdown, 0, 300)
                            ]);
                            $markdown .= "\n\n" . $tableMarkdown . "\n\n";
                        } else {
                            \Log::warning("❌ [TABLE-CONVERT] Table conversion returned empty", [
                                'baseUrl' => $baseUrl
                            ]);
                        }
                        break;
                        
                    case 'tr':
                        // Riga tabella - separa con | e aggiungi newline
                        $cells = $this->extractTableCells($child, $baseUrl);
                        if (!empty($cells)) {
                            $rowMarkdown = "| " . implode(" | ", $cells) . " |";
                            $markdown .= "\n" . $rowMarkdown;
                            
                            // 🔧 Se è una riga header, aggiungi separatore dopo
                            $isHeaderRow = $this->isTableHeaderRow($cells);
                            \Log::debug("📊 [TABLE-TR] Processing table row", [
                                'is_header_row' => $isHeaderRow,
                                'row_content' => substr($rowMarkdown, 0, 100),
                                'column_count' => count($cells),
                                'cells' => array_map(fn($c) => substr(strip_tags($c), 0, 20), $cells)
                            ]);
                            
                            if ($isHeaderRow) {
                                $columnCount = count($cells);
                                $separator = "| " . str_repeat("--- | ", $columnCount);
                                $separator = rtrim($separator);
                                $markdown .= "\n" . $separator;
                                
                                \Log::info("✅ [TABLE-TR] Added header separator", [
                                    'column_count' => $columnCount,
                                    'separator' => $separator
                                ]);
                            }
                        }
                        break;
                        
                    case 'td':
                    case 'th':
                        // Celle - gestite dal TR parent, ma aggiungi contenuto se processate singolarmente
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= $innerText;
                        break;
                        
                    case 'div':
                    case 'span':
                    case 'section':
                    case 'article':
                        // Salta elementi con classi responsive duplicate
                        if ($child instanceof \DOMElement && $this->shouldSkipResponsiveElement($child)) {
                            break;
                        }
                        // Contenitori generici - processa contenuto
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= $innerText;
                        break;
                        
                    default:
                        // Salta elementi con classi responsive duplicate
                        if ($child instanceof \DOMElement && $this->shouldSkipResponsiveElement($child)) {
                            break;
                        }
                        // Altri elementi - estrai solo il contenuto
                        $innerText = $this->convertToMarkdown($child, $baseUrl);
                        $markdown .= $innerText;
                        break;
                }
            }
        }
        
        return $markdown;
    }

    /**
     * Converte un link HTML in formato Markdown
     */
    private function convertLinkToMarkdown(\DOMElement $linkElement, string $baseUrl): string
    {
        $href = $linkElement->getAttribute('href');
        $title = $linkElement->getAttribute('title');
        $text = trim($linkElement->textContent);
        
        // Se non c'è href, restituisci solo il testo
        if (!$href) {
            return $this->escapeMarkdownCharsForLinkText($text);
        }
        
        // 🔗 ENHANCED: Gestione link JavaScript per paginazione e navigazione
        if (str_starts_with($href, 'javascript:')) {
            // Per link di paginazione e navigazione, preserva il testo ma senza link
            if ($text) {
                $escapedText = $this->escapeMarkdownCharsForLinkText($text);
                // Se è un numero o ha parole di navigazione, mantieni formattazione speciale
                if (preg_match('/^\d+$/', $text) || in_array(strtolower($text), ['precedente', 'successivo', 'previous', 'next'])) {
                    return "**{$escapedText}**"; // Bold per evidenziare elementi di navigazione
                }
                return $escapedText;
            }
            return '';
        }
        
        // Converti URL relativi in assoluti
        $absoluteUrl = $this->resolveUrl($href, $baseUrl);
        
        // Se il testo è vuoto, usa l'URL come testo
        if (!$text) {
            $text = $absoluteUrl;
        } else {
            $text = $this->escapeMarkdownCharsForLinkText($text);
        }
        
        // Formato: [text](url) o [text](url "title")
        if ($title) {
            $title = $this->escapeMarkdownCharsForLinkText($title);
            return "[{$text}]({$absoluteUrl} \"{$title}\")";
        } else {
            return "[{$text}]({$absoluteUrl})";
        }
    }

    /**
     * Escape caratteri speciali Markdown
     */
    private function escapeMarkdownChars(string $text): string
    {
        // Caratteri che hanno significato speciale in Markdown
        // Nota: i punti nei numeri di telefono NON dovrebbero essere escapati
        $specialChars = ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '!', '|'];
        
        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        
        // Escapa i punti solo se NON sono parte di numeri di telefono
        $text = preg_replace('/\.(?!\d)/', '\\.', $text);
        
        return $text;
    }

    /**
     * Escape caratteri speciali Markdown per il testo dei link
     * Più permissivo dell'escaping normale - non escapa punti e altri caratteri sicuri nei link
     */
    private function escapeMarkdownCharsForLinkText(string $text): string
    {
        // Nel testo dei link Markdown solo questi caratteri sono veramente problematici:
        // - [ e ] perché possono chiudere prematuramente il link
        // - \ perché è il carattere di escape
        // I punti, parentesi, ecc. sono sicuri nel testo dei link
        $linkSpecialChars = ['\\', '[', ']'];
        
        foreach ($linkSpecialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        
        return $text;
    }



    /**
     * Estrae il contenuto delle celle da una riga di tabella
     */
    private function extractTableCells(\DOMElement $row, string $baseUrl): array
    {
        $cells = [];
        
        // 🔧 CORREZIONE CRITICA: Estrazione semplificata e più inclusiva
        // Prima tenta approccio responsive, poi fallback inclusivo
        
        $cellElements = [];
        $hasResponsiveClasses = false;
        
        // STEP 1: Analizza se ci sono classi responsive nella riga
        foreach ($row->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && 
                in_array(strtolower($child->nodeName), ['td', 'th'])) {
                
                $class = $child->getAttribute('class');
                if (strpos($class, 'hidden-xs') !== false || strpos($class, 'visible-xs') !== false) {
                    $hasResponsiveClasses = true;
                    break;
                }
            }
        }
        
        // STEP 2: Se ci sono classi responsive, usa logica responsive
        if ($hasResponsiveClasses) {
            \Log::debug("🔄 [TABLE] Logica responsive attivata");
            
            // Preferisci celle desktop-visible (hidden-xs)
            foreach ($row->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && 
                    in_array(strtolower($child->nodeName), ['td', 'th'])) {
                    
                    $class = $child->getAttribute('class');
                    if (strpos($class, 'hidden-xs') !== false) {
                        $cellElements[] = $child;
                    }
                }
            }
            
            // Fallback a celle senza classi responsive
            if (empty($cellElements)) {
                foreach ($row->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE && 
                        in_array(strtolower($child->nodeName), ['td', 'th'])) {
                        
                        $class = $child->getAttribute('class');
                        if (strpos($class, 'visible-xs') === false) {
                            $cellElements[] = $child;
                        }
                    }
                }
            }
        } else {
            // STEP 3: 🚀 MODALITÀ INCLUSIVA - Estrai TUTTE le celle (questo risolve il bug!)
            \Log::debug("✅ [TABLE] Modalità inclusiva attivata - estrarre tutte le celle");
            
            foreach ($row->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && 
                    in_array(strtolower($child->nodeName), ['td', 'th'])) {
                    $cellElements[] = $child;
                }
            }
        }
        
        // STEP 4: Estrai contenuto con logging migliorato
        foreach ($cellElements as $i => $cell) {
            $cellContent = trim($this->convertToMarkdown($cell, $baseUrl));
            // Pulisci contenuto vuoto o con solo whitespace
            $cellContent = preg_replace('/\s+/', ' ', $cellContent);
            $finalContent = $cellContent ?: '-';
            $cells[] = $finalContent;
            
            \Log::debug("📊 [TABLE] Cella {$i}", [
                'raw_content' => substr($cellContent, 0, 50),
                'final_content' => substr($finalContent, 0, 50),
                'is_empty' => empty($cellContent)
            ]);
        }
        
        \Log::info("📋 [TABLE] Riga estratta", [
            'cells_count' => count($cells),
            'cells_preview' => array_map(fn($c) => substr($c, 0, 30), $cells),
            'responsive_mode' => $hasResponsiveClasses
        ]);
        
        return $cells;
    }

    /**
     * 🔧 Converte una tabella HTML completa in Markdown table corretto
     */
    private function convertTableToMarkdown(\DOMElement $table, string $baseUrl = ''): string
    {
        $rows = $table->getElementsByTagName('tr');
        if ($rows->length === 0) {
            return '';
        }
        
        $markdownRows = [];
        $headerProcessed = false;
        
        foreach ($rows as $row) {
            $cells = $this->extractTableCells($row, $baseUrl);
            if (!empty($cells)) {
                $markdownRow = "| " . implode(" | ", $cells) . " |";
                $markdownRows[] = $markdownRow;
                
                // Aggiungi separatore header dopo la prima riga (assumendo sia header)
                if (!$headerProcessed) {
                    $columnCount = count($cells);
                    $separator = "| " . str_repeat("--- | ", $columnCount);
                    $markdownRows[] = rtrim($separator);
                    $headerProcessed = true;
                }
            }
        }
        
        \Log::info("📊 [TABLE-CONVERSION] Tabella convertita", [
            'rows_count' => count($markdownRows),
            'columns_estimated' => $headerProcessed ? (count(explode('|', $markdownRows[0])) - 2) : 0,
            'preview' => substr(implode("\n", array_slice($markdownRows, 0, 3)), 0, 200)
        ]);
        
        return implode("\n", $markdownRows);
    }

    /**
     * 🔧 Preserva la struttura delle tabelle durante la pulizia del markdown
     */
    private function preserveTableStructureInMarkdown(string $markdown): string
    {
        // Identifica le tabelle markdown e proteggi le loro newlines
        $lines = explode("\n", $markdown);
        $cleanedLines = [];
        $inTable = false;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Rileva inizio/fine tabella
            $isTableLine = preg_match('/^\|\s*.*\s*\|$/', $trimmedLine) || 
                          preg_match('/^\|\s*[-:]+\s*\|/', $trimmedLine); // Header separator
            
            if ($isTableLine) {
                $inTable = true;
                // Mantieni le righe di tabella esattamente come sono
                $cleanedLines[] = $trimmedLine;
            } elseif ($inTable && empty($trimmedLine)) {
                // Riga vuota dopo tabella = fine tabella
                $inTable = false;
                $cleanedLines[] = $trimmedLine;
            } elseif (!$inTable) {
                // Fuori dalle tabelle: collassa spazi multipli ma mantieni newlines importanti
                if (!empty($trimmedLine)) {
                    $cleanedLine = preg_replace('/\s+/', ' ', $trimmedLine);
                    $cleanedLines[] = $cleanedLine;
                } else {
                    $cleanedLines[] = '';
                }
            } else {
                // Dentro tabella ma non riga tabella = fine tabella
                $inTable = false;
                if (!empty($trimmedLine)) {
                    $cleanedLine = preg_replace('/\s+/', ' ', $trimmedLine);
                    $cleanedLines[] = $cleanedLine;
                } else {
                    $cleanedLines[] = '';
                }
            }
        }
        
        // Rimuovi righe vuote eccessive (massimo 2 consecutive)
        $result = implode("\n", $cleanedLines);
        $result = preg_replace('/\n{3,}/', "\n\n", $result);
        
        return trim($result);
    }

    /**
     * 🔧 Verifica se questa è una riga header di tabella
     */
    private function isTableHeaderRow(array $cells): bool
    {
        if (empty($cells)) {
            return false;
        }
        
        // Rimuovi HTML e normalizza il contenuto per il controllo
        $cleanCells = array_map(function($cell) {
            return strtolower(trim(strip_tags($cell)));
        }, $cells);
        
        // Pattern comuni per header di tabelle
        $headerPatterns = [
            'nome', 'name', 'title', 'titolo',
            'indirizzo', 'address', 'addr',
            'telefono', 'phone', 'tel', 'cellulare',
            'email', 'mail', 'e-mail',
            'descrizione', 'description', 'desc',
            'servizio', 'service',
            'ufficio', 'office',
            'categoria', 'category', 'cat'
        ];
        
        // Conta quante celle contengono pattern header
        $headerMatches = 0;
        foreach ($cleanCells as $cell) {
            foreach ($headerPatterns as $pattern) {
                if (strpos($cell, $pattern) !== false) {
                    $headerMatches++;
                    break; // Una match per cella
                }
            }
        }
        
        // È header se almeno la metà delle celle matchano pattern header
        $isHeader = $headerMatches >= (count($cells) / 2);
        
        return $isHeader;
    }

    /**
     * Determina se un elemento dovrebbe essere saltato per evitare duplicazioni responsive
     */
    private function shouldSkipResponsiveElement(\DOMElement $element): bool
    {
        $class = $element->getAttribute('class');
        
        // Salta elementi visible-xs se abbiamo già processato la versione desktop
        if (strpos($class, 'visible-xs') !== false) {
            return $this->hasDesktopEquivalent($element);
        }
        
        return false;
    }

    /**
     * Verifica se una riga ha contenuto duplicato desktop/mobile
     */
    private function hasResponsiveDuplicate(\DOMElement $row): bool
    {
        $hasDesktop = false;
        $hasMobile = false;
        
        foreach ($row->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $class = $child->getAttribute('class');
                if (strpos($class, 'hidden-xs') !== false) {
                    $hasDesktop = true;
                }
                if (strpos($class, 'visible-xs') !== false) {
                    $hasMobile = true;
                }
            }
        }
        
        // Se abbiamo entrambe le versioni, salta la mobile
        return $hasDesktop && $hasMobile;
    }

    /**
     * Verifica se esiste un equivalente desktop per un elemento mobile
     */
    private function hasDesktopEquivalent(\DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if (!$parent) return false;
        
        // Cerca elementi siblings con hidden-xs
        foreach ($parent->childNodes as $sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && $sibling !== $element) {
                $class = $sibling->getAttribute('class');
                if (strpos($class, 'hidden-xs') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    private function cleanupContent(string $content): string
    {
        // Normalizza whitespace ma preserva la formattazione Markdown
        // Non toccare gli spazi doppi che indicano line break in Markdown
        $content = preg_replace('/[ \t]+/', ' ', $content); // Solo spazi e tab multipli
        $content = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $content); // Max 2 newline consecutive
        
        // Rimuovi linee troppo corte o che sembrano menu/footer
        $lines = explode("\n", $content);
        $filteredLines = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Preserva linee Markdown importanti anche se corte
            if ($this->isImportantMarkdownLine($line)) {
                $filteredLines[] = $line;
                continue;
            }

            // Preserva linee che contengono contatti (telefono/email) anche se brevi
            // Telefoni: supporta prefisso tel:, separatori punto/spazio/trattino, fissi e mobili
            $isPhoneLine = (bool) preg_match('/(?i)(?:^|\s)tel[:\.]?\s*\+?\d|(?<!\d)(?:\+39\s*)?(?:0\d{1,3}|3\d{2})[\.\s\-]?\d{2,4}[\.\s\-]?\d{3,4}(?!\d)/u', $trimmedLine);
            // Email semplice
            $isEmailLine = (bool) preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $trimmedLine);
            if ($isPhoneLine || $isEmailLine) {
                $filteredLines[] = $line;
                continue;
            }
            
            // Salta linee troppo corte
            if (strlen($trimmedLine) < 10) continue;
            
            // Salta linee che sembrano menu/footer
            if (preg_match('/^(home|contact|about|privacy|terms|menu|search|login|register|©|\d{4})$/i', $trimmedLine)) continue;
            
            $filteredLines[] = $line;
        }
        
        return trim(implode("\n", $filteredLines));
    }

    /**
     * Verifica se una linea è importante per la formattazione Markdown
     */
    private function isImportantMarkdownLine(string $line): bool
    {
        $trimmed = trim($line);
        
        // Headers Markdown
        if (preg_match('/^#{1,6}\s/', $trimmed)) return true;
        
        // Liste
        if (preg_match('/^[-*+]\s/', $trimmed)) return true;
        
        // Blockquotes
        if (preg_match('/^>\s/', $trimmed)) return true;
        
        // Code blocks
        if (preg_match('/^```/', $trimmed)) return true;
        
        // Linee vuote (importanti per separare paragrafi)
        if ($trimmed === '') return true;
        
        // Line breaks (due spazi alla fine)
        if (preg_match('/  $/', $line)) return true;
        
        return false;
    }

    private function extractLinks(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);
        
        $links = [];
        $aElements = $dom->getElementsByTagName('a');
        
        foreach ($aElements as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;
            
            // Converti link relativi in assoluti
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            if ($absoluteUrl && filter_var($absoluteUrl, FILTER_VALIDATE_URL)) {
                // 🔒 Solo HTTPS: scarta http
                $scheme = parse_url($absoluteUrl, PHP_URL_SCHEME);
                if (strtolower((string)$scheme) !== 'https') {
                    continue;
                }
                $links[] = $absoluteUrl;
            }
        }
        
        return array_unique($links);
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            // Normalizza a https se il base è https e il link è http allo stesso host
            $baseParts = parse_url($baseUrl);
            $urlParts = parse_url($url);
            if (($baseParts['scheme'] ?? null) === 'https' && ($urlParts['scheme'] ?? '') === 'http' && ($baseParts['host'] ?? null) === ($urlParts['host'] ?? null)) {
                $urlParts['scheme'] = 'https';
                return $this->buildUrl($urlParts);
            }
            return $url; // Already absolute
        }
        
        $baseParts = parse_url($baseUrl);
        if (!$baseParts) return null;
        
        if ($url[0] === '/') {
            // Absolute path
            $scheme = $baseParts['scheme'] === 'https' ? 'https' : $baseParts['scheme'];
            return $scheme . '://' . $baseParts['host'] . $url;
        } else {
            // Relative path
            $basePath = dirname($baseParts['path'] ?? '/');
            $scheme = $baseParts['scheme'] === 'https' ? 'https' : $baseParts['scheme'];
            return $scheme . '://' . $baseParts['host'] . $basePath . '/' . ltrim($url, '/');
        }
    }

    /**
     * Ricompone un URL da parti parse_url
     */
    private function buildUrl(array $parts): string
    {
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user     = $parts['user'] ?? '';
        $pass     = isset($parts['pass']) ? ':' . $parts['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    private function isUrlAllowed(string $url, ScraperConfig $config): bool
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) return false;
        // 🔒 Consenti solo HTTPS
        if (strtolower((string)($parsedUrl['scheme'] ?? '')) !== 'https') {
            return false;
        }
        
        // Check allowed domains
        if (!empty($config->allowed_domains)) {
            $allowed = false;
            foreach ($config->allowed_domains as $domain) {
                if (str_contains($parsedUrl['host'], $domain)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) return false;
        }

        // Check include patterns
        if (!empty($config->include_patterns)) {
            $included = false;
            foreach ($config->include_patterns as $pattern) {
                $regex = $this->compileUserRegex($pattern);
                if ($regex !== null && @preg_match($regex, $url)) {
                    $included = true;
                    break;
                }
            }
            if (!$included) return false;
        }

        // Check exclude patterns
        if (!empty($config->exclude_patterns)) {
            foreach ($config->exclude_patterns as $pattern) {
                $regex = $this->compileUserRegex($pattern);
                if ($regex !== null && @preg_match($regex, $url)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isLinkOnlyUrl(string $url, ScraperConfig $config): bool
    {
        // 🐛 DEBUG: Log dei pattern link-only
        \Log::debug("🔍 [LINK-ONLY-DEBUG]", [
            'url' => $url,
            'link_only_patterns_type' => gettype($config->link_only_patterns),
            'link_only_patterns_empty' => empty($config->link_only_patterns),
            'link_only_patterns_value' => $config->link_only_patterns,
            'link_only_patterns_count' => is_array($config->link_only_patterns) ? count($config->link_only_patterns) : 'not-array'
        ]);
        
        if (empty($config->link_only_patterns)) {
            return false;
        }
        foreach ($config->link_only_patterns as $pattern) {
            $regex = $this->compileUserRegex($pattern);
            \Log::debug("🔍 [LINK-ONLY-PATTERN-CHECK]", [
                'url' => $url,
                'pattern' => $pattern,
                'regex' => $regex,
                'matches' => $regex !== null && @preg_match($regex, $url)
            ]);
            if ($regex !== null && @preg_match($regex, $url)) {
                \Log::info("✅ [LINK-ONLY-MATCH] URL matched link-only pattern", [
                    'url' => $url,
                    'pattern' => $pattern
                ]);
                return true;
            }
        }
        return false;
    }

    /**
     * Compila un pattern inserito dall'utente in una regex sicura per preg_match.
     * - Se il pattern ha già delimitatori validi (/, #, ~) lo usa così com'è
     * - Altrimenti lo racchiude tra delimitatori ~ con flag i (case-insensitive)
     * - Ritorna null se il pattern è vuoto o non valido
     */
    private function compileUserRegex(?string $pattern): ?string
    {
        $p = trim((string) $pattern);
        if ($p === '') {
            return null;
        }

        // Se sembra già una regex con delimitatori e (eventuali) flag, usala
        // Esempi validi: /foo/i, #bar#, ~baz~ims
        if (preg_match('/^([#~\/]).+\1[imsxuADSUXJ]*$/', $p)) {
            return $p;
        }

        // Altrimenti incapsula con ~ per non confliggere con gli slash
        return '~' . $p . '~i';
    }

    private function shouldSkipKnownUrl(string $url, Tenant $tenant, ScraperConfig $config): bool
    {
        if (!($config->skip_known_urls ?? false)) {
            return false;
        }
        $q = Document::query()->where('tenant_id', $tenant->id)->where('source_url', $url);
        if ($config->recrawl_days && $config->recrawl_days > 0) {
            $threshold = now()->subDays((int) $config->recrawl_days);
            // se ultimo scraping è più vecchio del threshold, non skippare
            $q->where('last_scraped_at', '>=', $threshold);
        }
        return $q->exists();
    }

    /**
     * 🆕 Salva e avvia ingestion per un singolo risultato (salvataggio progressivo)
     */
    private function saveAndIngestSingleResult(array $result, Tenant $tenant, ScraperConfig $config): void
    {
        // Crea contenuto Markdown
        $markdownContent = "# {$result['title']}\n\n";
        $markdownContent .= "**URL:** {$result['url']}\n\n";
        $markdownContent .= "**Scraped on:** " . now()->format('Y-m-d H:i:s') . "\n\n";
        $markdownContent .= "---\n\n";
        $markdownContent .= $result['content'];

        // Calcola hash del contenuto
        $contentHash = hash('sha256', $result['content']);
        
        // Cerca documento esistente per lo stesso URL
        $existingDocument = Document::where('tenant_id', $tenant->id)
            ->where('source_url', $result['url'])
            ->first();
        
        // Se non trovato per URL, cerca per titolo (documenti vecchi senza source_url)
        if (!$existingDocument) {
            $scrapedTitle = $result['title'] . ' (Scraped)';
            $existingDocument = Document::where('tenant_id', $tenant->id)
                ->where('title', $scrapedTitle)
                ->where('source', 'web_scraper')
                ->whereNull('source_url')
                ->first();
            
            if ($existingDocument) {
                $existingDocument->update(['source_url' => $result['url']]);
                \Log::info("Aggiornato source_url per documento vecchio", [
                    'document_id' => $existingDocument->id,
                    'url' => $result['url']
                ]);
            }
        }

        if ($existingDocument) {
            // Controlla se il contenuto è cambiato
            if ($existingDocument->content_hash === $contentHash) {
                // Contenuto identico - aggiorna solo timestamp
                $targetKbId = $config->target_knowledge_base_id;
                $updateData = ['last_scraped_at' => now()];
                if ($targetKbId && $existingDocument->knowledge_base_id !== (int) $targetKbId) {
                    $updateData['knowledge_base_id'] = (int) $targetKbId;
                }
                $existingDocument->update($updateData);
                $this->stats['skipped']++;
                ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'skipped', strlen($result['content']));
                return; // Non avviare ingestion se contenuto uguale
            } else {
                // Contenuto cambiato - aggiorna e re-ingesta
                $this->updateExistingDocument($existingDocument, $result, $markdownContent, $contentHash);
                $this->stats['updated']++;
                ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'updated', strlen($result['content']));
                
                // 🚀 Avvia ingestion del documento aggiornato
                \Log::info("🔄 [PROGRESSIVE-INGEST] Avvio ingestion per documento aggiornato", [
                    'document_id' => $existingDocument->id,
                    'url' => $result['url']
                ]);
                dispatch(new \App\Jobs\IngestUploadedDocumentJob($existingDocument->id));
                return;
            }
        }

        // Nuovo documento - crea e avvia ingestion
        try {
            $document = $this->createNewDocument($tenant, $result, $markdownContent, $contentHash);
            $this->stats['new']++;
            ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'new', strlen($result['content']));
            
            // 🚀 Avvia ingestion immediatamente
            \Log::info("🚀 [PROGRESSIVE-INGEST] Avvio ingestion per nuovo documento", [
                'document_id' => $document->id,
                'url' => $result['url'],
                'title' => $document->title
            ]);
            dispatch(new \App\Jobs\IngestUploadedDocumentJob($document->id));
            
        } catch (\Illuminate\Database\QueryException $e) {
            // Se errore di chiave duplicata, skip
            if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), '23505')) {
                \Log::warning("🔄 Documento probabilmente già creato da processo concorrente", [
                    'url' => $result['url'],
                    'error' => $e->getMessage()
                ]);
                $this->stats['skipped']++;
                ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'skipped', strlen($result['content']));
            } else {
                throw $e;
            }
        }
    }

    private function saveResults(Tenant $tenant, bool $forceReingestion = false): int
    {
        $savedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        
        foreach ($this->results as $result) {
            try {
                // Crea contenuto Markdown
                $markdownContent = "# {$result['title']}\n\n";
                $markdownContent .= "**URL:** {$result['url']}\n\n";
                $markdownContent .= "**Scraped on:** " . now()->format('Y-m-d H:i:s') . "\n\n";
                $markdownContent .= "---\n\n";
                $markdownContent .= $result['content'];

                // Calcola hash del contenuto (solo del contenuto estratto, non delle metadati)
                $contentHash = hash('sha256', $result['content']);
                
                // Cerca documento esistente per lo stesso URL con lock per evitare race conditions
                // Strategia robusta: cerca per source_url, ma se non trova nulla,
                // cerca anche per titolo/path per compatibilità con documenti vecchi
                $existingDocument = Document::where('tenant_id', $tenant->id)
                    ->where('source_url', $result['url'])
                    ->first();
                
                // Se non trovato per URL, cerca per titolo (documenti vecchi senza source_url)
                if (!$existingDocument) {
                    $scrapedTitle = $result['title'] . ' (Scraped)';
                    $existingDocument = Document::where('tenant_id', $tenant->id)
                        ->where('title', $scrapedTitle)
                        ->where('source', 'web_scraper')
                        ->whereNull('source_url') // Solo documenti vecchi senza URL
                        ->first();
                    
                    // Se trovato un documento vecchio, aggiorna il source_url
                    if ($existingDocument) {
                        $existingDocument->update(['source_url' => $result['url']]);
                        \Log::info("Aggiornato source_url per documento vecchio", [
                            'document_id' => $existingDocument->id,
                            'url' => $result['url']
                        ]);
                    }
                }

                if ($existingDocument) {
                    // Controlla se il contenuto è cambiato
                    if ($existingDocument->content_hash === $contentHash && !$forceReingestion) {
                        // Contenuto identico - aggiorna solo timestamp e, se configurata, la KB target
                        $targetKbId = null;
                        try {
                            $cfg = ScraperConfig::where('tenant_id', $tenant->id)->first();
                            $targetKbId = $cfg?->target_knowledge_base_id;
                        } catch (\Throwable) {
                            $targetKbId = null;
                        }
                        $updateData = [ 'last_scraped_at' => now() ];
                        if ($targetKbId && $existingDocument->knowledge_base_id !== (int) $targetKbId) {
                            $updateData['knowledge_base_id'] = (int) $targetKbId;
                        }
                        $existingDocument->update($updateData);
                        $skippedCount++;
                        $this->stats['skipped']++;
                        ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'skipped', strlen($result['content']));
                        \Log::info("Documento invariato, skip", ['url' => $result['url']]);
                        continue;
                    } else {
                        // Contenuto cambiato O force reingestion - aggiorna documento esistente
                        if ($forceReingestion && $existingDocument->content_hash === $contentHash) {
                            \Log::info("🔄 Force reingestion - stesso contenuto ma aggiorno comunque", [
                                'url' => $result['url'],
                                'document_id' => $existingDocument->id
                            ]);
                        }
                        $this->updateExistingDocument($existingDocument, $result, $markdownContent, $contentHash);
                        $updatedCount++;
                        $this->stats['updated']++;
                        ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'updated', strlen($result['content']));
                        continue;
                    }
                }

                // Nuovo documento - crea ex-novo con protezione race condition
                try {
                    $document = $this->createNewDocument($tenant, $result, $markdownContent, $contentHash);
                    $savedCount++;
                    $this->stats['new']++;
                    ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'new', strlen($result['content']));
                } catch (\Illuminate\Database\QueryException $e) {
                    // Se errore di chiave duplicata, probabilmente un altro processo ha già creato il documento
                    if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), '23505')) {
                        \Log::warning("🔄 Documento probabilmente già creato da processo concorrente", [
                            'url' => $result['url'],
                            'error' => $e->getMessage()
                        ]);
                        $skippedCount++;
                        $this->stats['skipped']++;
                        ScraperLogger::urlSuccess($this->sessionId, $result['url'], 'skipped', strlen($result['content']));
                    } else {
                        throw $e; // Rilancia se non è un errore di duplicazione
                    }
                }
                
            } catch (\Exception $e) {
                \Log::error("Errore salvataggio documento scraped", [
                    'url' => $result['url'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        \Log::info("Scraping completato", [
            'tenant_id' => $tenant->id,
            'new_documents' => $savedCount,
            'updated_documents' => $updatedCount,
            'skipped_documents' => $skippedCount
        ]);
        
        return $savedCount + $updatedCount;
    }

    private function createNewDocument(Tenant $tenant, array $result, string $markdownContent, string $contentHash): Document
    {
        // Genera nome file unico basato su URL + titolo per evitare duplicati
        $urlSlug = $this->generateUniqueFilenameFromUrl($result['url']);
        $titleSlug = Str::slug($result['title']);
        
        // Combina URL e titolo per nome più specifico
        $baseFilename = $urlSlug . (!empty($titleSlug) ? '-' . substr($titleSlug, 0, 30) : '');
        $filename = $baseFilename . '-v1.md';
        $path = "scraped/{$tenant->id}/" . $filename;

        // Assicurati che il nome file sia unico e allinea versione con DB
        $versionNumber = 1;
        while (Storage::disk('public')->exists($path)) {
            $versionNumber++;
            $filename = $baseFilename . "-v{$versionNumber}.md";
            $path = "scraped/{$tenant->id}/" . $filename;
        }

        // Salva file (forza UTF-8 corretto ed evita mojibake)
        $markdownContent = $this->normalizeMarkdownEncoding($markdownContent);
        Storage::disk('public')->put($path, $markdownContent);

        // Crea record documento
        // Associa alla KB target da config se presente, altrimenti KB di default
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        $targetKbId = $config->target_knowledge_base_id ?? null;
        if (!$targetKbId) {
            $targetKbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->where('is_default', true)->value('id');
        }

        // Prepara metadata avanzati
        $metadata = [];
        if (isset($result['quality_analysis'])) {
            $metadata['quality_analysis'] = $result['quality_analysis'];
        }

        $document = Document::create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $targetKbId,
            'title' => $result['title'] . ' (Scraped)',
            'path' => $path,
            'source_url' => $result['url'],
            'content_hash' => $contentHash,
            'last_scraped_at' => now(),
            'scrape_version' => $versionNumber, // ⚡ Aligned with actual file version
            'size' => strlen($markdownContent),
            'ingestion_status' => 'pending',
            'source' => 'web_scraper',
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);

        // Avvia job di ingestion
        IngestUploadedDocumentJob::dispatch($document->id);
        
        // 📎 Scarica documenti collegati se abilitato
        if ($config && $config->download_linked_documents) {
            $this->downloadLinkedDocuments($markdownContent, $tenant, $config, $result['url']);
        }
        
        \Log::info("Nuovo documento creato", [
            'url' => $result['url'],
            'document_id' => $document->id,
            'hash' => substr($contentHash, 0, 8)
        ]);

        return $document;
    }

    /**
     * 🌐 Detect if this is a JavaScript-rendered SPA site
     */
    private function isJavaScriptRenderedSite(string $html, string $url): bool
    {
        // Check for common SPA indicators
        $jsIndicators = [
            'ng-version',           // Angular
            'app-root',             // Angular
            'react-root',           // React
            'vue-app',              // Vue
            '_nghost-',             // Angular compiled
            'router-outlet',        // Angular routing
            'data-ng-',             // AngularJS
            'v-if',                 // Vue directives
        ];
        
        $jsIndicatorCount = 0;
        foreach ($jsIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                $jsIndicatorCount++;
            }
        }
        
        // 🚀 FIX: Se l'HTML proviene da rendering JavaScript (Puppeteer),
        // dovrebbe essere SEMPRE trattato come JS site per usare l'estrazione appropriata
        // Abbassata soglia da >= 2 a >= 1 per essere più inclusivi
        $isDomainSpa = false;
        
        // Più permissivo: anche 1 solo indicatore JS significa che è un sito JS-rendered
        $isJsSite = $jsIndicatorCount >= 1 || $isDomainSpa;
        
        \Log::debug("🔍 JavaScript site detection", [
            'url' => $url,
            'js_indicators_found' => $jsIndicatorCount,
            'is_domain_spa' => $isDomainSpa,
            'is_js_site' => $isJsSite,
            'threshold' => 'lowered_to_1'
        ]);
        
        return $isJsSite;
    }
    
    /**
     * 🚀 Extract content from JavaScript-rendered sites using HTML-to-Markdown
     */
    private function extractFromJavaScriptSite(string $html, string $url): ?array
    {
        try {
            // 🚀 STEP 1: Try Readability.php first (same as non-JS sites!)
            \Log::debug("📖 [JS-EXTRACTION] Trying Readability.php extraction on JavaScript site", [
                'url' => $url,
                'input_html_length' => strlen($html),
                'html_preview' => substr($html, 0, 300)
            ]);
            $readabilityResult = $this->extractWithReadability($html, $url);
            
            if ($readabilityResult && strlen($readabilityResult['content']) > 100) {
                \Log::info("✅ [JS-EXTRACTION] Readability.php extraction successful", [
                    'url' => $url,
                    'method' => 'readability',
                    'content_length' => strlen($readabilityResult['content']),
                    'content_preview' => substr($readabilityResult['content'], 0, 200),
                    'extraction_efficiency' => strlen($html) > 0 ? round((strlen($readabilityResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
                ]);
                return $readabilityResult;
            }
            
            \Log::warning("⚠️ [JS-EXTRACTION] Readability.php failed/insufficient, trying smart patterns", [
                'url' => $url,
                'readability_content_length' => $readabilityResult ? strlen($readabilityResult['content']) : 0,
                'input_html_length' => strlen($html),
                'readability_efficiency' => ($readabilityResult && strlen($html) > 0) ? round((strlen($readabilityResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
            ]);
            
            // 🚀 STEP 2: Try smart content extraction (uses our Angular patterns!)
            \Log::debug("🧠 [JS-EXTRACTION] Trying smart content extraction on JavaScript site", [
                'url' => $url,
                'input_html_length' => strlen($html),
                'html_preview' => substr($html, 0, 300)
            ]);
            $smartResult = $this->trySmartContentExtraction($html, $url);
            
            if ($smartResult && strlen($smartResult['content']) > 100) {
                \Log::info("🎯 [JS-EXTRACTION] Smart extraction successful", [
                    'url' => $url,
                    'pattern_used' => 'smart_patterns',
                    'content_length' => strlen($smartResult['content']),
                    'content_preview' => substr($smartResult['content'], 0, 200),
                    'extraction_efficiency' => strlen($html) > 0 ? round((strlen($smartResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
                ]);
                return $smartResult;
            }
            
            \Log::warning("⚠️ [JS-EXTRACTION] Smart extraction failed/insufficient, falling back to manual DOM", [
                'url' => $url,
                'smart_content_length' => $smartResult ? strlen($smartResult['content']) : 0,
                'input_html_length' => strlen($html),
                'smart_efficiency' => ($smartResult && strlen($html) > 0) ? round((strlen($smartResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
            ]);
            
            // 🚀 STEP 3: Fallback to manual DOM extraction (same as non-JS sites!)
            \Log::debug("🎯 [JS-EXTRACTION] Using manual DOM extraction (same as non-JS sites)", [
                'url' => $url,
                'original_html_length' => strlen($html)
            ]);
            $manualResult = $this->extractWithManualDOM($html, $url, null, null, null);
            
            if ($manualResult && strlen($manualResult['content']) > 100) {
                \Log::info("✅ [JS-EXTRACTION] Manual DOM extraction successful", [
                    'url' => $url,
                    'method' => 'manual_dom',
                    'content_length' => strlen($manualResult['content']),
                    'content_preview' => substr($manualResult['content'], 0, 200),
                    'extraction_efficiency' => strlen($html) > 0 ? round((strlen($manualResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
                ]);
                return $manualResult;
            }
            
            \Log::warning("⚠️ [JS-EXTRACTION] All primary methods failed, trying Readability.php as last resort", [
                'url' => $url,
                'manual_content_length' => $manualResult ? strlen($manualResult['content']) : 0,
                'input_html_length' => strlen($html),
                'manual_efficiency' => ($manualResult && strlen($html) > 0) ? round((strlen($manualResult['content']) / strlen($html)) * 100, 2) . '%' : '0%'
            ]);
            
            // 🚀 FALLBACK FINALE: Prova Readability.php come ultima risorsa
            $readabilityFallback = $this->extractWithReadability($html, $url);
            if ($readabilityFallback && strlen($readabilityFallback['content']) > 100) {
                \Log::info("✅ [JS-EXTRACTION] Readability.php last-resort fallback successful", [
                    'url' => $url,
                    'method' => 'readability_last_resort',
                    'content_length' => strlen($readabilityFallback['content']),
                    'content_preview' => substr($readabilityFallback['content'], 0, 200)
                ]);
                return $readabilityFallback;
            }
            
            \Log::warning("❌ [JS-EXTRACTION] All extraction methods failed including Readability.php", [
                'url' => $url,
                'readability_content_length' => $readabilityFallback ? strlen($readabilityFallback['content']) : 0
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            \Log::warning("❌ JavaScript site extraction failed", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * 🧹 Clean HTML before converting to Markdown (optimized for Angular SPAs)
     */
    private function cleanHtmlForMarkdown(string $html): string
    {
        // Pre-process: Replace Angular component tags with standard HTML
        $html = $this->replaceAngularTags($html);
        
        // Load HTML into DOM
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();
        
        // Remove unwanted elements
        $elementsToRemove = [
            '//script',
            '//style', 
            '//nav',
            '//footer',
            '//header',
            '//aside',
            '//*[contains(@class, "nav")]',
            '//*[contains(@class, "navbar")]', 
            '//*[contains(@class, "menu")]',
            '//*[contains(@class, "footer")]',
            '//*[contains(@class, "header")]',
            '//*[contains(@class, "ads")]',
            '//*[contains(@class, "advertisement")]',
            '//*[contains(@class, "cookie")]',
            '//*[contains(@class, "privacy")]',
        ];
        
        $xpath = new \DOMXPath($dom);
        foreach ($elementsToRemove as $selector) {
            $nodes = $xpath->query($selector);
            foreach ($nodes as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        
        // Try to find main content area - PRIORITY to testolungo content
        $mainContentSelectors = [
            '//*[contains(@class, "testolungo")]',  // 🚀 PRIORITY: Specific content divs first
            '//*[contains(@class, "articolo-dettaglio-testo")]', // 🆕 Palmanova specific (1003 chars)
            '//*[contains(@class, "article-container")]', // 🆕 Palmanova specific (755 chars)
            '//*[@role="main"]',                     // 🆕 ARIA role main (755 chars)
            '//*[contains(@class, "main-content")]', // Our injected content
            '//*[contains(@class, "container-fluid")]', // 🆕 Bootstrap containers (comuni italiani)
            '//*[contains(@class, "page-content")]', // 🆕 Page content wrapper
            '//*[contains(@class, "site-content")]', // 🆕 Site content wrapper
            '//main',
            '//article', 
            '//*[@id="main"]',
            '//*[@id="content"]',
            '//*[@id="page-content"]',               // 🆕 Page content ID
            '//*[contains(@class, "content")]',
            '//*[contains(@class, "main")]',
            '//*[contains(@class, "wrapper")]',      // 🆕 Generic wrapper
            '//body' // fallback
        ];
        
        foreach ($mainContentSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $mainNode = $nodes->item(0);
                $cleanHtml = $dom->saveHTML($mainNode);
                \Log::debug("🎯 Found main content with selector", [
                    'selector' => $selector,
                    'content_length' => strlen($cleanHtml)
                ]);
                return $cleanHtml;
            }
        }
        
        // Fallback: return cleaned full body
        return $dom->saveHTML();
    }
    
    /**
     * 🔧 Replace Angular component tags with standard HTML divs to preserve content
     */
    private function replaceAngularTags(string $html): string
    {
        // Map Angular components to standard HTML
        $replacements = [
            '<app-switcher>' => '<div class="app-switcher">',
            '</app-switcher>' => '</div>',
            '<app-md-procedimento>' => '<div class="md-procedimento">',
            '</app-md-procedimento>' => '</div>',
            '<app-md-files>' => '<div class="md-files">',
            '</app-md-files>' => '</div>',
            '<app-converter-file>' => '<div class="converter-file">',
            '</app-converter-file>' => '</div>',
            // Add more as needed
        ];
        
        // 🚀 PRIORITY: Extract and preserve testolungo content before tag replacement
        $testolungoContent = '';
        if (preg_match('/<div class="testolungo[^"]*"[^>]*>(.*?)<\/div>/s', $html, $matches)) {
            $testolungoContent = $matches[1];
            \Log::debug("🎯 Extracted testolungo content", [
                'content_length' => strlen($testolungoContent),
                'preview' => substr(strip_tags($testolungoContent), 0, 200)
            ]);
        }
        
        // Apply replacements
        $cleanHtml = str_replace(array_keys($replacements), array_values($replacements), $html);
        
        // 🚀 ENHANCEMENT: Ensure testolungo content is prominently placed
        if (!empty($testolungoContent)) {
            // Place testolungo content at the beginning for better visibility
            $cleanHtml = '<div class="main-content">' . $testolungoContent . '</div>' . $cleanHtml;
        }
        
        \Log::debug("🔧 Angular tags replacement", [
            'original_length' => strlen($html),
            'replaced_length' => strlen($cleanHtml),
            'replacements_applied' => count($replacements),
            'testolungo_preserved' => !empty($testolungoContent)
        ]);
        
        return $cleanHtml;
    }
    
    /**
     * 🧹 Clean up markdown content
     */
    private function cleanupMarkdown(string $markdown): string
    {
        // Remove excessive whitespace
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        
        // Remove empty links
        $markdown = preg_replace('/\[\s*\]\([^)]*\)/', '', $markdown);
        
        // Clean up malformed links
        $markdown = preg_replace('/\[([^\]]+)\]\(\s*\)/', '$1', $markdown);
        
        // Remove lines that are just punctuation or symbols
        $lines = explode("\n", $markdown);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Skip lines that are just punctuation/symbols or very short
            if (strlen($trimmed) > 3 && !preg_match('/^[^\w]*$/', $trimmed)) {
                $cleanLines[] = $line;
            }
        }
        
        return trim(implode("\n", $cleanLines));
    }

    /**
     * Normalizza il contenuto Markdown in UTF-8 e corregge i caratteri mojibake più comuni.
     */
    private function normalizeMarkdownEncoding(string $content): string
    {
        // Se arriva come ISO-8859-1/Windows-1252, converti a UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Fix mojibake comuni (Ã , Ã©, Ã¨, â€™, ecc.)
        $replacements = [
            "Ã€" => "À", "Ã�" => "Á", "Ã‚" => "Â", "Ãƒ" => "Ã", "Ã„" => "Ä", "Ã…" => "Å",
            "Ã " => "à", "Ã¡" => "á", "Ã¢" => "â", "Ã£" => "ã", "Ã¤" => "ä", "Ã¥" => "å",
            "Ãˆ" => "È", "Ã‰" => "É", "ÃŠ" => "Ê", "Ã‹" => "Ë",
            "Ã¨" => "è", "Ã©" => "é", "Ãª" => "ê", "Ã«" => "ë",
            "ÃŒ" => "Ì", "Ã�" => "Í", "ÃŽ" => "Î", "Ã�" => "Ï",
            "Ã¬" => "ì", "Ã­" => "í", "Ã®" => "î", "Ã¯" => "ï",
            "Ã’" => "Ò", "Ã“" => "Ó", "Ã”" => "Ô", "Ã•" => "Õ", "Ã–" => "Ö",
            "Ã²" => "ò", "Ã³" => "ó", "Ã´" => "ô", "Ãµ" => "õ", "Ã¶" => "ö",
            "Ã™" => "Ù", "Ãš" => "Ú", "Ã›" => "Û", "Ãœ" => "Ü",
            "Ã¹" => "ù", "Ãº" => "ú", "Ã»" => "û", "Ã¼" => "ü",
            "Ã‡" => "Ç", "Ã§" => "ç",
            "Ã‘" => "Ñ", "Ã±" => "ñ",
            "Ã¿" => "ÿ", "Ã�" => "Ÿ",
            "â€™" => "’", "â€˜" => "‘", "â€œ" => "“", "â€" => "”", "â€“" => "–", "â€”" => "—",
            "â€¦" => "…", "â‚¬" => "€", "â€¢" => "•",
            // Casi frequenti italiani
            "lâ€™" => "l'", "dâ€™" => "d'", "Lâ€™" => "L'", "Dâ€™" => "D'",
        ];

        // 🚀 FIRST PASS: Fix complex triple-encoded patterns from user's example
        $complexPatterns = [
            "/lÃ¢Â€Â™/u" => "l'",        // l'Ente 
            "/dÃ¢Â€Â™/u" => "d'",        // d'Identità
            "/allÃ¢Â€Â™/u" => "all'",    // all'atto
            "/dellÃ¢Â€Â™/u" => "dell'",  // dell'avviso
            "/nellÃ¢Â€Â™/u" => "nell'",  // nell'avviso
            "/sullÃ¢Â€Â™/u" => "sull'",  // sull'avviso
            "/unÃ¢Â€Â™/u" => "un'",      // un'autorizzazione
            "/ÃƒÂˆ/u" => "È",            // È necessario
            "/puÃ²/u" => "può",          // può essere
            "/perÃ²/u" => "però",        // però
            "/piÃ¹/u" => "più",          // più
            "/cittÃ /u" => "città",      // città
            "/qualitÃ /u" => "qualità",  // qualità
            "/modalitÃ /u" => "modalità", // modalità
            "/identitÃ /u" => "identità", // identità
            "/pubblicitÃ /u" => "pubblicità", // pubblicità
            "/UnitÃ /u" => "Unità",      // Unità Organizzativa
            "/avrÃ /u" => "avrà",        // avrà generato
            
            // 🚀 SECOND WAVE: Fix remaining â patterns from grep results
            "/dellâEnte/u" => "dell'Ente",
            "/dellâavviso/u" => "dell'avviso",  
            "/dellâeventuale/u" => "dell'eventuale",
            "/allâimporto/u" => "all'importo",
            "/sullâavviso/u" => "sull'avviso", 
            "/allâatto/u" => "all'atto",
            "/lâEnte/u" => "l'Ente",
            "/lâavviso/u" => "l'avviso",
            "/lâimporto/u" => "l'importo",
            "/unâ/u" => "un'",
            
            // 🚀 THIRD WAVE: Additional patterns from actual scraped files
            "/lâavvio/u" => "l'avvio",
            "/lâAnno/u" => "l'Anno",
            "/lâingresso/u" => "l'ingresso",
            "/LâINGRESSO/u" => "L'INGRESSO",
            "/lâentrata/u" => "l'entrata",
            "/lâuscita/u" => "l'uscita",
            "/lâaccesso/u" => "l'accesso",
        ];
        
        foreach ($complexPatterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        // 🚀 FINAL PASS: Direct string replacements for stubborn â patterns
        $directReplacements = [
            "dellâEnte" => "dell'Ente",   // From file: dell â Ente (with specific â char)
            "dellâavviso" => "dell'avviso",  
            "dellâeventuale" => "dell'eventuale",
            "allâimporto" => "all'importo",
            "sullâavviso" => "sull'avviso", 
            "allâatto" => "all'atto",
            "lâEnte" => "l'Ente",
            "lâavviso" => "l'avviso",
            "lâimporto" => "l'importo",
            "lâoccupazione" => "l'occupazione",
            "lâimposta" => "l'imposta",
            "unâ" => "un'",
            
            // Additional common mojibake patterns
            "lâavvio" => "l'avvio",
            "lâAnno" => "l'Anno", 
            "lâingresso" => "l'ingresso",
            "LâINGRESSO" => "L'INGRESSO",
            "lâentrata" => "l'entrata",
            "lâuscita" => "l'uscita", 
            "lâaccesso" => "l'accesso",
            "settembreÂ " => "settembre ",  // Remove trailing Â
            "ancheÂ " => "anche ",          // Remove trailing Â
            "Â·" => "·",                    // Fix bullet point
            " â " => " – ",                 // Fix dashes (A â B â C)
            "(A â B â C)" => "(A – B – C)", // Specific pattern
            
            // Add the exact pattern from user's example (copy-paste)
            "Il pagamento attraverso il sito dellâEnte può" => "Il pagamento attraverso il sito dell'Ente può",
        ];
        
        // Apply standard replacements first
        $content = strtr($content, $replacements);
        
        // Then apply direct replacements (these should take priority)
        $content = strtr($content, $directReplacements);
        
        // 🚀 EXTRA CLEANUP: Generic patterns for remaining issues  
        $content = preg_replace('/([a-zA-Z])â([a-zA-Z])/', '$1\'$2', $content); // Generic lâword -> l'word
        $content = preg_replace('/Â\s*/', ' ', $content); // Remove stray Â characters
        $content = str_replace(' â ', ' – ', $content); // Fix remaining dashes
        
        // 🚀 FINAL NUCLEAR OPTION: Direct replacement of exact patterns from preview
        $finalCleanup = [
            "lâavvio" => "l'avvio",
            "lâAnno" => "l'Anno",
            "lâingresso" => "l'ingresso",
            "LâINGRESSO" => "L'INGRESSO",
            "lâentrata" => "l'entrata", 
            "lâuscita" => "l'uscita",
            "lâaccesso" => "l'accesso",
        ];
        $content = strtr($content, $finalCleanup);

        // Garantisci UTF-8 valido
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = utf8_encode($content);
        }

        return $content;
    }

    /**
     * Genera un nome file unico dall'URL per evitare nomi duplicati
     */
    private function generateUniqueFilenameFromUrl(string $url): string
    {
        $parsedUrl = parse_url($url);
        $domain = str_replace(['www.', '.'], ['', ''], $parsedUrl['host'] ?? 'unknown');
        $path = $parsedUrl['path'] ?? '';
        
        // Estrai il segmento più significativo del percorso
        $pathSegments = array_filter(explode('/', trim($path, '/')));
        $pathSuffix = '';
        
        if (!empty($pathSegments)) {
            // Prendi gli ultimi 2-3 segmenti del percorso per specificità
            $relevantSegments = array_slice($pathSegments, -3);
            $pathSuffix = '-' . implode('-', array_map(function($segment) {
                // Pulisci e accorcia i segmenti
                $clean = preg_replace('/[^a-zA-Z0-9-]/', '', $segment);
                return substr($clean, 0, 15); // Max 15 chars per segmento
            }, $relevantSegments));
        }
        
        $baseFilename = $domain . $pathSuffix;
        return substr($baseFilename, 0, 60); // Limite totale 60 chars
    }

    /**
     * 🧠 Smart Content Extraction - Riconoscimento automatico pattern comuni
     */
    private function trySmartContentExtraction(string $html, string $url): ?array
    {
        \Log::debug("🧠 Starting smart content extraction", ['url' => $url]);

        // Load content patterns from configuration (extensible and maintainable)
        $contentPatterns = config('scraper-patterns.content_patterns', []);
        
        // ❌ RIMOSSO: Tenant-specific patterns non più supportati
        
        // Sort patterns by priority (ascending)
        usort($contentPatterns, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        // Helper per eseguire estrazioni multiple e concatenare
        $extractUsingPatterns = function(array $patterns) use ($html, $url): ?array {
            $collectedParts = [];
            $matchedDetails = [];
            foreach ($patterns as $pattern) {
                $regex = $pattern['regex'];
                $minLen = (int) ($pattern['min_length'] ?? 0);
                $name = $pattern['name'] ?? 'unknown';
                $desc = $pattern['description'] ?? '';

                $matchesAll = [];
                if (@preg_match_all($regex, $html, $matchesAll, PREG_SET_ORDER)) {
                    foreach ($matchesAll as $m) {
                        if (!isset($m[1])) {
                            continue;
                        }
                        $extractedHtml = $m[1];
                        $cleanContent = $this->processExtractedContent($extractedHtml, $url);
                        if (strlen($cleanContent) >= max(60, $minLen)) {
                            $collectedParts[] = $cleanContent;
                            $matchedDetails[] = [
                                'pattern' => $name,
                                'description' => $desc,
                                'length' => strlen($cleanContent),
                                'preview' => substr($cleanContent, 0, 120)
                            ];
                        }
                    }
                }
            }

            $combined = trim(implode("\n\n", $collectedParts));
            if (strlen($combined) >= 250) {
                \Log::info("🎯 Smart extraction successful (combined)", [
                    'url' => $url,
                    'parts' => count($collectedParts),
                    'total_length' => strlen($combined),
                    'matched' => $matchedDetails
                ]);
                return [
                    'title' => $this->extractTitleFromHtml($html) ?: parse_url($url, PHP_URL_HOST),
                    'content' => $combined
                ];
            }
            return null;
        };

        // 1) Prova PRIMA i pattern specifici del tenant (override reale, non solo priorità)
        if (!empty($tenantPatterns)) {
            $result = $extractUsingPatterns($tenantPatterns);
            if ($result) {
                return $result;
            }
        }

        // 2) Se non basta, prova i pattern globali
        if (!empty($contentPatterns)) {
            $result = $extractUsingPatterns($contentPatterns);
            if ($result) {
                return $result;
            }
        }

        \Log::debug("🔍 No smart patterns matched", ['url' => $url]);
        return null;
    }

    // ❌ RIMOSSO: getTenantExtractionPatterns() - metodo non più necessario

    /**
     * 🧹 Process and clean extracted HTML content
     */
    private function processExtractedContent(string $html, string $url = ''): string
    {
        // Get cleaning rules from configuration
        $removeContainers = config('scraper-patterns.cleaning_rules.remove_containers', [
            'nav', 'menu', 'sidebar', 'ads', 'banner', 'footer', 'header'
        ]);
        
        // Build regex pattern for containers to remove
        $containerPattern = implode('|', array_map('preg_quote', $removeContainers));
        $html = preg_replace('/<div[^>]*class="[^"]*(?:' . $containerPattern . ')[^"]*"[^>]*>.*?<\/div>/is', '', $html);
        
        // 🔗 PRESERVE LINKS: Convert HTML to markdown to preserve links before stripping tags
        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            // Ensure proper HTML structure
            $wrappedHtml = '<html><body>' . $html . '</body></html>';
            $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
            libxml_clear_errors();
            
            $bodyElements = $dom->getElementsByTagName('body');
            if ($bodyElements->length > 0) {
                // Convert HTML to markdown preserving links
                $baseUrl = $url ? $this->extractBaseUrl($url) : '';
                $markdown = $this->convertToMarkdown($bodyElements->item(0), $baseUrl);
                
                // Clean up the markdown PRESERVANDO la struttura delle tabelle
                $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
                // 🔧 PRESERVE TABLE STRUCTURE: Non collassare newlines nelle tabelle
                $markdown = $this->preserveTableStructureInMarkdown($markdown);
                
                return $markdown;
            }
        } catch (\Exception $e) {
            \Log::warning("🔗 HTML to markdown conversion failed in processExtractedContent", [
                'error' => $e->getMessage(),
                'html_length' => strlen($html)
            ]);
        }
        
        // Fallback: Convert to clean text (old behavior)
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        return $text;
    }

    /**
     * 🌐 Extract base URL from full URL for relative link resolution
     */
    private function extractBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return '';
        }
        
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        
        return $scheme . '://' . $host . $port;
    }

    /**
     * 🏷️ Extract title from HTML
     */
    private function extractTitleFromHtml(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        
        // Fallback: look for h1
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        
        return null;
    }

    private function updateExistingDocument(Document $existingDocument, array $result, string $markdownContent, string $contentHash): void
    {
        // Incrementa versione
        $newVersion = $existingDocument->scrape_version + 1;
        
        // Genera nuovo nome file con versione
        $baseFilename = Str::slug($result['title']);
        $filename = $baseFilename . "-v{$newVersion}.md";
        $newPath = "scraped/{$existingDocument->tenant_id}/" . $filename;

        // Salva nuova versione del file
        $markdownContent = $this->normalizeMarkdownEncoding($markdownContent);
        Storage::disk('public')->put($newPath, $markdownContent);

        // Opzionale: Rimuovi file vecchio per risparmiare spazio
        if ($existingDocument->path && Storage::disk('public')->exists($existingDocument->path)) {
            Storage::disk('public')->delete($existingDocument->path);
        }

        // Se configurata una KB target nello scraper, aggiorna anche la KB del documento
        $config = ScraperConfig::where('tenant_id', $existingDocument->tenant_id)->first();
        $targetKbId = $config->target_knowledge_base_id ?? null;

        // Prepara metadata avanzati (merge con esistenti)
        $existingMetadata = $existingDocument->metadata ?? [];
        $newMetadata = $existingMetadata;
        
        if (isset($result['quality_analysis'])) {
            $newMetadata['quality_analysis'] = $result['quality_analysis'];
            $newMetadata['quality_history'] = $existingMetadata['quality_history'] ?? [];
            // Mantieni storia delle ultime 5 analisi qualità
            array_unshift($newMetadata['quality_history'], [
                'version' => $existingDocument->scrape_version,
                'analysis' => $existingMetadata['quality_analysis'] ?? null,
                'scraped_at' => $existingDocument->last_scraped_at
            ]);
            $newMetadata['quality_history'] = array_slice($newMetadata['quality_history'], 0, 5);
        }

        // Aggiorna record documento
        $existingDocument->update([
            'title' => $result['title'] . ' (Scraped)',
            'path' => $newPath,
            'content_hash' => $contentHash,
            'last_scraped_at' => now(),
            'scrape_version' => $newVersion,
            'size' => strlen($markdownContent),
            'ingestion_status' => 'pending',
            'knowledge_base_id' => $targetKbId ?: $existingDocument->knowledge_base_id,
            'metadata' => !empty($newMetadata) ? $newMetadata : null
        ]);

        // Avvia re-ingestion
        IngestUploadedDocumentJob::dispatch($existingDocument->id);
        
        // 📎 Scarica documenti collegati se abilitato
        if ($config && $config->download_linked_documents) {
            $this->downloadLinkedDocuments($markdownContent, $existingDocument->tenant, $config, $result['url']);
        }
        
        \Log::info("Documento aggiornato", [
            'url' => $result['url'],
            'document_id' => $existingDocument->id,
            'old_hash' => substr($existingDocument->getOriginal('content_hash') ?? '', 0, 8),
            'new_hash' => substr($contentHash, 0, 8),
            'version' => $newVersion
        ]);
    }

    /**
     * 🎯 FUNZIONALITÀ NUOVA: Scraping di un singolo URL
     * 
     * @param int $tenantId
     * @param string $url
     * @param bool $force Se true, sovrascrive documenti esistenti
     * @param int|null $knowledgeBaseId KB di destinazione (opzionale)
     * @return array
     */
    public function scrapeSingleUrl(int $tenantId, string $url, bool $force = false, ?int $knowledgeBaseId = null): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        \Log::info("🎯 [SINGLE-URL] Inizio scraping singolo URL", [
            'tenant_id' => $tenantId,
            'url' => $url,
            'force' => $force,
            'knowledge_base_id' => $knowledgeBaseId
        ]);

        $this->visitedUrls = [];
        $this->results = [];
        $this->stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];

        try {
            // Verifica se il documento esiste già
            $existingDoc = Document::where('tenant_id', $tenantId)
                ->where('source_url', $url)
                ->first();

            if ($existingDoc && !$force) {
                \Log::info("📄 [SINGLE-URL] Documento già esistente (usare --force per sovrascrivere)", [
                    'existing_doc_id' => $existingDoc->id,
                    'existing_title' => $existingDoc->title
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Documento già esistente. Usa --force per sovrascrivere.',
                    'existing_document' => [
                        'id' => $existingDoc->id,
                        'title' => $existingDoc->title,
                        'path' => $existingDoc->path,
                        'created_at' => $existingDoc->created_at
                    ]
                ];
            }

            // Scraping del singolo URL
            $result = $this->scrapeSingleUrlInternal($url, $tenant, $knowledgeBaseId, $force);
            
            if ($result) {
                $this->results[] = $result;
                
                // Salva il risultato (force=true per re-scraping)
                $savedCount = $this->saveResults($tenant, $force);
                
                \Log::info("✅ [SINGLE-URL] Scraping completato", [
                    'url' => $url,
                    'saved_count' => $savedCount,
                    'force_mode' => $force
                ]);

                return [
                    'success' => true,
                    'url' => $url,
                    'saved_count' => $savedCount,
                    'stats' => $this->stats,
                    'document' => $result
                ];
            } else {
                \Log::warning("❌ [SINGLE-URL] Scraping fallito", ['url' => $url]);
                
                return [
                    'success' => false,
                    'message' => 'Impossibile estrarre contenuto dall\'URL',
                    'url' => $url
                ];
            }

        } catch (\Exception $e) {
            \Log::error("💥 [SINGLE-URL] Errore durante scraping", [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Errore durante lo scraping: ' . $e->getMessage(),
                'url' => $url
            ];
        }
    }

    /**
     * 🔄 FUNZIONALITÀ NUOVA: Force re-scraping di documento esistente
     * 
     * @param int $documentId
     * @return array
     */
    public function forceRescrapDocument(int $documentId): array
    {
        $document = Document::findOrFail($documentId);
        
        \Log::info("🔄 [FORCE-RESCRAPE] Inizio re-scraping documento", [
            'document_id' => $documentId,
            'title' => $document->title,
            'source_url' => $document->source_url
        ]);

        if (!$document->source_url) {
            return [
                'success' => false,
                'message' => 'Documento non ha source_url. Non può essere ri-scrapato.',
                'document_id' => $documentId
            ];
        }

        try {
            // Re-scraping dell'URL originale
            $result = $this->scrapeSingleUrl(
                $document->tenant_id, 
                $document->source_url, 
                true, // force = true
                $document->knowledge_base_id
            );

            if ($result['success']) {
                \Log::info("✅ [FORCE-RESCRAPE] Re-scraping completato", [
                    'document_id' => $documentId,
                    'original_title' => $document->title,
                    'new_stats' => $result['stats']
                ]);

                return [
                    'success' => true,
                    'message' => 'Documento ri-scrapato con successo',
                    'document_id' => $documentId,
                    'original_document' => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'created_at' => $document->created_at
                    ],
                    'result' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Re-scraping fallito: ' . ($result['message'] ?? 'Errore sconosciuto'),
                    'document_id' => $documentId
                ];
            }

        } catch (\Exception $e) {
            \Log::error("💥 [FORCE-RESCRAPE] Errore durante re-scraping", [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Errore durante re-scraping: ' . $e->getMessage(),
                'document_id' => $documentId
            ];
        }
    }

    /**
     * ⚡ Check if Node.js is available in the current environment
     */
    private function isNodeAvailable(): bool
    {
        $result = shell_exec('node --version 2>&1');
        $isAvailable = $result && str_starts_with(trim($result), 'v');
        
        \Log::debug("🔍 [NODE-CHECK] Node.js availability", [
            'available' => $isAvailable,
            'version_output' => trim($result ?: 'no output'),
            'environment' => app()->environment()
        ]);
        
        return $isAvailable;
    }

    /**
     * Metodo interno per scraping di un singolo URL (usato sia da scrapeSingleUrl che da forceRescrapDocument)
     */
    private function scrapeSingleUrlInternal(string $url, Tenant $tenant, ?int $knowledgeBaseId, bool $force): ?array
    {
        \Log::debug("🔍 [SINGLE-URL-INTERNAL] Processando URL", ['url' => $url]);

        try {
            // Usa configurazione di default per il tenant (se esiste)
            $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
            // Ensure tenant-specific patterns are available during single URL flow
            $this->currentConfig = $config;
            
            if (!$config) {
                // Crea una configurazione temporanea per questo scraping
                $config = new ScraperConfig([
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'timeout' => 30, // ⚡ OPTIMIZED: Reduced from 150s with improved patterns
                    'max_redirects' => 5,
                    'respect_robots' => false,
                    'rate_limit_rps' => 1,
                    'render_js' => true, // Always enable JS rendering for SPA sites
                    'target_knowledge_base_id' => $knowledgeBaseId
                ]);
            } else {
                // Override della KB se specificata
                if ($knowledgeBaseId) {
                    $config->target_knowledge_base_id = $knowledgeBaseId;
                }
                // RISPETTA LA CONFIGURAZIONE: NON forzare il rendering JS se disabilitato
                // Mantieni solo un timeout minimo ragionevole
                $config->render_js = (bool) ($config->render_js ?? false);
                $config->timeout = max((int) $config->timeout, 30);
            }

            // Fetch e estrazione contenuto
            $content = $this->fetchUrl($url, $config);
            if (!$content) {
                \Log::warning("❌ [SINGLE-URL-INTERNAL] Fetch fallito", ['url' => $url]);
                return null;
            }

            $extracted = $this->extractContent($content, $url);
            if (!$extracted) {
                \Log::warning("❌ [SINGLE-URL-INTERNAL] Estrazione contenuto fallita", ['url' => $url]);
                return null;
            }

            \Log::info("✅ [SINGLE-URL-INTERNAL] Contenuto estratto", [
                'url' => $url,
                'title' => $extracted['title'],
                'content_length' => strlen($extracted['content'])
            ]);

            return [
                'url' => $url,
                'title' => $extracted['title'],
                'content' => $extracted['content'],
                'extracted_at' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            \Log::error("💥 [SINGLE-URL-INTERNAL] Errore durante estrazione", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }



    /**
     * Chunking del contenuto per embeddings
     */
    public function chunkContent(string $content): array
    {
        $maxChars = config('rag.chunk.max_chars', 2200);
        $overlapChars = config('rag.chunk.overlap_chars', 250);
        
        $chunks = [];
        $contentLength = strlen($content);
        $start = 0;
        
        while ($start < $contentLength) {
            $end = $start + $maxChars;
            
            // Se non siamo alla fine, cerca un punto di interruzione naturale
            if ($end < $contentLength) {
                // Cerca il primo punto o newline dopo la posizione target
                $breakPoint = strpos($content, '.', $end - 100);
                if ($breakPoint !== false && $breakPoint < $end + 100) {
                    $end = $breakPoint + 1;
                } else {
                    // Fallback: cerca newline
                    $breakPoint = strpos($html, "\n", $end - 100);
                    if ($breakPoint !== false && $breakPoint < $end + 100) {
                        $end = $breakPoint + 1;
                    }
                }
            }
            
            $chunk = substr($content, $start, $end - $start);
            $chunk = trim($chunk);
            
            if (!empty($chunk)) {
                $chunks[] = $chunk;
            }
            
            $start = $end - $overlapChars;
        }
        
        return $chunks;
    }

    /**
     * 📎 Scarica documenti collegati (PDF, Office, etc.) linkati nel contenuto
     * @param string|array $content Contenuto markdown o array di link
     */
    private function downloadLinkedDocuments($content, Tenant $tenant, ScraperConfig $config, string $pageUrl): void
    {
        try {
            // Supporta sia contenuto markdown che array di link
            if (is_array($content)) {
                $links = array_unique(array_map('trim', $content));
            } else {
                // Estrai link dal markdown
                preg_match_all('/\[[^\]]+\]\(([^\)]+)\)/', $content, $matches);
                $links = array_unique(array_map('trim', $matches[1] ?? []));
            }
            
            if (empty($links)) {
                \Log::debug("📎 Nessun link trovato nel contenuto", ['page_url' => $pageUrl]);
                return;
            }

            \Log::info("📎 Analizzando link per documenti collegati", [
                'page_url' => $pageUrl,
                'total_links' => count($links),
                'config' => [
                    'extensions' => $config->linked_extensions,
                    'max_size_mb' => $config->linked_max_size_mb,
                    'same_domain_only' => $config->linked_same_domain_only
                ]
            ]);

            $downloaded = 0;
            $skipped = 0;

            foreach ($links as $link) {
                // Converti link relativi in assoluti
                $absoluteUrl = $this->resolveUrl($link, $pageUrl);
                if (!$absoluteUrl) {
                    $skipped++;
                    continue;
                }
                
                // Filtra per estensione
                $extension = strtolower(pathinfo(parse_url($absoluteUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (!in_array($extension, $config->linked_extensions ?? [])) {
                    $skipped++;
                    continue;
                }

                // Filtra per dominio se necessario
                if ($config->linked_same_domain_only) {
                    $pageDomain = parse_url($pageUrl, PHP_URL_HOST);
                    $linkDomain = parse_url($absoluteUrl, PHP_URL_HOST);
                    if ($pageDomain !== $linkDomain) {
                        $skipped++;
                        \Log::debug("📎 Skip link cross-domain", [
                            'link' => $absoluteUrl,
                            'page_domain' => $pageDomain,
                            'link_domain' => $linkDomain
                        ]);
                        continue;
                    }
                }

                // Verifica se già scaricato
                $existingDoc = Document::where('tenant_id', $tenant->id)
                    ->where('source_url', $absoluteUrl)
                    ->first();
                if ($existingDoc) {
                    $skipped++;
                    \Log::debug("📎 Documento già esistente", ['url' => $absoluteUrl, 'doc_id' => $existingDoc->id]);
                    continue;
                }

                // Scarica e salva
                if ($this->downloadAndSaveDocument($absoluteUrl, $tenant, $config)) {
                    $downloaded++;
                } else {
                    $skipped++;
                }
            }

            \Log::info("📎 Download documenti collegati completato", [
                'page_url' => $pageUrl,
                'downloaded' => $downloaded,
                'skipped' => $skipped,
                'total_processed' => count($links)
            ]);

        } catch (\Exception $e) {
            \Log::error("📎 Errore durante download documenti collegati", [
                'page_url' => $pageUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Scarica e salva un singolo documento collegato
     */
    private function downloadAndSaveDocument(string $url, Tenant $tenant, ScraperConfig $config): bool
    {
        try {
            // Fetch del documento
            $timeout = $config->timeout ?? 60;
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; WebScraper/1.0)'])
                ->get($url);

            if (!$response->successful()) {
                \Log::warning("📎 HTTP error downloading document", ['url' => $url, 'status' => $response->status()]);
                return false;
            }

            $content = $response->body();
            $sizeBytes = strlen($content);
            $sizeMB = $sizeBytes / (1024 * 1024);

            // Verifica dimensione
            if ($sizeMB > ($config->linked_max_size_mb ?? 10)) {
                \Log::warning("📎 Documento troppo grande", [
                    'url' => $url,
                    'size_mb' => round($sizeMB, 2),
                    'max_allowed' => $config->linked_max_size_mb
                ]);
                return false;
            }

            // Determina KB target
            $targetKbId = $config->linked_target_kb_id ?? $config->target_knowledge_base_id;
            if (!$targetKbId) {
                $targetKbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)
                    ->where('is_default', true)
                    ->value('id');
            }

            // Genera nome file unico
            $filename = basename(parse_url($url, PHP_URL_PATH));
            if (empty($filename) || !str_contains($filename, '.')) {
                $extension = pathinfo($url, PATHINFO_EXTENSION);
                $filename = 'document_' . time() . '.' . $extension;
            }

            $path = "linked_docs/{$tenant->id}/" . $filename;
            $counter = 1;
            while (Storage::disk('public')->exists($path)) {
                $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $path = "linked_docs/{$tenant->id}/{$nameWithoutExt}_{$counter}.{$ext}";
                $counter++;
            }

            // Salva file
            Storage::disk('public')->put($path, $content);

            // Crea record documento
            $document = Document::create([
                'tenant_id' => $tenant->id,
                'knowledge_base_id' => $targetKbId,
                'title' => pathinfo($filename, PATHINFO_FILENAME),
                'path' => $path,
                'source_url' => $url,
                'content_hash' => hash('sha256', $content),
                'size' => $sizeBytes,
                'ingestion_status' => 'pending',
                'source' => 'web_scraper_linked',
                'metadata' => [
                    'linked_from_scraper' => true,
                    'original_extension' => pathinfo($filename, PATHINFO_EXTENSION),
                    'downloaded_at' => now()->toISOString()
                ]
            ]);

            // Avvia ingestion
            IngestUploadedDocumentJob::dispatch($document->id);

            \Log::info("📎 Documento collegato scaricato e salvato", [
                'url' => $url,
                'document_id' => $document->id,
                'path' => $path,
                'size_mb' => round($sizeMB, 2)
            ]);

            return true;

        } catch (\Exception $e) {
            \Log::error("📎 Errore scaricamento documento", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Inizializza il tracking del progress
     */
    private function initializeProgress(int $tenantId, ?int $scraperConfigId): void
    {
        $sessionId = Str::uuid()->toString();
        
        $this->progress = ScraperProgress::create([
            'tenant_id' => $tenantId,
            'scraper_config_id' => $scraperConfigId,
            'session_id' => $sessionId,
            'status' => 'running',
            'started_at' => now(),
        ]);
        
        \Log::info("🚀 Scraping progress inizializzato", [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'scraper_config_id' => $scraperConfigId
        ]);
    }

    /**
     * Aggiorna il progress durante lo scraping
     */
    private function updateProgress(array $updates): void
    {
        if (!$this->progress) return;
        
        try {
            $this->progress->update($updates);
            
            // Log ogni 10 pagine scrapate
            if (isset($updates['pages_scraped']) && $this->progress->pages_scraped % 10 === 0) {
                \Log::info("📊 Progress update", [
                    'session_id' => $this->progress->session_id,
                    'pages_scraped' => $this->progress->pages_scraped,
                    'pages_found' => $this->progress->pages_found,
                    'documents_created' => $this->progress->documents_created,
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning("Errore aggiornamento progress", [
                'session_id' => $this->progress?->session_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Finalizza il progress al completamento
     */
    private function finalizeProgress(string $status = 'completed', ?string $error = null): void
    {
        if (!$this->progress) return;
        
        $updates = [
            'status' => $status,
            'completed_at' => now(),
        ];
        
        if ($error) {
            $updates['last_error'] = $error;
        }
        
        $this->progress->update($updates);
        
        \Log::info("✅ Scraping completato", [
            'session_id' => $this->progress->session_id,
            'status' => $status,
            'duration_seconds' => $this->progress->started_at->diffInSeconds(now()),
            'final_stats' => $this->progress->getSummary()
        ]);
    }

    /**
     * Scraping ricorsivo in modalità parallela
     * 
     * Invece di processare immediatamente ogni URL, questo metodo
     * dispatcha ogni URL come job separato sulla coda 'scraping',
     * permettendo a worker multipli di processarli in parallelo
     */
    public function scrapeRecursiveParallel(
        string $url,
        ScraperConfig $config,
        Tenant $tenant,
        int $depth = 0
    ): void
    {
        // Check profondità massima
        if ($depth > $config->max_depth) {
            \Log::debug("⚠️ [PARALLEL-SCRAPE] Max depth raggiunta", [
                'url' => $url,
                'depth' => $depth,
                'max_depth' => $config->max_depth
            ]);
            return;
        }

        // Check se URL già visitato
        if (isset($this->visitedUrls[$url])) {
            \Log::debug("⚠️ [PARALLEL-SCRAPE] URL già visitato", ['url' => $url]);
            return;
        }

        $this->visitedUrls[$url] = true;

        // 🚀 Dispatcha job per questo URL
        \App\Jobs\ScrapeUrlJob::dispatch(
            $url,
            $depth,
            $config->id,
            $tenant->id,
            $this->sessionId
        );

        \Log::info("📤 [PARALLEL-SCRAPE] Job dispatchato per URL", [
            'url' => $url,
            'depth' => $depth,
            'session_id' => $this->sessionId
        ]);

        // Se non è profondità massima, fetcha la pagina per estrarre link
        if ($depth < $config->max_depth) {
            try {
                $content = $this->fetchUrl($url, $config);
                if ($content) {
                    $links = $this->extractLinks($content, $url);
                    
                    \Log::info("🔗 [PARALLEL-SCRAPE] Link estratti", [
                        'url' => $url,
                        'links_found' => count($links),
                        'depth' => $depth
                    ]);
                    
                    // Dispatcha job per ogni link trovato
                    foreach ($links as $link) {
                        if (!isset($this->visitedUrls[$link]) && 
                            $this->isAllowedUrl($link, $config)) {
                            $this->scrapeRecursiveParallel($link, $config, $tenant, $depth + 1);
                        }
                    }
                } else {
                    \Log::warning("⚠️ [PARALLEL-SCRAPE] Fetch URL fallito per estrazione link", [
                        'url' => $url
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning("❌ [PARALLEL-SCRAPE] Errore estrazione link", [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Scrappa un singolo URL (chiamato dal job ScrapeUrlJob)
     * Questo metodo è ottimizzato per essere eseguito in parallelo
     */
    public function scrapeSingleUrlForParallel(
        string $url,
        int $depth,
        ScraperConfig $config,
        Tenant $tenant
    ): void
    {
        ScraperLogger::urlProcessing($this->sessionId, $url, $depth);

        // Rate limiting
        if ($config->rate_limit_rps > 0) {
            usleep(1000000 / $config->rate_limit_rps);
        }

        try {
            $content = $this->fetchUrl($url, $config);
            
            // 🐛 DEBUG: Log per capire cosa viene restituito da fetchUrl
            \Log::debug("🔍 [FETCH-DEBUG] fetchUrl returned", [
                'url' => $url,
                'content_is_null' => $content === null,
                'content_is_empty' => empty($content),
                'content_length' => $content ? strlen($content) : 0
            ]);
            
            if (!$content) {
                \Log::warning("⚠️ [FETCH-FAILED] fetchUrl returned empty content", ['url' => $url]);
                return;
            }

            // Determina se questa pagina è "link-only" in base alla configurazione
            $isLinkOnly = $this->isLinkOnlyUrl($url, $config);
            
            \Log::debug("🔍 [LINK-ONLY-CHECK]", [
                'url' => $url,
                'is_link_only' => $isLinkOnly
            ]);

            if (!$isLinkOnly) {
                // Politica: se skip_known_urls attivo, e abbiamo già un documento per questo URL, salta
                $shouldSkip = $this->shouldSkipKnownUrl($url, $tenant, $config);
                
                \Log::debug("🔍 [SKIP-CHECK]", [
                    'url' => $url,
                    'should_skip' => $shouldSkip,
                    'skip_known_urls_enabled' => $config->skip_known_urls ?? false
                ]);
                
                if ($shouldSkip) {
                    $this->stats['skipped']++;
                    ScraperLogger::urlSuccess($this->sessionId, $url, 'skipped', 0);
                    \Log::info('Skip URL già noto', ['url' => $url]);
                    return;
                }
                
                \Log::debug("🚀 [EXTRACTION-START] Starting content extraction", ['url' => $url]);
                
                // Estrai contenuto principale
                $extractedContent = $this->extractContent($content, $url);
                
                if ($extractedContent) {
                    // 🆕 SALVATAGGIO PROGRESSIVO: Salva il documento immediatamente
                    $result = [
                        'url' => $url,
                        'title' => $extractedContent['title'],
                        'content' => $extractedContent['content'],
                        'depth' => $depth,
                        'quality_analysis' => $extractedContent['quality_analysis'] ?? null
                    ];
                    
                    // 🚀 SALVA E AVVIA INGESTION IMMEDIATAMENTE
                    try {
                        $this->saveAndIngestSingleResult($result, $tenant, $config);
                        \Log::info("✅ [PROGRESSIVE-SAVE] Documento salvato e ingestion avviata", [
                            'session_id' => $this->sessionId,
                            'url' => $url,
                            'title' => $result['title']
                        ]);
                    } catch (\Exception $e) {
                        \Log::error("❌ [PROGRESSIVE-SAVE-ERROR] Errore salvataggio documento", [
                            'url' => $url,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    \Log::warning("⚠️ [EXTRACTION-FAILED] Nessun contenuto estratto", ['url' => $url]);
                }
            }
        } catch (\Exception $e) {
            \Log::error("❌ [SCRAPE-ERROR] Errore scraping URL", [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Rilancia per permettere retry del job
        }
    }

    /**
     * Imposta il session ID (usato dai job per mantenere coerenza nel logging)
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Ottiene il session ID corrente
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }
}

