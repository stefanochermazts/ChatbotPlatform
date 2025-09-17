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

        // Salva i risultati come documenti
        $savedCount = $this->saveResults($tenant, false);

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

    private function scrapeRecursive(string $url, ScraperConfig $config, Tenant $tenant, int $depth): void
    {
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
            if (!$content) return;

            // Determina se questa pagina è "link-only" in base alla configurazione
            $isLinkOnly = $this->isLinkOnlyUrl($url, $config);

            if (!$isLinkOnly) {
                // Politica: se skip_known_urls attivo, e abbiamo già un documento per questo URL (e non è da recrawllare), salta
                if ($this->shouldSkipKnownUrl($url, $tenant, $config)) {
                    $this->stats['skipped']++;
                    ScraperLogger::urlSuccess($this->sessionId, $url, 'skipped', 0);
                    \Log::info('Skip URL già noto', ['url' => $url]);
                } else {
                // Estrai contenuto principale
                $extractedContent = $this->extractContent($content, $url);
                if ($extractedContent) {
                    // Salva risultato con metadata qualità
                    $this->results[] = [
                        'url' => $url,
                        'title' => $extractedContent['title'],
                        'content' => $extractedContent['content'],
                        'depth' => $depth,
                        'quality_analysis' => $extractedContent['quality_analysis'] ?? null
                    ];
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
            // ❌ Log errore specifico per scraper
            ScraperLogger::urlError($this->sessionId, $url, $e->getMessage());
            \Log::warning("Errore scraping URL: {$url}", ['error' => $e->getMessage()]);
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
        // Use the same timeout as debug command (minimum 90s for Angular sites)
        $timeout = max($config->timeout ?? 150, 90);
        
        $content = $renderer->renderUrl($url, $timeout);
        
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
        // 🧠 ENHANCED: Analisi qualità avanzata per strategia ottimale
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
        // SPECIAL CASE: Comune di Palmanova - force manual DOM extraction
        if (isset($analysis['url']) && stripos($analysis['url'], 'comune.palmanova.ud.it') !== false) {
            \Log::debug("🎯 Using manual DOM for Palmanova site", ['url' => $analysis['url']]);
            return 'manual_dom_primary';
        }
        
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
            // Pre-processing HTML per migliorare estrazione tabelle
            $preprocessedHtml = $this->preprocessHtmlForReadability($html);
            
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
        // SMART CONTENT DETECTION: Try to detect main content containers automatically
        $smartExtraction = $this->trySmartContentExtraction($html, $url);
        if ($smartExtraction) {
            return $smartExtraction;
        }
        
        // Parse HTML with more robust error handling
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        
        // Try UTF-8 first, then fallback
        $loadSuccess = $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        
        if (!$loadSuccess) {
            // Fallback: try without encoding conversion
            $loadSuccess = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        }
        
        \Log::debug("🔍 DOM loading result", [
            'url' => $url,
            'load_success' => $loadSuccess,
            'dom_errors' => libxml_get_errors(),
            'html_length' => strlen($html)
        ]);
        
        libxml_clear_errors();
        
        // Estrai title
        $titleNodes = $dom->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : parse_url($url, PHP_URL_HOST);

        // Remove script, style, nav, footer, header
        $tagsToRemove = ['script', 'style', 'nav', 'footer', 'header', 'aside'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $elements->item($i)->parentNode->removeChild($elements->item($i));
            }
        }

        // Prova a estrarre main content
        $mainContent = '';
        
        // Cerca elementi con content principale - includi selettori CSS specifici per Palmanova
        $contentSelectors = ['main', 'article'];
        foreach ($contentSelectors as $selector) {
                $elements = $dom->getElementsByTagName($selector);
                if ($elements->length > 0) {
                $mainContent = $this->convertToMarkdown($elements->item(0), $url);
                    break;
            }
        }
        
        // Also check for div with id="main" (common in Angular apps)
        if (!$mainContent) {
            $xpath = new \DOMXPath($dom);
            $mainDivNodes = $xpath->query("//div[@id='main']");
            if ($mainDivNodes->length > 0) {
                \Log::debug("🔍 Found div with id='main'", [
                    'url' => $url,
                    'nodes_found' => $mainDivNodes->length
                ]);
                
                // For Palmanova, extract specifically the testolungo content from within main
                $testoLungoInMain = $xpath->query(".//div[contains(@class, 'testolungo')]", $mainDivNodes->item(0));
                if ($testoLungoInMain->length > 0) {
                    $mainContent = $this->convertToMarkdown($testoLungoInMain->item(0), $url);
                    \Log::info("🎯 Extracted testolungo content from within main div", [
                        'url' => $url,
                        'content_length' => strlen($mainContent)
                    ]);
                } else {
                    // Fallback: extract full main content
                    $mainContent = $this->convertToMarkdown($mainDivNodes->item(0), $url);
                    \Log::debug("🔍 Extracted full main div content", [
                        'url' => $url,
                        'content_length' => strlen($mainContent)
                    ]);
                }
            }
        }
        
        // SPECIAL CASE: Cerca contenuto con classi specifiche (es. Comune Palmanova)
        if (!$mainContent) {
            $xpath = new \DOMXPath($dom);
            
            \Log::debug("🔍 Looking for special content containers", [
                'url' => $url,
                'dom_loaded' => $dom ? 'yes' : 'no'
            ]);
            
            // Cerca div con classe "testolungo" (Palmanova specific)
            $testoLungoNodes = $xpath->query("//div[contains(@class, 'testolungo')]");
            \Log::debug("🔍 testolungo search result", [
                'url' => $url,
                'nodes_found' => $testoLungoNodes->length
            ]);
            
            if ($testoLungoNodes->length > 0) {
                $mainContent = $this->convertToMarkdown($testoLungoNodes->item(0), $url);
                \Log::info("🎯 Extracted content from 'testolungo' div", [
                    'url' => $url,
                    'content_length' => strlen($mainContent),
                    'content_preview' => substr($mainContent, 0, 200)
                ]);
            }
            
            // Fallback: Cerca div con classe "descrizione-modulo"
            if (!$mainContent) {
                $descrizioneNodes = $xpath->query("//div[contains(@class, 'descrizione-modulo')]");
                \Log::debug("🔍 descrizione-modulo search result", [
                    'url' => $url,
                    'nodes_found' => $descrizioneNodes->length
                ]);
                
                if ($descrizioneNodes->length > 0) {
                    $mainContent = $this->convertToMarkdown($descrizioneNodes->item(0), $url);
                    \Log::info("🎯 Extracted content from 'descrizione-modulo' div", [
                        'url' => $url,
                        'content_length' => strlen($mainContent)
                    ]);
                }
            }
        }

        // Fallback: usa body se non abbiamo main content
        if (!$mainContent) {
            $bodyElements = $dom->getElementsByTagName('body');
            if ($bodyElements->length > 0) {
                $mainContent = $this->convertToMarkdown($bodyElements->item(0), $url);
            }
        }

        // Cleanup content
        $mainContent = $this->cleanupContent($mainContent);
        
        if (strlen($mainContent) < 80) { // Soglia più bassa per il fallback
            return null;
        }

        return [
            'title' => $title ?: 'Untitled',
            'content' => $mainContent
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
                        

                        
                    case 'tr':
                        // Riga tabella - separa con | e aggiungi newline
                        $cells = $this->extractTableCells($child, $baseUrl);
                        if (!empty($cells)) {
                            $markdown .= "\n| " . implode(" | ", $cells) . " |";
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
                $links[] = $absoluteUrl;
            }
        }
        
        return array_unique($links);
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url; // Already absolute
        }
        
        $baseParts = parse_url($baseUrl);
        if (!$baseParts) return null;
        
        if ($url[0] === '/') {
            // Absolute path
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
        } else {
            // Relative path
            $basePath = dirname($baseParts['path'] ?? '/');
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $basePath . '/' . $url;
        }
    }

    private function isUrlAllowed(string $url, ScraperConfig $config): bool
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) return false;
        
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
        if (empty($config->link_only_patterns)) {
            return false;
        }
        foreach ($config->link_only_patterns as $pattern) {
            $regex = $this->compileUserRegex($pattern);
            if ($regex !== null && @preg_match($regex, $url)) {
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

        // Assicurati che il nome file sia unico
        $counter = 1;
        while (Storage::disk('public')->exists($path)) {
            $counter++;
            $filename = $baseFilename . "-v{$counter}.md";
            $path = "scraped/{$tenant->id}/" . $filename;
        }

        // Salva file
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
            'scrape_version' => 1,
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
        
        // 🎯 PRIORITY: Tenant-specific patterns override global ones
        $tenantPatterns = $this->getTenantExtractionPatterns();
        if (!empty($tenantPatterns)) {
            \Log::debug("🎯 Using tenant-specific patterns", [
                'tenant_patterns_count' => count($tenantPatterns),
                'global_patterns_count' => count($contentPatterns)
            ]);
            // Prepend tenant patterns (higher priority)
            $contentPatterns = array_merge($tenantPatterns, $contentPatterns);
        }
        
        // Sort patterns by priority
        usort($contentPatterns, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        foreach ($contentPatterns as $pattern) {
            if (preg_match($pattern['regex'], $html, $matches)) {
                $extractedHtml = $matches[1];
                
                // Clean and process the extracted content
                $cleanContent = $this->processExtractedContent($extractedHtml);
                
                if (strlen($cleanContent) >= $pattern['min_length']) {
                    \Log::info("🎯 Smart extraction successful", [
                        'url' => $url,
                        'pattern' => $pattern['name'],
                        'description' => $pattern['description'],
                        'content_length' => strlen($cleanContent),
                        'content_preview' => substr($cleanContent, 0, 200)
                    ]);
                    
                    return [
                        'title' => $this->extractTitleFromHtml($html) ?: parse_url($url, PHP_URL_HOST),
                        'content' => $cleanContent
                    ];
                }
                
                \Log::debug("🔍 Pattern matched but content too short", [
                    'url' => $url,
                    'pattern' => $pattern['name'],
                    'content_length' => strlen($cleanContent),
                    'min_required' => $pattern['min_length']
                ]);
            }
        }

        \Log::debug("🔍 No smart patterns matched", ['url' => $url]);
        return null;
    }

    /**
     * 🎯 Get tenant-specific extraction patterns from current scraping context
     */
    private function getTenantExtractionPatterns(): array
    {
        if (!$this->currentConfig || empty($this->currentConfig->extraction_patterns)) {
            return [];
        }

        $patterns = $this->currentConfig->extraction_patterns;
        
        \Log::debug("🎯 Loading tenant-specific patterns", [
            'tenant_id' => $this->currentConfig->tenant_id,
            'config_id' => $this->currentConfig->id,
            'patterns_count' => count($patterns)
        ]);

        return $patterns;
    }

    /**
     * 🧹 Process and clean extracted HTML content
     */
    private function processExtractedContent(string $html): string
    {
        // Get cleaning rules from configuration
        $removeContainers = config('scraper-patterns.cleaning_rules.remove_containers', [
            'nav', 'menu', 'sidebar', 'ads', 'banner', 'footer', 'header'
        ]);
        
        // Build regex pattern for containers to remove
        $containerPattern = implode('|', array_map('preg_quote', $removeContainers));
        $html = preg_replace('/<div[^>]*class="[^"]*(?:' . $containerPattern . ')[^"]*"[^>]*>.*?<\/div>/is', '', $html);
        
        // Convert to clean text
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        return $text;
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
            
            if (!$config) {
                // Crea una configurazione temporanea per questo scraping
                $config = new ScraperConfig([
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'timeout' => 150, // Same as debug command that works in development
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
                // Always enable JS rendering for SPA sites; renderer will resolve Node binary robustly
                $config->render_js = true;
                $config->timeout = max($config->timeout, 150); // Ensure sufficient timeout
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

}
