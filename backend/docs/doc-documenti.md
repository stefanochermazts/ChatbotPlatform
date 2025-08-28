# 📄 Gestione Documenti - Documentazione Funzionalità

## 📋 Panoramica
Il sistema di gestione documenti di ChatbotPlatform gestisce l'intero ciclo di vita dei documenti: upload, scraping web, parsing, chunking, indicizzazione vettoriale e mantenimento sincronizzazione tra PostgreSQL e Milvus.

---

## 🏗️ Architettura Sistema

### **Controller e Services**
- **`DocumentAdminController`**: Gestione CRUD documenti
- **`ScraperAdminController`**: Gestione scraping web
- **`WebScraperService`**: Servizio scraping e parsing
- **`IngestUploadedDocumentJob`**: Job asincrono ingestion
- **`DeleteVectorsJobFixed`**: Eliminazione sicura da Milvus

### **Database Structure**
```sql
-- Tabella documenti
documents:
  id, tenant_id, knowledge_base_id, title, source, path, source_url,
  ingestion_status, created_at, updated_at

-- Tabella chunks
document_chunks:
  id, document_id, chunk_index, content, embedding_status, created_at
  
-- Configurazioni scraper
scraper_configs:
  id, tenant_id, knowledge_base_id, base_url, max_depth, include_patterns,
  exclude_patterns, respect_robots, created_at, updated_at
```

---

## 📥 Upload Documenti

### **🔄 1. Upload Singolo**

**Interfaccia Admin:**
```php
// Validazione upload singolo
$request->validate([
    'title' => 'required|string|max:255',
    'file' => 'required|file|mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx',
    'knowledge_base_id' => 'nullable|integer|exists:knowledge_bases,id'
]);
```

**Processo:**
1. **Validazione**: Controllo tipo file e dimensioni
2. **Storage**: Salvataggio in `storage/public/kb/{tenant_id}/`
3. **Database**: Creazione record con `ingestion_status: 'pending'`
4. **Queue**: Dispatch `IngestUploadedDocumentJob` su coda `ingestion`

### **📚 2. Upload Multiplo (Batch)**

**Interfaccia Avanzata:**
```php
// Validazione batch upload
$request->validate([
    'files' => 'required|array|min:1',
    'files.*' => 'file|mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx',
    'knowledge_base_id' => 'nullable|integer|exists:knowledge_bases,id'
]);
```

**Ottimizzazioni:**
- Processing parallelo via queue jobs
- Auto-titling da filename
- Assignment automatico a KB default se non specificata
- Gestione errori granulare (successi vs fallimenti)

### **⚙️ 3. Pipeline Ingestion**

**Job `IngestUploadedDocumentJob`:**
```php
class IngestUploadedDocumentJob implements ShouldQueue
{
    public function handle()
    {
        // 1. Parse documento (PDF/DOCX/TXT/etc.)
        $content = $this->parseDocument($document);
        
        // 2. Chunking intelligente
        $chunks = $this->chunkContent($content, $maxChars, $overlap);
        
        // 3. Genera embeddings
        $embeddings = $this->generateEmbeddings($chunks);
        
        // 4. Salva chunks in PostgreSQL
        $this->saveChunks($chunks);
        
        // 5. Indicizza in Milvus
        $this->indexInMilvus($chunks, $embeddings);
        
        // 6. Aggiorna status
        $document->update(['ingestion_status' => 'completed']);
    }
}
```

**Chunking Strategy:**
```php
// Configurazione chunking (da .env)
RAG_CHUNK_MAX_CHARS=2200      // Max caratteri per chunk
RAG_CHUNK_OVERLAP_CHARS=250   // Overlap tra chunks

// Chunking intelligente preserva:
- Paragrafi completi
- Tabelle complete  
- Liste complete
- Heading context
```

---

## 🕷️ Web Scraping

> **📖 Documentazione Completa**: Per architettura dettagliata, configurazioni avanzate e troubleshooting scraper vedi [`doc-scraper.md`](./doc-scraper.md)

