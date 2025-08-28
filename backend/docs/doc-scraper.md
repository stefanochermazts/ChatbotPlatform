# 🕷️ Web Scraper - Documentazione Funzionalità

## 📋 Panoramica
Il Web Scraper di ChatbotPlatform è un sistema avanzato per l'acquisizione automatica di contenuti web, progettato per estrarre, processare e indicizzare pagine web nella knowledge base dei tenant con massima flessibilità e controllo qualità.

> **🎯 Best Practices**: Per configurazioni avanzate ed esempi pratici vedi [`web-scraper-guide.md`](./web-scraper-guide.md)

---

## 🏗️ Architettura Sistema

### **Controller e Services**
- **`ScraperAdminController`**: Interfaccia admin per configurazione e controllo
- **`WebScraperService`**: Core engine scraping e extraction
- **`RunWebScrapingJob`**: Job asincrono per scraping singola URL
- **`ScraperConfig`**: Model configurazioni per tenant
- **`IngestUploadedDocumentJob`**: Pipeline ingestion documenti scraped

### **Database Schema**
```sql
-- Configurazioni scraper per tenant
scraper_configs:
  id, tenant_id, knowledge_base_id, name, base_url, seed_urls,
  allowed_domains, sitemap_urls, include_patterns, exclude_patterns,
  link_only_patterns, max_depth, rate_limit_rps, render_js,
  respect_robots, skip_known_urls, recrawl_after_days,
  auth_headers, frequency_minutes, enabled, created_at, updated_at

-- Documenti generati da scraping  
documents:
  source = 'web_scraper', source_url, scrape_version, content_hash,
  last_scraped_at, scraping_config_id
```

---

## 🔄 Processo Scraping Completo

### **🚀 1. Inizializzazione Scraping**

**Flow di Avvio:**
```php
// ScraperAdminController.start()
public function start(Request $request, Tenant $tenant)
{
    // 1. Validazione configurazione
    $config = $this->validateScraperConfig($request);
    
    // 2. Salvataggio configurazione
    $scraperConfig = ScraperConfig::updateOrCreate([...]);
    
    // 3. Dispatch scraping asincrono
    $this->webScraperService->startScraping($scraperConfig);
    
    return response()->json(['status' => 'started']);
}
```

### **🔍 2. Discovery e Filtering URLs**

**WebScraperService Pipeline:**
```php
public function startScraping(ScraperConfig $config): array
{
    // 1. Parse robots.txt (se abilitato)
    $robotsRules = $this->parseRobotsTxt($config->base_url);
    
    // 2. Collect seed URLs + sitemap URLs
    $initialUrls = array_merge(
        $config->seed_urls,
        $this->extractSitemapUrls($config->sitemap_urls)
    );
    
    // 3. URL discovery ricorsivo
    $discoveredUrls = $this->discoverUrlsRecursively($initialUrls, $config);
    
    // 4. Filtering patterns
    $filteredUrls = $this->applyFilteringPatterns($discoveredUrls, $config);
    
    // 5. Dispatch job per ogni URL
    foreach ($filteredUrls as $url) {
        RunWebScrapingJob::dispatch($url, $config->id)
                          ->onQueue('ingestion');
    }
    
    return [
        'discovered' => count($discoveredUrls),
        'filtered' => count($filteredUrls),
        'dispatched' => count($filteredUrls)
    ];
}
```

### **📄 3. Content Extraction Ibrido**

**Strategia Multi-Approach:**
```php
private function extractContent(string $html, string $url): string
{
    // 1. Analisi tipo contenuto
    $contentType = $this->analyzeContentType($html);
    
    switch ($contentType) {
        case 'responsive_tables':
            // Tabelle con classi responsive (hidden-xs/visible-xs)
            return $this->extractWithManualDOM($html);
            
        case 'article':
            // Contenuto articolo/news
            return $this->extractWithReadability($html);
            
        case 'structured_data':
            // Dati strutturati (JSON-LD, microdata)
            return $this->extractStructuredData($html);
            
        default:
            // Estrazione ibrida
            return $this->hybridExtraction($html);
    }
}
```

