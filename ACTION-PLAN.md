# 🎯 Piano d'Azione - Performance Optimization

**Data**: 14 Ottobre 2025  
**Obiettivo**: Ridurre latenza RAG/Ingestion di 5-10x  
**Approccio**: Quick Wins prima, poi ottimizzazioni più complesse

---

## 📋 Status Attuale

### ✅ Completato (Oggi - 50 min)

1. **Quick Win #1: Embeddings Batch Optimization**
   - File: `OpenAIEmbeddingsService.php`
   - Batch size: 128 → 2048
   - Retry logic + exponential backoff
   - **Guadagno atteso**: 8x (12s → 1.5s per 1000 chunks)
   - **Status**: ✅ DEPLOYED, backward compatible

2. **Quick Win #2: Database Indexes**
   - Migration: `2025_10_14_062844_add_rag_performance_indexes.php`
   - 6 indici compositi creati
   - **Guadagno atteso**: 5x (500ms → 100ms query)
   - **Status**: ⚠️ PRONTO PER TEST

### 📂 Documentazione Creata

- ✅ `optimization-todo.md` - TODO completo (12 items, 36h stimate)
- ✅ `QUICK-WINS-COMPLETED.md` - Dettagli implementazione
- ✅ `TEST-QUICK-WINS.sh` - Script test automatico (Linux/Mac)
- ✅ `TEST-QUICK-WINS.ps1` - Script test automatico (Windows)
- ✅ `ACTION-PLAN.md` - Questo documento

---

## 🚀 Azioni Immediate (Oggi/Domani)

### Priorità 1: Test Quick Win #2 (Database Indexes)

**Obiettivo**: Verificare che gli indici migliorino le performance come atteso.

#### In DEV (Locale)

```powershell
# 1. Esegui test completo
cd C:\laragon\www\ChatbotPlatform
.\TEST-QUICK-WINS.ps1 dev

# Oppure manualmente:
cd backend
php artisan migrate

# Verifica indici creati
php artisan tinker --execute="
\$indexes = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = \'document_chunks\' AND indexname LIKE \'idx_%\'');
foreach(\$indexes as \$i) { echo \$i->indexname . PHP_EOL; }
"
```

**Cosa verificare**:
- [ ] Migration eseguita senza errori
- [ ] 6 indici creati correttamente
- [ ] Query RAG più veloci (confronta BEFORE/AFTER)
- [ ] Nessun errore nelle funzionalità esistenti

**Expected Results**:
```
BEFORE:
  RAG Query: ~500ms
  Admin Filtering: ~800ms

AFTER:
  RAG Query: ~100ms (-80%)
  Admin Filtering: ~150ms (-81%)
```

#### In PROD (Solo se test DEV OK)

⚠️ **ATTENZIONE**: Fare backup prima!

```powershell
# 1. Backup database
pg_dump -h localhost -U postgres chatbotplatform > backup_pre_indexes_$(date +%Y%m%d).sql

# 2. Test performance BEFORE
.\TEST-QUICK-WINS.ps1 prod  # Solo step 1 (baseline)

# 3. Run migration
cd backend
php artisan migrate --force

# 4. Test performance AFTER
.\TEST-QUICK-WINS.ps1 prod  # Completo
```

**Monitoraggio 24-48h**:
```powershell
# Monitor logs
Get-Content backend/storage/logs/laravel.log -Wait -Tail 50 | Select-String -Pattern "error|exception|slow"

# Check query performance
# Usa Laravel Telescope/Pulse se installato
```

---

### Priorità 2: Verifica Embeddings Optimization (Quick Win #1)

**Obiettivo**: Confermare che il batching migliore funzioni correttamente.

#### Test

1. **Upload documento con 100+ chunks**:
   - Admin Panel → Documenti → Upload
   - File: PDF/DOCX con ~50 pagine

