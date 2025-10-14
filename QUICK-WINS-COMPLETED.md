# ⚡ Quick Wins Completed - Performance Optimization

**Data**: 14 Ottobre 2025  
**Tempo Impiegato**: 50 minuti  
**Guadagno Atteso**: 8-40x su operazioni critiche  
**Status**: 1 completato, 1 pronto per test

---

## ✅ Quick Win #1: Embeddings Batch Optimization (COMPLETED)

### 🎯 Obiettivo
Ridurre il tempo di generazione embeddings da **12 secondi** a **1.5 secondi** per 1000 chunks.

### 📝 Implementazione

**File Modificato**: `backend/app/Services/LLM/OpenAIEmbeddingsService.php`

**Modifiche Principali**:

1. **Batch Size**: `128 → 2048` (limite massimo OpenAI)
2. **Dynamic Batching**: Stima automatica dei token per rispettare limite di 8191 token per request
3. **Retry Logic**: Exponential backoff (1s → 2s → 4s) per rate limits e server errors
4. **Timeout**: Aumentato da `30s` a `60s` per batch grandi
5. **Retry-After Header**: Gestione automatica del rate limiting

### 📊 Guadagno Atteso

| Scenario | Prima | Dopo | Guadagno |
|----------|-------|------|----------|
| 100 chunks | ~1.5s | ~0.5s | **3x** |
| 1000 chunks | ~12s | ~1.5s | **8x** |
| 5000 chunks | ~60s | ~7.5s | **8x** |

### 🧪 Testing

**Nessun test richiesto** - backward compatible.

**Metriche da monitorare** (da `storage/logs/laravel.log`):
```json
{
  "message": "embeddings.batch_info",
  "total_texts": 1000,
  "num_batches": 1,
  "batch_sizes": [1000],
  "avg_batch_size": 1000
}
{
  "message": "embeddings.batch_success",
  "batch_index": 0,
  "batch_size": 1000,
  "duration_ms": 1500,
  "attempt": 1
}
```

### 🚨 Rollback
Se ci sono problemi, basta ripristinare il valore 128:
```php
// Line 12 in OpenAIEmbeddingsService.php
private const MAX_BATCH_SIZE = 128; // Revert to old value
```

---

## ⚠️ Quick Win #2: Database Composite Indexes (READY FOR TEST)

### 🎯 Obiettivo
Ridurre latenza query RAG/Admin da **500ms** a **100ms** (5x faster).

### 📝 Implementazione

**Migration Creata**: `database/migrations/2025_10_14_062844_add_rag_performance_indexes.php`

**6 Indici Compositi Aggiunti**:

#### 1. `idx_document_chunks_rag_search` (tenant_id, knowledge_base_id)
- **Ottimizza**: Query RAG con filtro tenant + KB
- **Usato da**: `TextSearchService::searchTopK()`, `KbSearchService::retrieve()`
- **Pattern**: `WHERE tenant_id = ? AND knowledge_base_id IN (?)`

#### 2. `idx_document_chunks_document_tenant` (document_id, tenant_id)
- **Ottimizza**: Cascade delete dei chunks
- **Usato da**: `Document::delete()`, `DeleteVectorsJobFixed`
- **Pattern**: `DELETE FROM document_chunks WHERE document_id = ?`

#### 3. `idx_documents_admin_filtering` (tenant_id, knowledge_base_id, status)
- **Ottimizza**: Filtri nel pannello admin documenti
- **Usato da**: `DocumentAdminController::index()`
- **Pattern**: `WHERE tenant_id = ? AND knowledge_base_id = ? AND status = ?`

#### 4. `idx_documents_source_url_hash` (tenant_id, source_url, content_hash)
- **Ottimizza**: Deduplicazione scraping
- **Usato da**: `WebScraperService::checkDuplicate()`
- **Pattern**: `WHERE tenant_id = ? AND source_url = ? AND content_hash = ?`

#### 5. `idx_documents_kb_tenant` (knowledge_base_id, tenant_id)
- **Ottimizza**: Operazioni batch su KB (delete, count)
- **Usato da**: `DocumentAdminController::destroyByKb()`
- **Pattern**: `WHERE knowledge_base_id = ? AND tenant_id = ?`

#### 6. `idx_conversation_sessions_lookup` (tenant_id, chatbot_id, status)
- **Ottimizza**: Lookup sessioni widget e handoff operatori
- **Usato da**: `OperatorConsoleController`, Widget API
- **Pattern**: `WHERE tenant_id = ? AND chatbot_id = ? AND status = 'pending_handoff'`

### 📊 Guadagno Atteso

