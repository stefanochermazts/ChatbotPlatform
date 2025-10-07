# Modelli Dati e Schema Database

## Panoramica

Il database PostgreSQL 16 gestisce tutti i dati della piattaforma con isolamento multitenant rigoroso. Ogni query deve essere scopata per `tenant_id` per prevenire data leakage.

## Schema ER Semplificato

```
┌─────────────┐
│   tenants   │
│─────────────│
│ id (PK)     │───┐
│ name        │   │
│ subdomain   │   │
│ rag_settings│   │  (JSON config RAG)
│ created_at  │   │
└─────────────┘   │
                  │
        ┌─────────┴──────────────────────────────────────┐
        │                                                 │
        ↓                                                 ↓
┌──────────────────┐                            ┌─────────────────┐
│ knowledge_bases  │                            │ scraper_configs │
│──────────────────│                            │─────────────────│
│ id (PK)          │───┐                        │ id (PK)         │
│ tenant_id (FK)   │   │                        │ tenant_id (FK)  │
│ name             │   │                        │ url             │
│ description      │   │                        │ selectors       │ (JSON)
│ is_default       │   │                        │ target_kb_id    │
└──────────────────┘   │                        │ js_rendering    │
                       │                        └─────────────────┘
        ┌──────────────┴───────────┐
        │                          │
        ↓                          ↓
┌──────────────┐          ┌────────────────┐
│  documents   │          │ scraper_progress│
│──────────────│          │────────────────│
│ id (PK)      │───┐      │ id (PK)        │
│ tenant_id    │   │      │ tenant_id (FK) │
│ kb_id (FK)   │   │      │ config_id (FK) │
│ title        │   │      │ url            │
│ path         │   │      │ status         │
│ source       │   │      │ pages_scraped  │
│ source_url   │   │      │ started_at     │
│ content_hash │   │      └────────────────┘
│ status       │   │
│ chunks_count │   │
│ indexed_at   │   │
└──────────────┘   │
                   │
        ┌──────────┴──────────┐
        │                     │
        ↓                     ↓
┌──────────────────┐   ┌─────────────────┐
│ document_chunks  │   │ Milvus Vectors  │ (External DB)
│──────────────────│   │─────────────────│
│ id (PK)          │   │ chunk_id (PK)   │
│ document_id (FK) │───│ tenant_id       │
│ tenant_id        │   │ kb_id           │
│ kb_id (FK)       │   │ document_id     │
│ content          │   │ embedding       │ (float_vector[1536])
│ position         │   │ content_preview │
│ char_count       │   │ char_count      │
│ embedding_model  │   │                 │
│ embedding_dims   │   │ Partition: tenant_{id}
└──────────────────┘   └─────────────────┘
        │
        │ (Full-Text Search Index for BM25)
        ↓
    GIN Index on to_tsvector('italian', content)
```

## Tabelle Principali

### 1. `tenants`

**Scopo**: Gestione multitenant con isolamento dati

```sql
CREATE TABLE tenants (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subdomain VARCHAR(255) UNIQUE,
    email VARCHAR(255),
    logo_url TEXT,
    
    -- RAG Configuration (JSON)
    rag_settings JSONB DEFAULT '{}',
    
    -- Default KB per questo tenant
    default_kb_id BIGINT,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Soft delete
    deleted_at TIMESTAMP NULL
);

CREATE INDEX idx_tenants_subdomain ON tenants(subdomain);
```

**rag_settings JSON structure**:
```json
{
  "multi_kb_search": {
    "enabled": true,
    "max_kbs": 3,
    "threshold": 0.3
  },
  "reranking": {
    "driver": "embedding",
    "top_n": 10
  },
  "mmr": {
    "enabled": true,
    "lambda": 0.7,
    "top_n": 5
  },
  "hyde": {
    "enabled": false
  },
  "synonyms": {
    "sindaco": ["primo cittadino", "mayor"]
  }
}
```

### 2. `knowledge_bases`

**Scopo**: Organizzazione logica dei documenti per tenant