### **🌐 1. Configurazione Scraper Base**

**Setup Scraper Config:**
```php
$config = ScraperConfig::create([
    'tenant_id' => $tenant->id,
    'knowledge_base_id' => $kbId,
    'base_url' => 'https://example.com',
    'max_depth' => 3,                    // Profondità crawling
    'include_patterns' => [
        '\/$',                           // Homepage
        '/servizi/.*',                   // Sezione servizi
        '/news/.*'                       // News
    ],
    'exclude_patterns' => [
        '/admin/.*',                     // Area admin
        '/login.*',                      // Pages login
        '.*\.pdf$'                       // File PDF
    ],
    'respect_robots' => true             // Rispetta robots.txt
]);
```

### **🔧 2. Processo Scraping**

**`WebScraperService` Pipeline:**
```php
public function startScraping(ScraperConfig $config): void
{
    // 1. Analisi robots.txt
    if ($config->respect_robots) {
        $robotsRules = $this->parseRobotsTxt($config->base_url);
    }
    
    // 2. Discovery URLs
    $urls = $this->discoverUrls($config);
    
    // 3. Filtering patterns
    $filteredUrls = $this->filterUrls($urls, $config);
    
    // 4. Scraping parallelo
    foreach ($filteredUrls as $url) {
        RunWebScrapingJob::dispatch($url, $config)->onQueue('ingestion');
    }
}
```

**Estrattore Contenuto Ibrido:**
```php
private function analyzeContentType(string $html): string
{
    // 1. Detect content type
    if ($this->hasResponsiveTables($html)) {
        return 'responsive_tables';
    }
    if ($this->hasArticleStructure($html)) {
        return 'article';
    }
    return 'generic';
}

private function extractContent(string $html, string $type): string
{
    switch ($type) {
        case 'responsive_tables':
            return $this->extractWithManualDOM($html);    // Tabelle responsive
        case 'article':
            return $this->extractWithReadability($html);  // Articoli
        default:
            return $this->hybridExtraction($html);        // Ibrido
    }
}
```

### **📊 3. Scraping Avanzato**

**Gestione Tabelle Responsive:**
```php
// Fix per tabelle con classi responsive
private function extractTableCells(DOMElement $row): array
{
    $cells = [];
    $allCells = $row->getElementsByTagName('td');
    
    // 1. Cerca celle desktop-visible (hidden-xs)
    foreach ($allCells as $cell) {
        if ($this->hasClass($cell, 'hidden-xs')) {
            $cells[] = $this->cleanText($cell->textContent);
        }
    }
    
    // 2. Fallback: celle non mobile-only
    if (empty($cells)) {
        foreach ($allCells as $cell) {
            if (!$this->hasClass($cell, 'visible-xs')) {
                $cells[] = $this->cleanText($cell->textContent);
            }
        }
    }
    
    return $cells;
}
```

**Source URL Management:**
```php
// Migrazione source_url per documenti esistenti
php artisan scraper:migrate-source-urls --tenant=X --dry-run
php artisan scraper:migrate-source-urls --tenant=X  # Reale
```

### **🔄 4. Re-scraping Funzionalità**

**Single URL Scraping:**
```php
// Comando scraping singolo URL
php artisan scraper:single {tenant} {url} {--force} {--kb=}

// Endpoint admin
POST /admin/tenants/{tenant}/documents/scrape-single-url
{
    "url": "https://example.com/page",
    "knowledge_base_id": 1,
    "force": true
}
```

**Force Re-scraping:**
```php
// Re-scrape documento esistente
POST /admin/documents/{document}/rescrape

// Re-scrape tutti documenti scraped del tenant
POST /admin/tenants/{tenant}/documents/rescrape-all
{
    "confirm": true
}
```

---

## 🗂️ Gestione Knowledge Bases

### **🏷️ 1. Organizzazione KB**

**Assignment Documenti:**
- Assignment automatico a KB default se non specificata
- Possibilità cambio KB post-upload
- Filtering documenti per KB nell'interfaccia admin