**Gestione Tabelle Responsive (Fix Critico):**
```php
private function extractTableCells(DOMElement $row): array
{
    $cells = [];
    $allCells = $row->getElementsByTagName('td');
    
    // 1. Priorità: celle desktop-visible (hidden-xs)
    foreach ($allCells as $cell) {
        if ($this->hasClass($cell, 'hidden-xs')) {
            $cells[] = $this->cleanText($cell->textContent);
        }
    }
    
    // 2. Fallback: celle non mobile-only se nessuna desktop trovata
    if (empty($cells)) {
        foreach ($allCells as $cell) {
            if (!$this->hasClass($cell, 'visible-xs')) {
                $cells[] = $this->cleanText($cell->textContent);
            }
        }
    }
    
    // 3. Ultima risorsa: tutte le celle
    if (empty($cells)) {
        foreach ($allCells as $cell) {
            $cells[] = $this->cleanText($cell->textContent);
        }
    }
    
    return $cells;
}
```

---

## ⚙️ Configurazioni Scraper

### **🎯 1. Pattern Filtering**

**Include Patterns (Regex):**
```php
// Esempi pattern comuni
$includePatterns = [
    '\/$',                    // Homepage
    '/servizi/.*',           // Sezione servizi
    '/news/\d{4}/.*',        // News con anno
    '/prodotti/[^/]+/$'      // Prodotti specifici
];
```

**Exclude Patterns (Regex):**
```php
$excludePatterns = [
    '/admin/.*',             // Area amministrativa
    '/login.*',              // Pagine login
    '\.pdf$',                // File PDF
    '/search\?.*',           // Pagine ricerca
    '/print/.*'              // Versioni stampa
];
```

**Link-Only Patterns (Ottimizzazione):**
```php
// Pagine "indice" - estrai link ma non salvare contenuto
$linkOnlyPatterns = [
    '/news/?$',              // Homepage news
    '/categoria/.*',         // Pagine categoria
    '/page/\d+',             // Paginazione
    '/archivio.*',           // Archivi
    '/tags?/.*'              // Pagine tag
];
```

### **🔧 2. Parametri Comportamentali**

**Configurazione Crawling:**
```php
// Profondità e performance
'max_depth' => 3,                    // Max livelli ricorsione
'rate_limit_rps' => 1.0,            // Richieste per secondo
'render_js' => false,                // Rendering JavaScript
'respect_robots' => true,            // Rispetta robots.txt

// Deduplicazione intelligente  
'skip_known_urls' => true,           // Salta URL già processati
'recrawl_after_days' => 7,           // Re-scrape dopo N giorni

// Multi-scraper per tenant
'name' => 'Scraper Servizi PA',      // Nome identificativo
'frequency_minutes' => 1440,         // Frequenza esecuzione (24h)
'enabled' => true                    // Attivo/inattivo
```

**Autenticazione:**
```php
'auth_headers' => [
    'Authorization' => 'Bearer token123',
    'X-API-Key' => 'key456',
    'Cookie' => 'session=abc123;auth=xyz789'
];
```

---

## 📊 Versioning e Deduplicazione

### **🔍 1. Intelligent Deduplication**

**Hash-Based Detection:**
```php
public function processUrl(string $url, ScraperConfig $config): array
{
    // 1. Extract content
    $content = $this->extractContent($html, $url);
    $contentHash = hash('sha256', trim($content));
    
    // 2. Check existing document
    $existingDoc = Document::where('source_url', $url)
                          ->where('tenant_id', $config->tenant_id)
                          ->first();
    
    if ($existingDoc) {
        // 3. Compare content hash
        if ($existingDoc->content_hash === $contentHash) {
            // Content unchanged - update timestamp only
            $existingDoc->update(['last_scraped_at' => now()]);
            return ['status' => 'unchanged', 'action' => 'timestamp_updated'];
        } else {
            // Content changed - create new version
            return $this->updateDocumentVersion($existingDoc, $content, $contentHash);
        }
    } else {
        // 4. New document
        return $this->createNewDocument($url, $content, $contentHash, $config);
    }
}
```

### **📈 2. Version Management**

**Auto-Versioning Strategy:**
```php
private function updateDocumentVersion(Document $doc, string $content, string $hash): array
{
    // 1. Increment version
    $newVersion = $doc->scrape_version + 1;
    
    // 2. Update document record
    $doc->update([
        'content_hash' => $hash,
        'scrape_version' => $newVersion,
        'last_scraped_at' => now(),
        'ingestion_status' => 'pending'  // Re-trigger ingestion
    ]);
    
    // 3. Update file with versioning
    $filename = $this->generateVersionedFilename($doc->title, $newVersion);
    $this->saveContentToFile($content, $filename, $doc->tenant_id);
    
    // 4. Re-trigger ingestion pipeline
    IngestUploadedDocumentJob::dispatch($doc->id)->onQueue('ingestion');
    
    return [
        'status' => 'updated',
        'version' => $newVersion,
        'action' => 'content_changed'
    ];
}
```

