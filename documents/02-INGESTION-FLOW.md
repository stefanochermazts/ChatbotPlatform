# Flusso di Ingestion Documenti

## Panoramica

La pipeline di ingestion trasforma documenti raw (PDF, DOCX, TXT, Markdown) in chunks indicizzati con embeddings vettoriali, pronti per il retrieval RAG.

## Diagramma del Flusso

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. TRIGGER INGESTION                                            │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ A) Admin upload documento → DocumentAdminController          ││
│ │ B) Scraper crea Document → WebScraperService                 ││
│ │ C) CLI: php artisan documents:reprocess {docId}              ││
│ │                                                               ││
│ │ Document record creato con:                                  ││
│ │ - tenant_id, knowledge_base_id                               ││
│ │ - path (storage/app/documents/{tenantId}/{file})             ││
│ │ - source ('upload' | 'web_scraper')                          ││
│ │ - source_url (per scraped docs)                              ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. DISPATCH JOB                                                 │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ IngestUploadedDocumentJob::dispatch($document)               ││
│ │                                                               ││
│ │ Queue: 'ingestion'                                           ││
│ │ Retry: 3 tentativi                                           ││
│ │ Timeout: 600s (10 minuti per documenti grandi)               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. TEXT EXTRACTION                                              │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ DocumentParserService::extractText($path, $mimeType)         ││
│ │                                                               ││
│ │ PDF:  Smalot\PdfParser                                       ││
│ │       - Extract text layer                                   ││
│ │       - Preserve layout with line breaks                     ││
│ │       - Extract metadata (author, title, date)               ││
│ │                                                               ││
│ │ DOCX: PhpOffice\PhpWord                                      ││
│ │       - Parse XML structure                                  ││
│ │       - Extract text from paragraphs, tables, headers        ││
│ │       - Preserve formatting markers                          ││
│ │                                                               ││
│ │ TXT/MD: file_get_contents()                                  ││
│ │       - UTF-8 encoding detection                             ││
│ │       - BOM removal                                          ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. TEXT CLEANING                                                │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ TextCleaningService::clean($rawText)                         ││
│ │                                                               ││
│ │ - Remove excessive whitespace (normalize to single space)    ││
│ │ - Fix line breaks (preserve paragraph boundaries)            ││
│ │ - Remove control characters                                  ││
│ │ - Normalize unicode (NFC normalization)                      ││
│ │ - Fix common OCR errors (optional)                           ││
│ │ - Remove footer/header patterns (page numbers, etc.)         ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. CHUNKING (✅ TENANT-AWARE)                                   │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ ChunkingService::chunk($text, $tenantId, $options)           ││
│ │                                                               ││
│ │ ✅ Tenant-Aware Configuration (3-level hierarchy):           ││
│ │ 1. Tenant-specific (DB: tenants.rag_settings JSON)          ││
│ │ 2. Profile defaults (future)                                 ││
│ │ 3. Global defaults (config/rag.php)                          ││
│ │                                                               ││
│ │ Config Resolution:                                           ││
│ │ - TenantRagConfigService::getChunkingConfig($tenantId)       ││
│ │ - RAG_CHUNK_MAX_CHARS: 2200 (global default)                 ││
│ │ - RAG_CHUNK_OVERLAP_CHARS: 250 (global default)              ││
│ │ - Tenant-specific overrides: e.g., Tenant 5 uses 3000 chars ││
│ │                                                               ││
│ │ Strategia:                                                   ││
│ │ 1. Split su paragraph boundaries (\n\n)                      ││
│ │ 2. Se paragraph > MAX_CHARS:                                 ││
│ │    → Split su sentence boundaries (. ! ?)                    ││
│ │ 3. Se sentence > MAX_CHARS:                                  ││
│ │    → Hard split con overlap                                  ││
│ │ 4. Merge small chunks (<200 chars) con successivi            ││
│ │ 5. Add overlap tra chunk consecutivi                         ││
│ │                                                               ││
│ │ Output: array di chunk con metadata:                         ││
│ │ - content (string)                                           ││
│ │ - position (int, ordine nel documento)                       ││
│ │ - char_count (int)                                           ││
│ │                                                               ││
│ │ ⚠️ NOTA: I parametri di chunking sono applicati per tenant.  ││
│ │ Modifiche ai parametri richiedono re-ingestion documenti.   ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. EMBEDDINGS GENERATION                                        │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ OpenAIEmbeddingsService::embed($chunks)                      ││
│ │                                                               ││
│ │ Model: text-embedding-3-small (default)                      ││
│ │ Dimensions: 1536                                             ││
│ │                                                               ││
│ │ Batch processing:                                            ││
│ │ - Max 100 chunks per API call (OpenAI limit)                 ││
│ │ - Retry con exponential backoff su rate limit                ││
│ │ - Cache embeddings per deduplicazione                        ││
│ │                                                               ││
│ │ API Call:                                                    ││
│ │ POST https://api.openai.com/v1/embeddings                    ││
│ │ {                                                             ││
│ │   "model": "text-embedding-3-small",                         ││
│ │   "input": ["chunk1", "chunk2", ...]                         ││
│ │ }                                                             ││
│ │                                                               ││
│ │ Response: array di vectors [float, float, ...]               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. DATABASE STORAGE (PostgreSQL)                                │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ DocumentChunk::create([                                       ││
│ │   'document_id' => $document->id,                            ││
│ │   'tenant_id' => $document->tenant_id,                       ││
│ │   'knowledge_base_id' => $document->knowledge_base_id,       ││
│ │   'content' => $chunkText,                                   ││
│ │   'position' => $position,                                   ││
│ │   'char_count' => strlen($chunkText),                        ││
│ │   'embedding_model' => 'text-embedding-3-small',             ││
│ │   'embedding_dimensions' => 1536                             ││
│ │ ]);                                                           ││
│ │                                                               ││
│ │ Indici PostgreSQL:                                           ││
│ │ - tenant_id, knowledge_base_id (per scoping)                 ││
│ │ - document_id (per cascade delete)                           ││
│ │ - Full-text search index su content (GIN, per BM25)          ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. MILVUS VECTOR INDEXING                                       │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ MilvusClient::indexChunks($chunks, $embeddings)              ││
│ │                                                               ││
│ │ Collection: chatbot_vectors                                  ││
│ │ Partition: tenant_{tenantId}                                 ││
│ │                                                               ││
│ │ Schema:                                                      ││
│ │ - chunk_id (PK, int64)                                       ││
│ │ - tenant_id (int64, filterable)                              ││
│ │ - kb_id (int64, filterable)                                  ││
│ │ - document_id (int64, filterable)                            ││
│ │ - embedding (float_vector, dim=1536)                         ││
│ │ - content_preview (varchar, 500 chars)                       ││
│ │ - char_count (int64)                                         ││
│ │                                                               ││
│ │ Index: IVF_FLAT (default) o HNSW (production)                ││
│ │ Metric: COSINE (similarità coseno)                           ││
│ │                                                               ││
│ │ Batch insert (max 1000 vectors per call):                    ││
│ │ client.insert(                                               ││
│ │   collection_name="chatbot_vectors",                         ││
│ │   partition_name=f"tenant_{tenant_id}",                      ││
│ │   entities=[...]                                             ││
│ │ )                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 9. UPDATE DOCUMENT STATUS                                       │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ $document->update([                                           ││
│ │   'status' => 'indexed',                                     ││
│ │   'chunks_count' => $chunksCount,                            ││
│ │   'indexed_at' => now()                                      ││
│ │ ]);                                                           ││
│ │                                                               ││
│ │ Log::info('Document ingestion completed', [                  ││
│ │   'document_id' => $document->id,                            ││
│ │   'chunks_count' => $chunksCount,                            ││
│ │   'duration_ms' => $durationMs                               ││
│ │ ]);                                                           ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Classi Coinvolte