```sql
CREATE TABLE knowledge_bases (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    
    name VARCHAR(255) NOT NULL,
    description TEXT,
    
    -- Flag default KB per tenant
    is_default BOOLEAN DEFAULT false,
    
    -- Stats
    documents_count INT DEFAULT 0,
    chunks_count INT DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX idx_kb_tenant ON knowledge_bases(tenant_id);
CREATE INDEX idx_kb_default ON knowledge_bases(tenant_id, is_default);
```

**Esempio**:
- KB 1: "Documenti Ufficiali" (regolamenti, delibere)
- KB 2: "Sito Web" (contenuti pubblici)
- KB 3: "FAQ" (domande frequenti)

### 3. `documents`

**Scopo**: Metadata documenti caricati o scrapati

```sql
CREATE TABLE documents (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    knowledge_base_id BIGINT NOT NULL,
    
    -- Metadata
    title VARCHAR(500) NOT NULL,
    filename VARCHAR(255),
    mime_type VARCHAR(100),
    file_size BIGINT,
    
    -- Storage
    path TEXT NOT NULL, -- storage/app/documents/{tenant}/{file}
    
    -- Source tracking
    source VARCHAR(50) NOT NULL, -- 'upload' | 'web_scraper' | 'api'
    source_url TEXT NULL, -- URL originale se web_scraper
    
    -- Deduplication
    content_hash VARCHAR(64), -- MD5 hash del contenuto
    
    -- Processing status
    status VARCHAR(50) DEFAULT 'pending', -- 'pending' | 'processing' | 'indexed' | 'failed'
    error_message TEXT NULL,
    
    -- Stats
    chunks_count INT DEFAULT 0,
    indexed_at TIMESTAMP NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
);

CREATE INDEX idx_docs_tenant_kb ON documents(tenant_id, knowledge_base_id);
CREATE INDEX idx_docs_status ON documents(status);
CREATE INDEX idx_docs_source_url ON documents(source_url);
CREATE INDEX idx_docs_hash ON documents(content_hash);
```

### 4. `document_chunks`

**Scopo**: Chunks di testo indicizzabili con metadata

```sql
CREATE TABLE document_chunks (
    id BIGSERIAL PRIMARY KEY,
    document_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    knowledge_base_id BIGINT NOT NULL,
    
    -- Contenuto chunk
    content TEXT NOT NULL,
    position INT NOT NULL, -- Ordine nel documento originale
    char_count INT NOT NULL,
    
    -- Embeddings metadata (vettore reale in Milvus)
    embedding_model VARCHAR(100) DEFAULT 'text-embedding-3-small',
    embedding_dimensions INT DEFAULT 1536,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
);

CREATE INDEX idx_chunks_document ON document_chunks(document_id);
CREATE INDEX idx_chunks_tenant_kb ON document_chunks(tenant_id, knowledge_base_id);
CREATE INDEX idx_chunks_position ON document_chunks(document_id, position);

-- Full-Text Search Index per BM25
CREATE INDEX idx_chunks_fts 
ON document_chunks 
USING GIN (to_tsvector('italian', content));
```

### 5. `scraper_configs`

**Scopo**: Configurazione scraping per tenant

```sql
CREATE TABLE scraper_configs (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    
    -- URL base da scrapare
    url TEXT NOT NULL,
    
    -- Target KB
    target_kb_id BIGINT NOT NULL,
    
    -- Selettori CSS (JSON)
    selectors JSONB DEFAULT '{}',
    -- {"content": ".main", "title": "h1", "exclude": [".nav", ".footer"]}
    
    -- Pattern da escludere (array)
    exclude_patterns JSONB DEFAULT '[]',
    -- ["/admin", "/login", "utm_source="]
    
    -- JavaScript rendering
    js_rendering BOOLEAN DEFAULT false,
    wait_until VARCHAR(50) DEFAULT 'networkidle2', -- 'load' | 'domcontentloaded' | 'networkidle0' | 'networkidle2'
    timeout INT DEFAULT 30000, -- milliseconds
    
    -- Scheduling
    schedule_enabled BOOLEAN DEFAULT false,
    schedule_cron VARCHAR(100) NULL, -- '0 2 * * *' (daily at 2am)
    
    -- Stats
    last_run_at TIMESTAMP NULL,
    last_status VARCHAR(50) NULL,
    pages_scraped INT DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (target_kb_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE
);

CREATE INDEX idx_scraper_tenant ON scraper_configs(tenant_id);
CREATE INDEX idx_scraper_schedule ON scraper_configs(schedule_enabled, schedule_cron);
```