**Multi-KB Search:**
```php
// Flag tenant per ricerca multi-KB
$tenant->multi_kb_search = true;

// Selezione automatica KB migliore per query
$kbSelector = app(KnowledgeBaseSelector::class);
$selectedKbId = $kbSelector->selectForQuery($query, $tenantId);
```

### **📈 2. Metriche per KB**

**Statistics Dashboard:**
- Numero documenti per KB
- Status ingestion (pending/completed/failed)
- Dimensione totale contenuto
- Coverage semantic search
- Query hit rate per KB

---

## 🔄 Sincronizzazione PostgreSQL ↔ Milvus

### **⚠️ 1. Problema Orphan Documents**

**Issue Identificata:**
```php
// PROBLEMA: Race condition in DeleteVectorsJob
class DeleteVectorsJob {
    public function handle() {
        // ❌ Chunks già cancellati da PostgreSQL quando job esegue
        $chunks = DB::table('document_chunks')
                   ->whereIn('document_id', $this->documentIds)
                   ->get(); // → EMPTY!
    }
}
```

**Soluzione Implementata:**
```php
// SOLUZIONE: DeleteVectorsJobFixed con pre-calculation
class DeleteVectorsJobFixed {
    private array $primaryIds;  // Pre-calcolati!
    
    public static function fromDocumentIds(array $documentIds): self
    {
        // Calcola primaryIds PRIMA di dispatch job
        $primaryIds = [];
        $rows = DB::table('document_chunks')
                 ->whereIn('document_id', $documentIds)
                 ->select('document_id', 'chunk_index')
                 ->get();
                 
        foreach ($rows as $r) {
            $primaryIds[] = (int)(($r->document_id * 100000) + $r->chunk_index);
        }
        
        return new self($primaryIds);
    }
    
    public function handle(MilvusClient $milvus): void
    {
        // Usa primaryIds pre-calcolati
        $milvus->deleteByPrimaryIds($this->primaryIds);
    }
}
```

### **🔧 2. Fixed Document Deletion**

**Aggiornamento Controller:**
```php
// DocumentAdminController.destroy()
public function destroy(Tenant $tenant, Document $document)
{
    // 🚀 FIXED: Pre-calcola primaryIds PRIMA di cancellare chunks
    DeleteVectorsJobFixed::fromDocumentIds([$document->id])->dispatch();
    
    // Poi cancella da PostgreSQL
    DB::table('document_chunks')->where('document_id', $document->id)->delete();
    $document->delete();
}
```

---

## 🔍 Ricerca e Filtering

### **🎯 1. Interfaccia Admin**

**Filtering Avanzato:**
```php
// URL: /admin/tenants/{tenant}/documents?kb_id=1&source_url=servizi
$query = Document::where('tenant_id', $tenant->id);

if ($kbId > 0) {
    $query->where('knowledge_base_id', $kbId);
}

if (!empty($sourceUrlSearch)) {
    $query->where('source_url', 'ILIKE', '%' . $sourceUrlSearch . '%');
}

$docs = $query->orderByDesc('id')->paginate(20);
```

### **📊 2. Status Monitoring**

**Ingestion Status:**
- ⏳ `pending`: In attesa di processing
- ⚙️ `processing`: Attualmente in elaborazione  
- ✅ `completed`: Ingestion completata
- ❌ `failed`: Errore durante ingestion

**Actions per Status:**
- `pending`/`failed`: Bottone "🔄 Retry Ingestion"
- `completed`: Bottone "🔄 Re-scrape" (se source_url presente)
- Tutti: Bottone "🗑️ Elimina" (con conferma)

---

## 📁 File System Structure

### **Storage Organization**
```
storage/
├── public/kb/{tenant_id}/           # Upload documenti
│   ├── document1.pdf
│   ├── document2.docx
│   └── ...
├── scraped/{tenant_id}/             # Contenuto scraped
│   ├── example-com-v1.md
│   ├── example-com-services-v2.md
│   └── ...
└── logs/laravel.log                 # Logs ingestion/scraping
```