---

## 🔧 Multi-Scraper per Tenant

### **🗂️ 1. Organizzazione Multi-Scraper**

**Use Case Tipici:**
```php
// Esempio: Sito PA
$scrapers = [
    [
        'name' => 'Servizi Istituzionali',
        'knowledge_base_id' => 1,           // KB "Servizi"
        'frequency_minutes' => 1440,        // 1x/giorno
        'include_patterns' => ['/servizi/.*', '/uffici/.*'],
        'recrawl_after_days' => 30          // Contenuto stabile
    ],
    [
        'name' => 'News e Comunicazioni', 
        'knowledge_base_id' => 2,           // KB "News"
        'frequency_minutes' => 360,         // 4x/giorno
        'include_patterns' => ['/news/.*', '/comunicazioni/.*'],
        'recrawl_after_days' => 7           // Contenuto dinamico
    ]
];
```

### **📅 2. Scheduling Automatico**

**Comando Scheduler:**
```php
// app/Console/Commands/RunDueScrapers.php
public function handle(): int
{
    $dueConfigs = ScraperConfig::where('enabled', true)
                              ->whereNotNull('frequency_minutes')
                              ->get()
                              ->filter(function($config) {
                                  return $this->isDue($config);
                              });
    
    foreach ($dueConfigs as $config) {
        if ($this->option('dry-run')) {
            $this->info("Would run scraper: {$config->name} (Tenant: {$config->tenant_id})");
        } else {
            $this->webScraperService->startScraping($config);
            $this->info("Started scraper: {$config->name}");
        }
    }
    
    return 0;
}

private function isDue(ScraperConfig $config): bool
{
    if (!$config->last_executed_at) {
        return true; // Never executed
    }
    
    $interval = now()->diffInMinutes($config->last_executed_at);
    return $interval >= $config->frequency_minutes;
}
```