### 6. `scraper_progress`

**Scopo**: Tracking progress scraping batch

```sql
CREATE TABLE scraper_progress (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    scraper_config_id BIGINT NULL,
    
    url TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending', -- 'pending' | 'running' | 'completed' | 'failed'
    
    pages_scraped INT DEFAULT 0,
    pages_total INT DEFAULT 0,
    
    error_message TEXT NULL,
    
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (scraper_config_id) REFERENCES scraper_configs(id) ON DELETE SET NULL
);

CREATE INDEX idx_progress_tenant ON scraper_progress(tenant_id);
CREATE INDEX idx_progress_status ON scraper_progress(status);
```

## Milvus Collection Schema

**Collection**: `chatbot_vectors`

```python
from pymilvus import FieldSchema, CollectionSchema, DataType

fields = [
    FieldSchema(name="chunk_id", dtype=DataType.INT64, is_primary=True),
    FieldSchema(name="tenant_id", dtype=DataType.INT64),
    FieldSchema(name="kb_id", dtype=DataType.INT64),
    FieldSchema(name="document_id", dtype=DataType.INT64),
    FieldSchema(name="embedding", dtype=DataType.FLOAT_VECTOR, dim=1536),
    FieldSchema(name="content_preview", dtype=DataType.VARCHAR, max_length=500),
    FieldSchema(name="char_count", dtype=DataType.INT64)
]

schema = CollectionSchema(fields, description="Chatbot document chunks")

# Partitions per tenant isolation
partitions = [f"tenant_{tenant_id}" for tenant_id in tenant_ids]

# Index: IVF_FLAT (dev) o HNSW (prod)
index_params = {
    "metric_type": "COSINE",
    "index_type": "HNSW",
    "params": {"M": 16, "efConstruction": 200}
}
```

## Relazioni e Cascade

### Cascade Delete
```
tenants
  ├─ knowledge_bases (CASCADE)
  │   └─ documents (CASCADE)
  │       └─ document_chunks (CASCADE)
  └─ scraper_configs (CASCADE)
      └─ scraper_progress (SET NULL)
```

**Importante**: Quando si elimina un `Document`, eliminare anche i vettori da Milvus:

```php
// DocumentObserver::deleted()
public function deleted(Document $document)
{
    // 1. Delete chunks da PostgreSQL (cascade automatico)
    
    // 2. Delete vectors da Milvus
    app(MilvusClient::class)->deleteByDocumentId(
        $document->id,
        $document->tenant_id
    );
    
    // 3. Update KB stats
    $document->knowledgeBase->decrement('documents_count');
    $document->knowledgeBase->decrement('chunks_count', $document->chunks_count);
}
```

## Query Patterns Comuni

### 1. Retrieve chunks per RAG (BM25)

```php
$chunks = DB::table('document_chunks as dc')
    ->select([
        'dc.id',
        'dc.content',
        'dc.document_id',
        'd.source_url',
        DB::raw("ts_rank_cd(to_tsvector('italian', dc.content), plainto_tsquery('italian', ?)) as score")
    ])
    ->join('documents as d', 'dc.document_id', '=', 'd.id')
    ->where('dc.tenant_id', $tenantId)
    ->whereIn('dc.knowledge_base_id', $kbIds)
    ->whereRaw("to_tsvector('italian', dc.content) @@ plainto_tsquery('italian', ?)", [$query])
    ->orderByDesc('score')
    ->limit(50)
    ->get();
```

### 2. Multi-KB scoring per KB selection

```php
$kbScores = DB::table('knowledge_bases as kb')
    ->select([
        'kb.id',
        'kb.name',
        DB::raw("SUM(ts_rank_cd(to_tsvector('italian', dc.content), plainto_tsquery('italian', ?))) as total_score")
    ])
    ->join('document_chunks as dc', 'kb.id', '=', 'dc.knowledge_base_id')
    ->where('kb.tenant_id', $tenantId)
    ->whereRaw("to_tsvector('italian', dc.content) @@ plainto_tsquery('italian', ?)", [$query, $query])
    ->groupBy('kb.id', 'kb.name')
    ->havingRaw('SUM(ts_rank_cd(...)) > ?', [0.3]) // threshold
    ->orderByDesc('total_score')
    ->limit(3)
    ->get();
```