### 1. Jobs
- **`IngestUploadedDocumentJob.php`**
  - `handle()` - Orchestratore pipeline completa
  - Queue: `ingestion`
  - Timeout: 600s
  - Retry: 3 tentativi con exponential backoff

### 2. Services
- **`DocumentParserService.php`**
  - `extractText(string $path, string $mimeType): string`
  - Supporta: PDF, DOCX, TXT, Markdown
  - Parser libraries: Smalot/PdfParser, PhpOffice/PhpWord

- **`TextCleaningService.php`**
  - `clean(string $rawText): string`
  - Normalizzazione unicode, rimozione whitespace, fix OCR

- **`ChunkingService.php`**
  - `chunkText(string $text, array $config): array`
  - Strategia sliding window con overlap
  - Config da `config/rag.php`

- **`OpenAIEmbeddingsService.php`**
  - `embed(array $texts): array`
  - Batch API calls con retry
  - Model: `text-embedding-3-small`

- **`MilvusClient.php`**
  - `indexChunks(array $chunks, array $embeddings): void`
  - Integrazione con Milvus via Python bridge
  - Partition-based multitenancy

### 3. Models
- **`Document`**
  - Status: `pending` → `processing` → `indexed` | `failed`
  - Relationship: `hasMany(DocumentChunk::class)`

- **`DocumentChunk`**
  - Contenuto chunk + metadata
  - Relationship: `belongsTo(Document::class)`
  - Full-text search index per BM25

## Esempio Pratico

### Configurazione Chunking (.env)

```env
RAG_CHUNK_MAX_CHARS=2200
RAG_CHUNK_OVERLAP_CHARS=250
RAG_CHUNK_MIN_CHARS=100
```

### Esecuzione Ingestion

```bash
# Via admin panel (automatico dopo upload)
# → DocumentAdminController::store()

# Via CLI (riprocessamento manuale)
php artisan documents:reprocess 3920

# Monitoring queue
php artisan horizon
# → Dashboard: http://localhost/horizon
```

### Output Log Esempio