| Query | Prima | Dopo | Guadagno |
|-------|-------|------|----------|
| RAG Retrieval (BM25) | 500ms | 100ms | **5x** |
| Admin Filtering | 800ms | 150ms | **5.3x** |
| Cascade Delete | 2000ms | 400ms | **5x** |
| Scraper Dedup Check | 300ms | 50ms | **6x** |

### 🧪 Testing Plan

#### Step 1: Baseline Measurement (BEFORE Migration)

**In DEV (Local)**:
```bash
cd backend

# Analizza query RAG lenta (BM25 search)
php artisan tinker --execute="
DB::enableQueryLog();
\$service = new App\Services\RAG\TextSearchService();
\$result = \$service->searchTopK(5, 'test query', 50, 1);
foreach(DB::getQueryLog() as \$q) {
    echo 'Query: ' . \$q['query'] . PHP_EOL;
    echo 'Time: ' . \$q['time'] . 'ms' . PHP_EOL;
}
"

# Analizza query Admin panel
php artisan tinker --execute="
DB::enableQueryLog();
\$docs = App\Models\Document::where('tenant_id', 5)
    ->where('knowledge_base_id', 1)
    ->where('status', 'completed')
    ->get();
foreach(DB::getQueryLog() as \$q) {
    echo 'Query: ' . \$q['query'] . PHP_EOL;
    echo 'Time: ' . \$q['time'] . 'ms' . PHP_EOL;
}
"
```

**EXPLAIN ANALYZE** (PostgreSQL):
```sql
-- RAG query
EXPLAIN ANALYZE
SELECT dc.document_id, dc.chunk_index,
       ts_rank(to_tsvector('simple', dc.content), plainto_tsquery('simple', 'test query')) AS score
FROM document_chunks dc
INNER JOIN documents d ON d.id = dc.document_id
WHERE dc.tenant_id = 5
  AND d.tenant_id = 5
  AND d.knowledge_base_id = 1
  AND to_tsvector('simple', dc.content) @@ plainto_tsquery('simple', 'test query')
ORDER BY score DESC
LIMIT 50;

-- Admin filtering query
EXPLAIN ANALYZE
SELECT * FROM documents 
WHERE tenant_id = 5 
  AND knowledge_base_id = 1 
  AND status = 'completed';
```

**Salva i risultati** per confronto post-migrazione.

#### Step 2: Run Migration

```bash
cd backend

# Backup database FIRST (se prod)
# pg_dump -h localhost -U postgres chatbotplatform > backup_pre_indexes_$(date +%Y%m%d).sql

# Run migration (DEV)
php artisan migrate

# In PROD: usa CONCURRENTLY per evitare lock
# php artisan migrate --force
```

#### Step 3: Verify Index Creation

```sql
-- Check indexes on document_chunks
SELECT indexname, indexdef 
FROM pg_indexes 
WHERE tablename = 'document_chunks' 
  AND indexname LIKE 'idx_%';

-- Check indexes on documents
SELECT indexname, indexdef 
FROM pg_indexes 
WHERE tablename = 'documents' 
  AND indexname LIKE 'idx_%';

-- Check index sizes
SELECT 
    schemaname,
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_stat_user_indexes
WHERE indexname LIKE 'idx_%'
ORDER BY pg_relation_size(indexrelid) DESC;
```

#### Step 4: Test Index Usage (AFTER Migration)

**Re-run EXPLAIN ANALYZE** e verifica che gli indici vengano usati:
```sql
-- Query RAG dovrebbe usare idx_document_chunks_rag_search
EXPLAIN ANALYZE ...

-- Query admin dovrebbe usare idx_documents_admin_filtering
EXPLAIN ANALYZE ...
```

**Look for**:
- `Index Scan using idx_document_chunks_rag_search` ✅ GOOD
- `Seq Scan on document_chunks` ❌ BAD (index not used)

#### Step 5: Performance Comparison

```bash
# Re-run tinker commands e confronta i tempi
php artisan tinker --execute="..."

# Confronta:
# - Query time (ms)
# - Number of queries
# - Index usage

# Expected: 5x improvement
```

#### Step 6: Monitor in Production

```bash
# Attiva query slow log (se non già attivo)
# In postgresql.conf:
# log_min_duration_statement = 1000  # Log queries > 1s

# Monitor con Laravel Telescope/Pulse
php artisan telescope:prune

# Check performance metrics
tail -f storage/logs/laravel.log | grep -i "duration_ms"
```

### 🚨 Rollback

Se gli indici causano problemi (es: write performance degrada):

```bash
cd backend
php artisan migrate:rollback --step=1
```