### 3. Document deduplication check

```php
$existing = Document::where('tenant_id', $tenantId)
    ->where('source_url', $url)
    ->where('content_hash', $hash)
    ->first();

if ($existing) {
    Log::info('Duplicate document detected', [
        'existing_id' => $existing->id,
        'new_hash' => $hash
    ]);
    return $existing; // Skip re-ingestion
}
```

## Indici Performance-Critical

```sql
-- 1. Tenant scoping (ogni query deve filtrare per tenant_id)
CREATE INDEX idx_chunks_tenant_kb ON document_chunks(tenant_id, knowledge_base_id);
CREATE INDEX idx_docs_tenant_kb ON documents(tenant_id, knowledge_base_id);

-- 2. Full-text search (BM25)
CREATE INDEX idx_chunks_fts ON document_chunks 
USING GIN (to_tsvector('italian', content));

-- 3. Deduplication
CREATE INDEX idx_docs_hash ON documents(content_hash);
CREATE INDEX idx_docs_source_url ON documents(source_url);

-- 4. Cascade deletes performance
CREATE INDEX idx_chunks_document ON document_chunks(document_id);

-- 5. Status filtering
CREATE INDEX idx_docs_status ON documents(status);
CREATE INDEX idx_progress_status ON scraper_progress(status);
```

## Best Practices

### 1. Tenant Isolation ⚠️ CRITICAL

**Ogni query DEVE includere `tenant_id`**:

```php
// ❌ BAD: Nessun tenant scope
DocumentChunk::where('knowledge_base_id', $kbId)->get();

// ✅ GOOD: Sempre scope per tenant
DocumentChunk::where('tenant_id', $tenantId)
    ->where('knowledge_base_id', $kbId)
    ->get();
```

### 2. Cascade Cleanup

Quando elimini un `Document`, pulisci TUTTO:

```php
DB::transaction(function() use ($document) {
    // 1. PostgreSQL (cascade automatico per chunks)
    $document->delete();
    
    // 2. Milvus vectors
    $milvus->deleteByDocumentId($document->id, $document->tenant_id);
    
    // 3. Storage files
    Storage::delete($document->path);
    
    // 4. Update KB stats
    $document->knowledgeBase->decrement('documents_count');
});
```

### 3. Content Hash per Dedup

Sempre calcola e check content hash prima ingestion:

```php
$contentHash = md5($content);

if (Document::where('tenant_id', $tenantId)
    ->where('content_hash', $contentHash)
    ->exists()) {
    
    Log::info('Duplicate content skipped');
    return;
}
```

### 4. Soft Deletes

Usa `deleted_at` per recovery:

```php
// Soft delete
$document->delete(); // Sets deleted_at

// Hard delete (no recovery)
$document->forceDelete();

// Restore
$document->restore();
```

## Migration Example

```php
// database/migrations/2025_10_07_create_documents_table.php
public function up()
{
    Schema::create('documents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
        $table->foreignId('knowledge_base_id')->constrained()->onDelete('cascade');
        
        $table->string('title', 500);
        $table->string('filename')->nullable();
        $table->string('mime_type', 100)->nullable();
        $table->bigInteger('file_size')->nullable();
        $table->text('path');
        
        $table->string('source', 50); // 'upload' | 'web_scraper'
        $table->text('source_url')->nullable();
        $table->string('content_hash', 64)->nullable();
        
        $table->string('status', 50)->default('pending');
        $table->text('error_message')->nullable();
        
        $table->integer('chunks_count')->default(0);
        $table->timestamp('indexed_at')->nullable();
        
        $table->timestamps();
        $table->softDeletes();
        
        $table->index(['tenant_id', 'knowledge_base_id']);
        $table->index('status');
        $table->index('source_url');
        $table->index('content_hash');
    });
}
```

## Prossimi Step

1. → **[06-QUEUE-WORKERS.md](06-QUEUE-WORKERS.md)** - Queue system e Horizon
2. → **[01-SCRAPING-FLOW.md](01-SCRAPING-FLOW.md)** - Come usare questi modelli nello scraping