```
[INGESTION] Starting for document_id=3920
[INGESTION] Extracted 15,234 characters from PDF
[INGESTION] Cleaned to 14,892 characters
[INGESTION] Created 8 chunks (avg 1,861 chars)
[INGESTION] Generated 8 embeddings in 2.3s
[INGESTION] Stored 8 chunks in PostgreSQL
[INGESTION] Indexed 8 vectors in Milvus partition tenant_5
[INGESTION] Completed in 4.8s
```

## Note Tecniche

### Chunking Strategy

Il chunking è il processo più critico. Strategia implementata:

1. **Preserve semantic units**: Preferire split su paragraph boundaries
2. **Overlap for context**: 250 chars overlap tra chunk consecutivi
3. **Size optimization**: Target 2200 chars (≈500 tokens GPT)
4. **Merge small chunks**: Chunk <100 chars vengono mergiati

```php
// Esempio di chunk con overlap
Chunk 1: "Lorem ipsum dolor sit amet..." [chars 0-2200]
Chunk 2: "...sit amet consectetur..." [chars 1950-4150]
         ↑ overlap 250 chars      ↑
```

### Embeddings Batching

OpenAI permette max 100 input per call. Batching logic:

```php
$batches = array_chunk($chunks, 100);
foreach ($batches as $batch) {
    $embeddings = $this->openai->embeddings([
        'model' => 'text-embedding-3-small',
        'input' => $batch
    ]);
    
    // Rate limit handling
    if ($response->status === 429) {
        $retryAfter = $response->header('Retry-After');
        sleep($retryAfter);
        retry();
    }
}
```

### Milvus Partitioning

Ogni tenant ha una partition dedicata per isolamento:

```python
# Collection: chatbot_vectors
# Partitions: tenant_1, tenant_2, tenant_3, ...

collection.create_partition(f"tenant_{tenant_id}")

# Search su partition specifica (più veloce)
collection.search(
    data=[query_embedding],
    anns_field="embedding",
    param={"metric_type": "COSINE", "params": {"nprobe": 10}},
    partition_names=[f"tenant_{tenant_id}"],
    limit=20
)
```

## Troubleshooting

### Problema: PDF extraction fallisce

**Sintomo**: "Unable to extract text from PDF"

**Cause possibili**:
- PDF scansionato senza OCR (solo immagini)
- PDF protetto da password
- Encoding non UTF-8

**Soluzione**:
```php
// Check PDF con OCR layer
if ($parser->getText() === '') {
    throw new Exception('PDF requires OCR processing');
}

// Alternative: Tesseract OCR integration
```

### Problema: Embedding API rate limit

**Sintomo**: Job retry multipli, "Rate limit exceeded"

**Soluzione**:
```php
// Implementa exponential backoff
$this->retry([
    'delay' => 60, // 60 secondi tra retry
    'backoff' => 'exponential' // 60, 120, 240...
]);

// Reduce batch size
$batches = array_chunk($chunks, 50); // invece di 100
```

### Problema: Milvus connection timeout

**Sintomo**: "Connection to Milvus failed"

**Soluzione**:
```bash
# Verifica Milvus è running
docker ps | grep milvus

# Restart Milvus
docker-compose restart milvus-standalone

# Check Python bridge
python backend/milvus_search.py
```

### Problema: Chunk duplicati

**Sintomo**: Stessi chunk indicizzati multiple volte

**Soluzione**:
```php
// Delete old chunks prima di re-ingestion
DocumentChunk::where('document_id', $docId)->delete();

// Sync delete da Milvus
$milvus->deleteByDocumentId($docId, $tenantId);
```

## Best Practices

1. **Chunk size**: 2000-2500 chars è ottimale per GPT-4 context
2. **Overlap**: 200-300 chars garantisce continuità semantica
3. **Batch embeddings**: Max 100 chunks per API call
4. **Error handling**: Retry con exponential backoff su rate limit
5. **Monitoring**: Log timing di ogni step per profiling
6. **Cleanup**: Delete old chunks da PostgreSQL E Milvus prima re-ingestion
7. **Validation**: Verifica embedding dimensions (1536 per text-embedding-3-small)

## Metriche e Monitoring

### Performance Target

- **Ingestion completa**: <10s per documento medio (50 KB)
- **Chunking**: <1s per documento
- **Embeddings**: <3s per batch 100 chunks
- **Milvus indexing**: <2s per batch 1000 vectors

### Log Key Points

```
[INGESTION] document_id={id} tenant_id={tenant} status=started
[INGESTION] text_extracted chars={count}
[INGESTION] chunks_created count={num} avg_chars={avg}
[INGESTION] embeddings_generated duration_ms={ms}
[INGESTION] postgres_stored chunks={num}
[INGESTION] milvus_indexed vectors={num} partition={name}
[INGESTION] completed duration_ms={total}
```

## Prossimi Step

Dopo completamento ingestion:
1. → **[03-RAG-RETRIEVAL-FLOW.md](03-RAG-RETRIEVAL-FLOW.md)** - Query e retrieval
2. → **[06-QUEUE-WORKERS.md](06-QUEUE-WORKERS.md)** - Monitoring Horizon

