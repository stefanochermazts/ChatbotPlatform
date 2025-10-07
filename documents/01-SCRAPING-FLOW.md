# Flusso di Scraping Web

## Panoramica

Il sistema di scraping web automatico permette di estrarre contenuti da siti web, inclusi quelli con JavaScript rendering, e trasformarli in documenti indicizzabili nella knowledge base.

## Diagramma del Flusso

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. CONFIGURAZIONE SCRAPER                                       │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Admin Panel → ScraperAdminController                         ││
│ │ - URL target, selettori CSS, pattern esclusione             ││
│ │ - KB destination, chunking config                            ││
│ │ - Save ScraperConfig model                                   ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. TRIGGER SCRAPING                                             │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ A) Manuale: Admin click "Scrape" button                     ││
│ │ B) CLI: php artisan scraper:scrape-single-url {url}         ││
│ │ C) CLI: php artisan scraper:scrape-for-tenant {tenantId}    ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. DISPATCH JOB                                                 │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ WebScraperJob::dispatch($url, $config)                       ││
│ │ Queue: 'scraping' (dedicata, vedi Horizon config)            ││
│ │ Retry: 3 tentativi con backoff                               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. ESECUZIONE SCRAPING                                          │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ WebScraperService::scrapeUrl()                               ││
│ │                                                               ││
│ │ A) Fetch HTML con JavaScriptRenderer (Puppeteer)            ││
│ │    - Headless Chrome                                         ││
│ │    - Wait for JS execution                                   ││
│ │    - Screenshot opzionale                                    ││
│ │                                                               ││
│ │ B) Parse HTML con DOMDocument                                ││
│ │    - Apply selettori CSS configurati                         ││
│ │    - Extract text content                                    ││
│ │    - Clean markup e whitespace                               ││
│ │                                                               ││
│ │ C) Convert to Markdown                                       ││
│ │    - Headers, links, lists, tables                           ││
│ │    - Preserve semantic structure                             ││
│ │    - Extract metadata (title, description)                   ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. SALVATAGGIO MARKDOWN                                         │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Storage::put("scraped/{tenantId}/{filename}.md", $content)  ││
│ │                                                               ││
│ │ Path pattern: storage/app/scraped/{tenantId}/{slug}.md      ││
│ │ Slug: URL normalizzato (es: wwwcomunesancesareormit.md)     ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. CREAZIONE DOCUMENT RECORD                                    │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Document::create([                                            ││
│ │   'tenant_id' => $tenantId,                                  ││
│ │   'knowledge_base_id' => $config->target_kb_id,              ││
│ │   'title' => $pageTitle,                                     ││
│ │   'path' => $relativePath,                                   ││
│ │   'source' => 'web_scraper',                                 ││
│ │   'source_url' => $originalUrl,                              ││
│ │   'content_hash' => md5($content)                            ││
│ │ ]);                                                           ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. DISPATCH INGESTION JOB                                       │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ IngestUploadedDocumentJob::dispatch($document)               ││
│ │                                                               ││
│ │ Queue: 'ingestion'                                           ││
│ │ → Continua con INGESTION FLOW (02-INGESTION-FLOW.md)        ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Classi Coinvolte

### 1. Controllers
- **`ScraperAdminController.php`**
  - `create()` - Form configurazione scraper
  - `store()` - Salva ScraperConfig
  - `scrape()` - Trigger manuale scraping

### 2. Jobs
- **`WebScraperJob.php`**
  - `handle(WebScraperService $scraper)` - Esegue scraping asincrono
  - Queue: `scraping`
  - Timeout: 300s
  - Retry: 3 tentativi

### 3. Services
- **`WebScraperService.php`**
  - `scrapeUrl(string $url, ScraperConfig $config): array`
  - `extractContent(string $html, ScraperConfig $config): string`
  - `convertToMarkdown(string $html): string`
  - `saveMarkdownFile(string $content, int $tenantId): string`

- **`JavaScriptRenderer.php`**
  - `render(string $url, array $options): string`
  - Integrazione con Puppeteer via Node.js
  - Gestione headless Chrome

### 4. Models
- **`ScraperConfig`**
  - Configurazione scraping per tenant
  - Campi: url, selectors, exclude_patterns, target_kb_id
  - Relationship: `belongsTo(Tenant::class)`

- **`Document`**
  - Record del documento scrapato
  - Campi: tenant_id, path, source='web_scraper', source_url
  - Relationship: `belongsTo(KnowledgeBase::class)`

### 5. Commands
- **`ScrapeSingleUrlCommand`**
  - `php artisan scraper:scrape-single-url {url} {--tenant=}`
  - Scraping singolo URL