2. **Monitor logs**:
   ```powershell
   Get-Content backend/storage/logs/laravel.log -Wait -Tail 20 | Select-String "embeddings.batch"
   ```

3. **Verifica output**:
   ```json
   {
     "message": "embeddings.batch_info",
     "total_texts": 1000,
     "num_batches": 1,        // ✅ Era 8 (128x8=1024)
     "avg_batch_size": 1000,  // ✅ Era 128
   }
   {
     "message": "embeddings.batch_success",
     "batch_index": 0,
     "batch_size": 1000,
     "duration_ms": 1500,     // ✅ Era ~12000
     "attempt": 1
   }
   ```

**Expected**: 
- Meno batch (1 invece di 8)
- Durata totale ~8x più veloce
- Nessun errore OpenAI rate limit

---

## 📅 Prossime Settimane

### Settimana 1 (16h effort)

#### Quick Win #3: N+1 Query Elimination (4h)
**Obiettivo**: Ridurre numero di query da 25 → 5 per RAG request

**Azione**:
1. Identificare dove avviene N+1 (probabilmente in elaborazione citations)
2. Implementare eager loading o JOIN
3. Test: Misurare query count BEFORE/AFTER

**Files da modificare**:
- `ChatCompletionsController.php` (se lì avviene l'N+1)
- Oppure servizi RAG

**Guadagno atteso**: 2.7x (700ms → 260ms per 10 citations)

---

#### Quick Win #4: Parallel Chunk Processing (4h)
**Obiettivo**: Velocizzare ingestion con processing parallelo

**Azione**:
```php
// IngestUploadedDocumentJob.php
use Illuminate\Support\Facades\Parallel;

$sanitizedChunks = Parallel::map($chunks, function($content) {
    return $this->sanitizeUtf8Content($content);
})->all();
```

**Guadagno atteso**: 3.4x (700ms → 205ms per 100 chunks)

⚠️ **Requirement**: PHP 8.1+ con fiber support

---

#### Quick Win #5: Parallel Milvus + BM25 (2h)
**Obiettivo**: Eseguire vector search e BM25 in parallelo

**Azione**:
```php
// KbSearchService.php
[$vectorResults, $bm25Results] = Parallel::run([
    fn() => $this->milvus->search(...),
    fn() => $this->textSearch->search(...),
]);
```

**Guadagno atteso**: 1.75x (350ms → 200ms)

---

#### Optimization #6: Batch Operations Async (8h)
**Obiettivo**: Eliminare timeout su batch rescrape

**Azione**:
1. Creare `RescrapeDocumentJob` per singolo documento
2. Usare `Bus::batch()` per orchestrazione
3. Aggiungere monitoring UI per progress
4. Test con 100+ documenti

**Guadagno atteso**: No timeout, instant response

---

### Settimana 2 (12h effort)

#### Optimization #7: RAG Query Caching (8h)
**Obiettivo**: 80% hit rate, 57x faster per cached queries

**Azione**:
```php
$cacheKey = "rag:v1:{$tenantId}:" . hash('xxh3', $normalizedQuery);

$result = Cache::tags(["tenant:{$tenantId}:rag", "kb:{$kbId}"])
    ->remember($cacheKey, 3600, fn() => $this->performRetrieval(...));
```

**Invalidation**: On document update/delete

**Guadagno atteso**: 
- Cache miss: 2.5s
- Cache hit: 50ms (**50x faster**)
- Expected hit rate: 80%

---

#### Optimization #8: Widget Lazy Loading (4h)
**Obiettivo**: Zero FCP impact

**Azione**:
1. Split `chatbot-embed.js` in minimal loader (5KB)
2. Load full widget only on user interaction
3. Preconnect to critical domains

**Guadagno atteso**: 500ms → 0ms FCP impact

---

### Settimana 3 (8h effort)

- Tenant config caching (2h)
- Neighbor chunks batch query (2h)
- Query deduplication (thundering herd) (2h)
- Connection pooling (2h)

---

## 📊 Metriche di Successo

### Target Performance (Entro 3 settimane)

| Metrica | Baseline | Target | Improvement |
|---------|----------|--------|-------------|
| Document Ingestion (100 chunks) | 15s | <3s | **5x** |
| RAG Query (cold) | 2.5s | <1s | **2.5x** |
| RAG Query (cached) | 2.5s | <50ms | **50x** |
| Embeddings (1000 chunks) | 12s | <1.5s | **8x** |
| Admin Filtering | 800ms | <150ms | **5x** |
| P95 Latency RAG | ~3s | <1.5s | **2x** |
| Cache Hit Rate | 0% | >80% | ♾️ |

### Monitoring

**Laravel Logs**:
```powershell
# Real-time monitoring
Get-Content backend/storage/logs/laravel.log -Wait -Tail 50 | Select-String -Pattern "duration_ms|embeddings|rag.cache"
```

**Database**:
```sql
-- Slow queries (PostgreSQL)
SELECT query, mean_exec_time, calls 
FROM pg_stat_statements 
WHERE mean_exec_time > 1000 
ORDER BY mean_exec_time DESC 
LIMIT 10;
```

**Application**:
- Laravel Telescope (se installato)
- Laravel Pulse (se installato)
- Custom metrics via Log::info()

---

## 🚨 Problemi e Rollback

### Se Indexes Degradano Write Performance

```bash
# Rollback migration
cd backend
php artisan migrate:rollback --step=1

# Oppure drop selettivo
psql -d chatbotplatform -c "DROP INDEX idx_document_chunks_rag_search;"
```

### Se Embeddings Batch Causa Rate Limit

```php
// OpenAIEmbeddingsService.php, line 12
private const MAX_BATCH_SIZE = 128; // Revert
```

### Se Parallel Processing Causa Crash

```php
// Rimuovi Parallel::map() e torna al loop sequenziale
foreach ($chunks as $chunk) {
    $sanitized[] = $this->sanitizeUtf8Content($chunk);
}
```

---

## 📞 Supporto

### Documentazione
- [optimization-todo.md](./optimization-todo.md) - TODO dettagliato
- [QUICK-WINS-COMPLETED.md](./QUICK-WINS-COMPLETED.md) - Dettagli tecnici
- [Laravel Performance Docs](https://laravel.com/docs/11.x/optimization)

### Tools
- [TEST-QUICK-WINS.ps1](./TEST-QUICK-WINS.ps1) - Script test Windows
- [TEST-QUICK-WINS.sh](./TEST-QUICK-WINS.sh) - Script test Linux

### Comandi Utili

```powershell
# Test performance query
cd backend
php artisan tinker --execute="\$service = new App\Services\RAG\TextSearchService(); \$service->searchTopK(1, 'test', 50);"

# Monitor queue jobs
php artisan queue:work --verbose

# Check database indexes
php artisan tinker --execute="DB::select('SELECT * FROM pg_indexes WHERE tablename = \'document_chunks\'');"

# Clear all caches
php artisan optimize:clear
```

---

## ✅ Checklist Finale

### Prima di Chiudere Ogni Optimization

- [ ] Test funzionali passano
- [ ] Performance improvement misurato e documentato
- [ ] Nessun errore in log per 24h
- [ ] Metriche monitorate
- [ ] Documentazione aggiornata
- [ ] Rollback plan testato (se critico)
- [ ] Team informato delle modifiche

### Prima di Deploy in Produzione

- [ ] Test completi in DEV
- [ ] Backup database
- [ ] Downtime window comunicato (se necessario)
- [ ] Rollback plan pronto
- [ ] Monitoring attivo
- [ ] On-call coverage

---

**Last Updated**: 14 Ottobre 2025, 08:45 CET  
**Next Review**: Dopo test Quick Win #2 in DEV  
**Owner**: Development Team