**Kernel Scheduling:**
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('scraper:run-due')
             ->everyFiveMinutes()
             ->withoutOverlapping()
             ->runInBackground();
}
```

---

## 🚀 Funzionalità Avanzate

### **🔄 1. Single URL Scraping**

**Scraping Mirato:**
```php
// Endpoint: POST /admin/tenants/{tenant}/documents/scrape-single-url
public function scrapeSingleUrl(Request $request): JsonResponse
{
    $validated = $request->validate([
        'url' => 'required|url',
        'knowledge_base_id' => 'nullable|integer|exists:knowledge_bases,id',
        'force' => 'boolean'
    ]);
    
    try {
        $result = $this->webScraperService->scrapeSingleUrl(
            $validated['url'],
            $request->tenant,
            $validated['knowledge_base_id'] ?? null,
            $validated['force'] ?? false
        );
        
        return response()->json([
            'success' => true,
            'document_id' => $result['document_id'],
            'status' => $result['status'],
            'message' => $result['message']
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}
```

### **🔄 2. Force Re-scraping**

**Re-scraping Forzato:**
```php
public function forceRescrapDocument(int $documentId): array
{
    $document = Document::findOrFail($documentId);
    
    if (!$document->source_url) {
        throw new \Exception('Document has no source_url for re-scraping');
    }
    
    // 1. Force scraping ignorando cache/hash
    $result = $this->scrapeSingleUrlInternal(
        $document->source_url,
        $document->tenant_id,
        $document->knowledge_base_id,
        true  // force = true
    );
    
    // 2. Update original document record
    if ($result['success']) {
        $document->update([
            'ingestion_status' => 'pending',
            'last_scraped_at' => now()
        ]);
        
        // 3. Re-trigger full ingestion
        IngestUploadedDocumentJob::dispatch($documentId)->onQueue('ingestion');
    }
    
    return $result;
}
```

---

## 📊 Monitoraggio e Analytics

### **📈 1. Statistiche Scraping**

**Metrics Collection:**
```php
// Durante scraping
$stats = [
    'urls_discovered' => count($discoveredUrls),
    'urls_filtered' => count($filteredUrls),
    'urls_processed' => 0,
    'documents_created' => 0,
    'documents_updated' => 0,
    'documents_unchanged' => 0,
    'errors' => []
];

// Per ogni URL processato
foreach ($urls as $url) {
    try {
        $result = $this->processUrl($url, $config);
        $stats['urls_processed']++;
        $stats['documents_' . $result['status']]++;
    } catch (\Exception $e) {
        $stats['errors'][] = [
            'url' => $url,
            'error' => $e->getMessage()
        ];
    }
}
```

### **🔍 2. Quality Metrics**

**Content Quality Assessment:**
```php
private function assessContentQuality(string $content): array
{
    return [
        'char_count' => strlen($content),
        'word_count' => str_word_count($content),
        'paragraph_count' => substr_count($content, "\n\n"),
        'has_tables' => strpos($content, '|') !== false,
        'has_lists' => preg_match('/^\s*[-*+]\s/m', $content),
        'extraction_score' => $this->calculateExtractionScore($content)
    ];
}

private function calculateExtractionScore(string $content): float
{
    $score = 0.0;
    
    // Length score (0.0-0.4)
    $charCount = strlen($content);
    if ($charCount > 1000) $score += 0.4;
    elseif ($charCount > 500) $score += 0.3;
    elseif ($charCount > 200) $score += 0.2;
    
    // Structure score (0.0-0.3)
    if (strpos($content, '##') !== false) $score += 0.1; // Headers
    if (strpos($content, '|') !== false) $score += 0.1;  // Tables
    if (preg_match('/^\s*[-*+]\s/m', $content)) $score += 0.1; // Lists
    
    // Content score (0.0-0.3)
    $words = str_word_count($content);
    if ($words > 100) $score += 0.3;
    elseif ($words > 50) $score += 0.2;
    elseif ($words > 20) $score += 0.1;
    
    return min(1.0, $score);
}
```

---

## 🛡️ Error Handling e Resilienza

### **⚠️ 1. Gestione Errori Graduata**

**Error Categories:**
```php
class ScrapingErrorHandler
{
    public function handleScrapingError(\Exception $e, string $url, ScraperConfig $config): void
    {
        $errorType = $this->categorizeError($e);
        
        switch ($errorType) {
            case 'network_timeout':
                // Retry con backoff
                $this->scheduleRetry($url, $config, delay: 300);
                break;
                
            case 'rate_limit':
                // Riduci velocità temporaneamente
                $this->adjustRateLimit($config, factor: 0.5);
                $this->scheduleRetry($url, $config, delay: 600);
                break;
                
            case 'auth_required':
                // Notifica admin, non retry
                $this->notifyAuthError($config, $url);
                break;
                
            case 'content_empty':
                // Log warning, continua
                Log::warning('Empty content extracted', ['url' => $url]);
                break;
                
            case 'parsing_error':
                // Fallback a estrazione semplice
                $this->trySimpleExtraction($url, $config);
                break;
                
            default:
                // Errore generico
                Log::error('Scraping failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'config_id' => $config->id
                ]);
        }
    }
}
```

### **🔄 2. Resilienza e Fallback**

**Backup Strategies:**
```php
private function extractWithFallbacks(string $html, string $url): string
{
    $strategies = [
        'readability_full',     // Readability.php completo
        'readability_simple',   // Readability semplificato
        'manual_dom',          // Parsing DOM manuale
        'simple_strip'         // Strip tag semplice
    ];
    
    foreach ($strategies as $strategy) {
        try {
            $content = $this->executeStrategy($strategy, $html, $url);
            
            if ($this->isContentValid($content)) {
                Log::info("Extraction successful with strategy: {$strategy}", ['url' => $url]);
                return $content;
            }
            
        } catch (\Exception $e) {
            Log::warning("Strategy {$strategy} failed for {$url}: " . $e->getMessage());
            continue;
        }
    }
    
    // Ultima risorsa: contenuto grezzo
    return $this->extractRawText($html);
}
```

---

## 🔧 Commands CLI

### **🚀 1. Management Commands**

**Scraping Commands:**
```bash
# Esegui scraper dovuti (scheduler)
php artisan scraper:run-due [--dry-run] [--tenant=X] [--id=Y]

# Scraping singolo URL
php artisan scraper:single {tenant} {url} [--force] [--kb=X]

# Re-scrape documento esistente
php artisan scraper:rescrape {document} [--all-scraped] [--tenant=X]

# Pulizia documenti obsoleti  
php artisan scraper:clean-old [--dry-run] [--days=30] [--tenant=X]

# Statistiche scraping
php artisan scraper:stats [--tenant=X] [--from=date] [--to=date]
```

**Examples:**
```bash
# Test scraping configurazione specifica
php artisan scraper:run-due --id=123 --dry-run

# Scraping forzato URL singolo
php artisan scraper:single 5 "https://example.com/page" --force --kb=2

# Pulizia documenti vecchi 90 giorni
php artisan scraper:clean-old --days=90 --tenant=5

# Re-scrape tutti documenti di un tenant
php artisan scraper:rescrape --all-scraped --tenant=5
```

### **🔍 2. Debug e Monitoring**

**Debug Commands:**
```bash
# Log scraping in tempo reale
tail -f storage/logs/laravel.log | grep -i "scraping\|webscraper"

# Monitor job queue
php artisan queue:monitor ingestion

# Controlla job falliti
php artisan queue:failed

# Retry job falliti
php artisan queue:retry all

# Statistiche deduplicazione
php artisan tinker --execute="
\$stats = App\Models\Document::where('source', 'web_scraper')
  ->selectRaw('COUNT(*) as total, AVG(scrape_version) as avg_version')
  ->first();
dump(\$stats);
"
```

---

## 📁 File Critici

```
backend/
├── app/Http/Controllers/Admin/
│   └── ScraperAdminController.php          # Interface admin scraper
├── app/Services/Scraper/
│   └── WebScraperService.php               # Core scraping engine
├── app/Jobs/
│   └── RunWebScrapingJob.php               # Job scraping singola URL
├── app/Console/Commands/
│   ├── RunDueScrapers.php                  # Scheduler automatico
│   ├── ScrapeUrl.php                       # Scraping singolo CLI
│   └── RescrapeDocument.php                # Re-scraping CLI
├── app/Models/
│   └── ScraperConfig.php                   # Model configurazioni
├── resources/views/admin/scraper/
│   ├── index.blade.php                     # Lista configurazioni
│   └── edit.blade.php                      # Form configurazione
└── routes/web.php                          # Route scraper admin
```

---

## 🚨 Troubleshooting

### **Problemi Comuni**

**1. 0 Documenti Creati**
```bash
✅ Check: Seed URLs accessibili
✅ Check: Include/exclude patterns corretti  
✅ Check: max_depth >= 2 (non 1)
✅ Check: Allowed domains include dominio target
✅ Check: robots.txt non blocca (se respect_robots=true)
```

**2. Contenuto Vuoto/Malformattato**
```bash
⚙️ Solution: Verifica extraction strategy
⚙️ Solution: Testa con render_js=true per SPA
⚙️ Solution: Controlla selettori CSS custom
⚙️ Debug: Ispeziona HTML sorgente manualmente
```

**3. Rate Limiting / Ban IP**
```bash
🔧 Reduce: rate_limit_rps a 0.5 o meno
🔧 Add: User-Agent diverso e realistico
🔧 Add: Auth headers se necessario  
🔧 Wait: Pausa tra sessioni scraping
```

**4. Performance Issues**
```bash
🚀 Optimize: Riduci max_depth
🚀 Optimize: Usa link_only_patterns per indici
🚀 Optimize: Abilita skip_known_urls
🚀 Monitor: Queue worker capacity
```

### **Debug Workflow**
```bash
# 1. Test configurazione
php artisan scraper:run-due --id=123 --dry-run

# 2. Test singolo URL
php artisan scraper:single 5 "https://example.com/test" --force

# 3. Monitor logs
tail -f storage/logs/laravel.log | grep "WebScraperService\|RunWebScrapingJob"

# 4. Verifica documenti creati
php artisan tinker --execute="
\$docs = App\Models\Document::where('source', 'web_scraper')
  ->where('tenant_id', 5)
  ->latest()
  ->take(5)
  ->get(['id', 'title', 'source_url', 'ingestion_status']);
dump(\$docs);
"
```

---

## 📊 KPI e Success Metrics

### **Operational KPIs**
- **Discovery Rate**: % URLs trovati vs attesi
- **Processing Success**: % URLs processati senza errori
- **Content Quality**: Score medio extraction (0-1)
- **Deduplication Efficiency**: % documenti unchanged su re-scrape

### **Business KPIs**
- **Coverage**: % contenuti target acquisiti
- **Freshness**: Tempo medio tra update sito e re-scraping
- **RAG Integration**: % contenuti scraped utilizzati in risposte
- **Cost Efficiency**: Rapporto nuovo contenuto / risorse utilizzate

### **Quality Metrics**
- **Content Completeness**: Preservazione struttura originale
- **Extraction Accuracy**: Confronto manuale vs automatico
- **Update Detection**: Precision nel rilevare cambiamenti
- **Error Recovery**: % recupero dopo fallimenti temporanei