- **`ScrapeForTenantCommand`**
  - `php artisan scraper:scrape-for-tenant {tenantId}`
  - Scraping batch di tutti gli URL configurati per tenant

## Esempio Pratico

### Configurazione ScraperConfig

```json
{
  "url": "https://www.comune.sancesareo.rm.it",
  "tenant_id": 5,
  "target_kb_id": 2,
  "selectors": {
    "content": ".main-content, article",
    "title": "h1.page-title"
  },
  "exclude_patterns": [
    ".sidebar",
    ".footer",
    "nav",
    ".cookie-banner"
  ],
  "js_rendering": true,
  "wait_until": "networkidle2",
  "timeout": 30000
}
```

### Esecuzione CLI

```bash
# Scraping singolo URL
php artisan scraper:scrape-single-url https://example.com --tenant=5

# Scraping tutti gli URL del tenant
php artisan scraper:scrape-for-tenant 5

# Con queue worker attivo
php artisan horizon
```

### Output Markdown Esempio

```markdown
# Chi Siamo - Comune di San Cesareo

Il Comune di San Cesareo è un'amministrazione pubblica che serve i cittadini...

## Sindaco

Alessandra Sabelli

## Assessori

- **Annalisa Benincasa** - Sportello Europa e Fondi PNRR
- **Marco Rossi** - Urbanistica e Lavori Pubblici

## Contatti

- Email: info@comune.sancesareo.rm.it
- Telefono: 06.95898200
```

## Note Tecniche

### JavaScript Rendering

Il sistema usa **Puppeteer** per rendering pagine JavaScript-heavy:

```javascript
// JavaScriptRenderer integra con Node.js script
await page.goto(url, { waitUntil: 'networkidle2' });
await page.waitForSelector('.content', { timeout: 30000 });
const html = await page.content();
```

### Content Extraction

Algoritmo di estrazione intelligente:

1. **Selettori CSS**: applica selettori configurati
2. **Cleanup**: rimuove script, style, nav, footer
3. **Markdown conversion**: preserva struttura semantica
4. **Link extraction**: mantiene link interni e deep-links

### Deduplicazione

Il sistema verifica `content_hash` per evitare duplicati:

```php
$existingDoc = Document::where('tenant_id', $tenantId)
    ->where('source_url', $url)
    ->where('content_hash', $hash)
    ->first();

if ($existingDoc) {
    Log::info('Document already exists, skipping');
    return;
}
```

## Troubleshooting

### Problema: Puppeteer timeout

**Sintomo**: Job fallisce con "Navigation timeout"

**Soluzione**:
```php
// Aumenta timeout in config
'timeout' => 60000, // 60 secondi
'wait_until' => 'domcontentloaded' // Più veloce di networkidle2
```

### Problema: Selettori non matchano

**Sintomo**: Content vuoto o parziale

**Soluzione**:
1. Verifica selettori con Chrome DevTools
2. Usa selettori più generici: `main, article, .content`
3. Abilita `js_rendering` se necessario

### Problema: Markdown malformato

**Sintomo**: Link rotti, tabelle non parsate

**Soluzione**:
- Verifica HTML source sia ben formato
- Controlla log per warning di parsing
- Usa `exclude_patterns` per rimuovere elementi problematici

## Best Practices

1. **Selettori specifici**: Usa selettori CSS precisi per evitare noise
2. **Exclude patterns**: Escludi sempre nav, footer, sidebar
3. **JS rendering**: Abilita solo se necessario (più lento e resource-intensive)
4. **Rate limiting**: Non scrapare troppo frequentemente lo stesso sito
5. **Error handling**: Monitora Horizon per job falliti
6. **Content validation**: Verifica che il markdown generato sia corretto

## Metriche e Monitoring

### Log Key

```
[SCRAPER] Starting scrape for {url}
[SCRAPER] JS rendering enabled
[SCRAPER] Content extracted: {length} chars
[SCRAPER] Markdown saved: {path}
[SCRAPER] Document created: {doc_id}
[SCRAPER] Ingestion job dispatched
```

### Performance

- **Tempo medio scraping**: 5-15s (con JS rendering)
- **Tempo medio scraping**: 1-3s (senza JS rendering)
- **Dimensione media markdown**: 10-50 KB
- **Success rate target**: >95%

## Prossimi Step

Dopo il completamento dello scraping:
1. → **[02-INGESTION-FLOW.md](02-INGESTION-FLOW.md)** - Chunking e indexing
2. → **[03-RAG-RETRIEVAL-FLOW.md](03-RAG-RETRIEVAL-FLOW.md)** - Query e retrieval