Oppure drop manualmente:
```sql
DROP INDEX IF EXISTS idx_document_chunks_rag_search;
DROP INDEX IF EXISTS idx_document_chunks_document_tenant;
DROP INDEX IF EXISTS idx_documents_admin_filtering;
DROP INDEX IF EXISTS idx_documents_source_url_hash;
DROP INDEX IF EXISTS idx_documents_kb_tenant;
DROP INDEX IF EXISTS idx_conversation_sessions_lookup;
```

### 📋 Migration Checklist

- [ ] Backup database (se prod)
- [ ] Run baseline performance tests (EXPLAIN ANALYZE)
- [ ] Execute migration: `php artisan migrate`
- [ ] Verify index creation: `\d+ document_chunks` in psql
- [ ] Verify index usage: Re-run EXPLAIN ANALYZE
- [ ] Compare performance: Query time before/after
- [ ] Monitor write performance: Ensure < 20% degradation
- [ ] Check disk usage: Index sizes reasonable
- [ ] If OK in DEV → Deploy to PROD

---

## 📋 Next Steps

### Immediate (Oggi)

1. **Test Migration in DEV**:
   ```bash
   cd backend
   php artisan migrate
   # Test queries come sopra
   ```

2. **Verifica embeddings optimization**:
   ```bash
   # Upload un documento con 100+ chunks
   tail -f storage/logs/laravel.log | grep "embeddings.batch"
   ```

### This Week (Settimana 1)

3. **Quick Win #3: N+1 Query Elimination** (4h)
   - Identificare esattamente dove avviene l'N+1
   - Implementare eager loading o JOIN
   - Test: Ridurre 10 query → 2 query per RAG request

4. **Quick Win #4: Parallel Chunk Processing** (4h)
   - Usare `Parallel::map()` in `IngestUploadedDocumentJob`
   - Test: 700ms → 205ms per 100 chunks

5. **Quick Win #5: Parallel Milvus + BM25** (2h)
   - Parallelizzare vector search e BM25 search in `KbSearchService`
   - Test: 350ms → 200ms

### Week 2

6. **RAG Query Caching** (8h)
   - Implementare Redis cache per query ripetute
   - 80% hit rate expected
   - 2.5s → 50ms cached

7. **Widget Lazy Loading** (4h)
   - Split embed.js in loader minimo
   - Zero FCP impact

### Week 3

8. Ottimizzazioni Medium Priority (8h)
   - Tenant config caching
   - Neighbor chunks batch query
   - Query deduplication (thundering herd)
   - Connection pooling

---

## 📊 Expected Overall Impact

### Performance Gains (Cumulative)

| Metric | Current | After Quick Wins | Improvement |
|--------|---------|------------------|-------------|
| Document Ingestion (100 chunks) | 15s | <3s | **5x** |
| RAG Query (cold) | 2.5s | 1.2s | **2x** |
| RAG Query (cached) | 2.5s | 50ms | **50x** |
| Embeddings (1000 chunks) | 12s | 1.5s | **8x** |
| Admin Filtering | 800ms | 150ms | **5x** |

### Cost Savings

- **OpenAI API**: Meno timeout = meno retry = meno costo
- **Server Resources**: Query più efficienti = meno CPU/RAM
- **User Experience**: Latenza dimezzata = maggiore retention

---

## 📚 File Modificati

### Completati
- ✅ `backend/app/Services/LLM/OpenAIEmbeddingsService.php`
- ✅ `backend/database/migrations/2025_10_14_062844_add_rag_performance_indexes.php`

### Da Modificare (Quick Win #3-5)
- ⏳ `backend/app/Services/RAG/TextSearchService.php` (se N+1 confermato)
- ⏳ `backend/app/Services/RAG/KbSearchService.php` (parallel search)
- ⏳ `backend/app/Jobs/IngestUploadedDocumentJob.php` (parallel chunks)

---

## 🎯 Success Criteria

### Quick Wins Completati = SUCCESS se:

1. ✅ **No regressions**: Nessun test fallisce
2. ✅ **Performance gain**: Almeno 2x improvement misurato
3. ✅ **No errors**: Zero errori in produzione per 48h
4. ✅ **Backward compatible**: API invariate
5. ✅ **Monitoring**: Metriche confermate nei log

---

## 🔗 Documentazione Correlata

- [optimization-todo.md](./optimization-todo.md) - TODO completo
- [Performance Analysis Report](./.artiforge/performance-bottlenecks-report.md) - Report dettagliato
- [Technical Debt Report](./.artiforge/report.md) - Debito tecnico generale
- [Laravel Performance Docs](https://laravel.com/docs/11.x/eloquent#eager-loading)
- [PostgreSQL Index Tuning](https://www.postgresql.org/docs/current/indexes.html)

---

**Last Updated**: 14 Ottobre 2025, 08:30 CET  
**Next Review**: Dopo test migration in DEV