### **Versioning Scraped Content**
- Automatic versioning: `{domain}-v{N}.md`
- Comparison con versione precedente
- Keep history per audit e rollback

---

## ⚡ Performance e Ottimizzazioni

### **🚀 1. Queue Management**

**Code Dedicate:**
```php
// Queue priority
'ingestion'    => alta priorità (upload documenti)
'embeddings'   => media priorità (generazione embeddings)
'indexing'     => media priorità (Milvus indexing)  
'evaluation'   => bassa priorità (quality assessment)
```

**Monitoring Code:**
```bash
# Worker status
php artisan queue:work --queue=ingestion,embeddings,indexing,evaluation

# Failed jobs
php artisan queue:failed

# Retry failed
php artisan queue:retry all
```

### **📈 2. Chunking Ottimizzato**

**Strategy Configurabile:**
```php
// config/rag.php
'chunking' => [
    'max_chars' => env('RAG_CHUNK_MAX_CHARS', 2200),
    'overlap_chars' => env('RAG_CHUNK_OVERLAP_CHARS', 250),
    'preserve_paragraphs' => true,
    'preserve_tables' => true,
    'preserve_lists' => true
]
```

**Re-chunking After Config Change:**
```php
// Re-process con nuova strategia chunking
php artisan documents:rechunk --tenant={id} --dry-run
php artisan documents:rechunk --tenant={id}
```

---

## 🚨 Troubleshooting

### **Problemi Comuni**

**1. Ingestion Failed**
```bash
✅ Check: File readable e formato supportato
✅ Check: Disk space disponibile  
✅ Check: Queue worker attivo
✅ Check: OpenAI API key valida (embeddings)
✅ Check: Milvus connectivity
```

**2. Scraping 0 Pagine**
```bash
⚙️ Check: max_depth >= 3 (non 1!)
⚙️ Check: include_patterns includono homepage (\/$)
⚙️ Check: robots.txt non troppo restrittivo
⚙️ Check: URL base accessibile
```

**3. Orphan Documents Milvus**
```bash
🔧 Solution: Usa DeleteVectorsJobFixed (già implementato)
🔧 Check: Job queue processati correttamente
🔧 Debug: Log primaryIds calculation
```

**4. Re-chunking Necessario**
```bash
# Sintomi: Chunk troppo grandi/piccoli, recall basso
🔧 Aggiorna RAG_CHUNK_MAX_CHARS in .env  
🔧 Re-process documenti esistenti
🔧 Monitor: impact su performance RAG
```

### **Debug Commands**
```bash
# Check documento specifico
php artisan tinker --execute="
\$doc = App\Models\Document::find(123);
\$chunks = \$doc->chunks()->count();
echo 'Document: ' . \$doc->title . ', Chunks: ' . \$chunks;
"

# Check ingestion status tenant
php artisan tinker --execute="
\$tenant = App\Models\Tenant::find(5);
\$stats = \$tenant->documents()
    ->selectRaw('ingestion_status, count(*) as count')
    ->groupBy('ingestion_status')
    ->pluck('count', 'ingestion_status');
dump(\$stats);
"
```

---

## 📊 KPI e Metriche

### **Operational Metrics**
- **Ingestion Rate**: Documenti/ora processati
- **Success Rate**: % ingestion completate vs failed  
- **Average Processing Time**: Tempo medio per documento
- **Storage Growth**: GB/mese crescita storage

### **Quality Metrics**
- **Chunking Quality**: Overlap semantico tra chunks
- **Extraction Accuracy**: % contenuto preservato vs originale
- **Index Coverage**: % documenti indicizzati correttamente
- **Search Relevance**: Hit rate documenti in query results

### **Cost Metrics**
- **Embedding Cost**: $ per 1M token processati
- **Storage Cost**: $ per GB documenti + chunks
- **Compute Cost**: $ per ora worker queue
- **API Cost**: $ per 1K chiamate OpenAI embeddings
