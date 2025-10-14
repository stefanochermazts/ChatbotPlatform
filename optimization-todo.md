# 🚀 Performance Optimization TODO - ChatbotPlatform

**Created**: 14 Ottobre 2025  
**Target Completion**: 3 settimane  
**Expected Overall Gain**: 5-10x performance improvement

---

## 📊 Progress Overview

```
COMPLETED: ████░░░░░░░░░░░░░░░░ 2/12 (17%)
CRITICAL:  ███░░░░░░░░░░░░░░░░░ 2/6  (33%)
HIGH:      ░░░░░░░░░░░░░░░░░░░░ 0/2  (0%)
MEDIUM:    ░░░░░░░░░░░░░░░░░░░░ 0/4  (0%)
```

**Estimated Total Effort**: 36 ore (~1 settimana full-time)  
**Actual Time Spent**: 2h

---

## 🔴 WEEK 1: Critical Fixes (16h target)

### ⚡ Quick Win #1: Embeddings Batch Size Optimization

**Priority**: 🔴 CRITICAL  
**Effort**: 2h  
**Gain**: 8x faster (12s → 1.5s per 1000 chunks)  
**Status**: ✅ COMPLETED (2025-10-14)

**Files to Modify**:
- `backend/app/Services/LLM/OpenAIEmbeddingsService.php`

**Changes**:
```php
Line 41: 
- $batches = array_chunk($clean, 128);
+ $batches = array_chunk($clean, 2048); // OpenAI max limit

// TODO: Implement dynamic batching based on token count
// TODO: Add retry logic with exponential backoff
// TODO: Add timeout 60s for large batches
```

**Testing**:
- [ ] Test con documento 100 chunks
- [ ] Test con documento 1000 chunks (max batch)
- [ ] Test con documento 5000 chunks (multiple batches)
- [ ] Verify no OpenAI rate limit errors
- [ ] Measure performance improvement

**Rollback Plan**: Revert to batch size 128 if issues

---

### ⚡ Quick Win #2: Database Composite Indexes

**Priority**: 🔴 CRITICAL  
**Effort**: 4h (include testing)  
**Gain**: 5x faster queries (500ms → 100ms)  
**Status**: ✅ MIGRATION CREATED (2025-10-14) - ⏳ TESTING REQUIRED

**Migration to Create**:
```bash
php artisan make:migration add_rag_performance_indexes
```

**Indexes to Add**:
1. `idx_document_chunks_rag_search` - tenant + KB filtering
2. `idx_document_chunks_content_fts` - Full-text search (verify exists)
3. `idx_documents_admin_filtering` - Admin panel filters
4. `idx_documents_source_url_hash` - Deduplication
5. `idx_document_chunks_document_tenant` - Cascade delete
6. `idx_conversation_sessions_lookup` - Widget handoff

**Testing**:
- [ ] Run EXPLAIN ANALYZE on slow queries
- [ ] Verify index usage with EXPLAIN
- [ ] Measure query time before/after
- [ ] Check index size impact on disk
- [ ] Verify no slow down on writes

**Rollback Plan**: Drop indexes if write performance degrades >20%

---

### ⚡ Quick Win #3: N+1 Query Elimination

**Priority**: 🔴 CRITICAL  
**Effort**: 4h  
**Gain**: 2.7x faster (700ms → 260ms per 10 citations)  
**Status**: ✅ COMPLETED (2025-10-14)

**Files to Modify**:
- `backend/app/Services/RAG/TextSearchService.php`
- `backend/app/Services/RAG/KbSearchService.php`

**Changes**:
```php
// TextSearchService.php - Add JOIN
->join('documents as d', 'dc.document_id', '=', 'd.id')
->select([
    'dc.*',
    'd.title as document_title',
    'd.source_url as document_source_url',
    'd.knowledge_base_id',
])

// KbSearchService.php - Eager loading
$chunks = DocumentChunk::whereIn('id', $chunkIds)
    ->with(['document:id,title,source_url,knowledge_base_id'])
    ->get();
```

**Testing**:
- [ ] Test RAG query con 5 citations
- [ ] Test RAG query con 20 citations
- [ ] Verify no missing document data
- [ ] Check SQL query count (should be 2-3 total)
- [ ] Measure latency improvement

**Rollback Plan**: Revert to individual queries if data inconsistencies

---

### 🔄 Optimization #4: Parallel Chunk Processing

**Priority**: 🔴 CRITICAL  
**Effort**: 4h  
**Gain**: 3.4x faster (700ms → 205ms per 100 chunks)  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Jobs/IngestUploadedDocumentJob.php`

**Changes**:
```php
// Use Illuminate\Support\Facades\Parallel
$sanitizedChunks = Parallel::map($chunks, function($content, $index) {
    return [
        'content' => $this->sanitizeUtf8Content($content),
        // ... other fields
    ];
})->all();
```

**Testing**:
- [ ] Test ingestion 50 chunks
- [ ] Test ingestion 200 chunks
- [ ] Test ingestion 1000 chunks
- [ ] Verify no content corruption
- [ ] Measure CPU usage (should increase with parallel)
- [ ] Check memory usage stays reasonable

**Notes**: 
- Parallel processing richiede PHP 8.1+ con fiber support
- Verificare configurazione server supporta parallel execution

---

### 🔄 Optimization #5: Parallel Milvus + BM25 Search

**Priority**: 🔴 CRITICAL  
**Effort**: 2h  
**Gain**: 1.75x faster (350ms → 200ms)  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Services/RAG/KbSearchService.php`

**Changes**:
```php
[$vectorResults, $bm25Results] = Parallel::run([
    fn() => $this->milvus->search($embedding, $tenantId, $kbIds, $vectorTopK),
    fn() => $this->textSearch->search($query, $tenantId, $kbIds, $bm25TopK),
]);
```

**Testing**:
- [ ] Test RAG query normal
- [ ] Test RAG query con multi-KB
- [ ] Verify results consistency vs sequential
- [ ] Measure latency improvement
- [ ] Check no race conditions

---

### 🔄 Optimization #6: Batch Operations Async

**Priority**: 🔴 HIGH  
**Effort**: 8h  
**Gain**: No timeout, instant response (<100ms)  
**Status**: ⏳ TODO

**New Files to Create**:
- `backend/app/Jobs/RescrapeDocumentJob.php`
- `backend/app/Http/Controllers/Admin/BatchStatusController.php`

**Files to Modify**:
- `backend/app/Http/Controllers/Admin/DocumentAdminController.php`

**Changes**:
```php
// rescrapeAll() method
Bus::batch($jobs)
    ->name("Rescrape Tenant {$tenant->id}")
    ->dispatch();

return response()->json([
    'batch_id' => $batch->id,
    'monitoring_url' => route('admin.batch.status', $batch->id)
]);
```

**Testing**:
- [ ] Test batch con 5 documenti
- [ ] Test batch con 50 documenti
- [ ] Test batch con 200 documenti
- [ ] Verify progress monitoring works
- [ ] Test batch cancellation
- [ ] Test batch failure handling

**Routes to Add**:
```php
Route::get('/admin/batch/{batch}/status', [BatchStatusController::class, 'show']);
Route::post('/admin/batch/{batch}/cancel', [BatchStatusController::class, 'cancel']);
```

---

## 🟡 WEEK 2: High Priority (12h target)

### 💾 Optimization #7: RAG Query Caching

**Priority**: 🟡 HIGH  
**Effort**: 8h  
**Gain**: 57x faster cached (2.5s → 50ms), 80% hit rate expected  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Services/RAG/KbSearchService.php`
- `backend/app/Observers/DocumentObserver.php`

**Implementation Steps**:
1. [ ] Add caching to `retrieve()` method
2. [ ] Implement cache key generation (tenant + query hash)
3. [ ] Add cache tags (tenant, KB)
4. [ ] Implement invalidation on document update/delete
5. [ ] Add cache hit/miss metrics
6. [ ] Configure Redis for production

**Cache Strategy**:
```php
$cacheKey = "rag:v1:{$tenantId}:" . hash('xxh3', $normalizedQuery);

Cache::tags(["tenant:{$tenantId}:rag", "kb:{$kbId}"])
    ->remember($cacheKey, 3600, fn() => $this->performRetrieval(...));
```

**Testing**:
- [ ] Test cache miss (first query)
- [ ] Test cache hit (repeated query)
- [ ] Test cache invalidation on document update
- [ ] Test cache invalidation on document delete
- [ ] Measure hit rate dopo 1h di uso
- [ ] Verify cache size stays reasonable

**Monitoring**:
```php
Log::info('rag.cache', [
    'tenant_id' => $tenantId,
    'query_hash' => $queryHash,
    'cache_hit' => $hit,
    'duration_ms' => $duration,
]);
```

---

### 📦 Optimization #8: Widget Lazy Loading

**Priority**: 🟡 HIGH  
**Effort**: 4h  
**Gain**: Zero FCP impact (500ms → 0ms)  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/public/widget/embed/chatbot-embed.js`
- `backend/public/widget/js/chatbot-widget.js`

**Implementation**:
1. [ ] Split embed.js (5KB minimal loader)
2. [ ] Load widget.js only on user click
3. [ ] Load CSS asynchronously
4. [ ] Add loading spinner while loading
5. [ ] Preconnect to OpenAI domains

**Testing**:
- [ ] Test widget load on click
- [ ] Test widget load on scroll
- [ ] Measure FCP impact (should be ~0ms)
- [ ] Verify no broken functionality
- [ ] Test on slow 3G connection

**Webpack Config**:
```javascript
optimization: {
    splitChunks: {
        chunks: 'async',
        cacheGroups: {
            widget: { name: 'widget', priority: 10 },
            vendor: { name: 'vendor', priority: 5 }
        }
    }
}
```

---

## 🟢 WEEK 3: Medium Priority (8h target)

### 🔧 Optimization #9: Tenant Config Caching

**Priority**: 🟢 MEDIUM  
**Effort**: 2h  
**Gain**: 50x faster (50ms → 1ms)  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Services/RAG/TenantRagConfigService.php`

**Changes**:
```php
public function getAdvancedConfig(int $tenantId): array {
    return Cache::remember("tenant:{$tenantId}:rag:advanced", 300, function() {
        // ... fetch config
    });
}
```

**Testing**:
- [ ] Test config fetch (should be cached)
- [ ] Test cache invalidation on tenant update
- [ ] Verify no stale config issues

---

### 🔧 Optimization #10: Neighbor Chunks Batch Query

**Priority**: 🟢 MEDIUM  
**Effort**: 2h  
**Gain**: Nx faster (N queries → 1 query)  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Services/RAG/ContextBuilder.php` (se esiste)

**Changes**:
```php
// Batch query per neighbor chunks invece di N query separate
$allNeighbors = DocumentChunk::whereIn('document_id', $documentIds)
    ->whereIn('chunk_index', $neighborIndexes)
    ->get()
    ->groupBy('document_id');
```

**Testing**:
- [ ] Test context expansion con 5 chunks
- [ ] Test context expansion con 20 chunks
- [ ] Verify neighbor selection correct

---

### 🔧 Optimization #11: Query Deduplication (Thundering Herd)

**Priority**: 🟢 MEDIUM  
**Effort**: 2h  
**Gain**: Prevent duplicate simultaneous queries  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/app/Services/RAG/KbSearchService.php`

**Changes**:
```php
$lock = Cache::lock("rag:lock:{$tenantId}:" . md5($query), 10);

if ($lock->get()) {
    try {
        // Execute query
    } finally {
        $lock->release();
    }
} else {
    // Wait for other process, then return cached result
}
```

**Testing**:
- [ ] Test concurrent requests same query
- [ ] Verify only 1 execution
- [ ] Test lock timeout handling

---

### 🔧 Optimization #12: Connection Pooling

**Priority**: 🟢 MEDIUM  
**Effort**: 2h  
**Gain**: Reduce connection overhead  
**Status**: ⏳ TODO

**Files to Modify**:
- `backend/config/database.php`

**Changes**:
```php
'options' => [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_EMULATE_PREPARES => false,
],
'pool' => [
    'min' => 2,
    'max' => 20,
],
```

**Testing**:
- [ ] Monitor connection count
- [ ] Test under load (100 concurrent requests)
- [ ] Verify no connection leaks

---

## 📊 Performance Metrics Tracking

### Before Optimizations (Baseline)

| Metric | Value | Date Measured |
|--------|-------|---------------|
| Document Ingestion (100 chunks) | 15s | - |
| RAG Query (cold) | 2.5s | - |
| RAG Query (cached) | N/A (no cache) | - |
| Batch Rescrape (100 docs) | Timeout | - |
| Widget FCP Impact | 500ms | - |
| Cache Hit Rate | 0% | - |
| P95 Latency RAG | ~3s | - |
| DB Queries per RAG request | ~25 | - |

### After Optimizations (Target)

| Metric | Target | Actual | % Improvement |
|--------|--------|--------|---------------|
| Document Ingestion (100 chunks) | <3s | - | - |
| RAG Query (cold) | <1s | - | - |
| RAG Query (cached) | <50ms | - | - |
| Batch Rescrape (100 docs) | <5min | - | - |
| Widget FCP Impact | 0ms | - | - |
| Cache Hit Rate | >80% | - | - |
| P95 Latency RAG | <1.5s | - | - |
| DB Queries per RAG request | <5 | - | - |

---

## 🎯 Quick Wins Priority (Do First!)

```
Priority Order for Maximum ROI:

1. ⚡ Embeddings Batch (2h → 8x gain)     [EASIEST]
2. ⚡ Database Indexes (4h → 5x gain)     [EASY]
3. ⚡ N+1 Elimination (4h → 2.7x gain)    [MEDIUM]
4. 💾 RAG Caching (8h → 57x cached)      [HIGH ROI]
5. 🔄 Parallel Processing (6h → 3.4x)    [MEDIUM]
```

**Recommendation**: Start with #1-3 (10h effort, 15x+ cumulative gain)

---

## 🔍 Testing Checklist

### Pre-Optimization Baseline
- [ ] Measure current ingestion time (sample 10 docs)
- [ ] Measure current RAG latency (sample 50 queries)
- [ ] Count DB queries per RAG request
- [ ] Measure widget load time
- [ ] Document current pain points

### Post-Optimization Verification
- [ ] Re-measure all baseline metrics
- [ ] Verify no functionality regression
- [ ] Check error rates unchanged
- [ ] Monitor resource usage (CPU, memory, DB connections)
- [ ] User acceptance testing

### Production Monitoring
- [ ] Setup Laravel Pulse dashboards
- [ ] Configure Sentry error tracking
- [ ] Add custom metrics (cache hit rate, query latency)
- [ ] Setup alerting (P95 > 2s, cache hit < 50%)

---

## 🚨 Rollback Procedures

### Per-Optimization Rollback

Each optimization has specific rollback in its section above.

### Emergency Full Rollback

```bash
# Revert all migrations
php artisan migrate:rollback --step=1

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Restart queues
php artisan queue:restart

# Revert code changes
git revert <commit-hash>
```

---

## 📝 Notes & Learnings

### Optimization #1: Embeddings Batch
**Date**: 2025-10-14  
**Status**: ✅ COMPLETED  
**Notes**: 
- Aumentato batch size da 128 a 2048 (limite OpenAI)
- Implementato batching dinamico con stima token count
- Aggiunto retry logic con exponential backoff
- Aumentato timeout da 30s a 60s per batch grandi
- Gestione Retry-After header per rate limits
**Issues Encountered**: Nessuno  
**Actual Time**: 30 min  
**Actual Gain**: Da testare in produzione (expected 8x)  

---

### Optimization #2: Database Indexes
**Date**: 2025-10-14  
**Status**: ⚠️ MIGRATION CREATED - NOT TESTED YET  
**Migration**: `2025_10_14_062844_add_rag_performance_indexes.php`  
**Notes**: 
- Creati 6 indici compositi:
  1. `idx_document_chunks_rag_search` (tenant + KB)
  2. `idx_document_chunks_document_tenant` (document + tenant)
  3. `idx_documents_admin_filtering` (tenant + KB + status)
  4. `idx_documents_source_url_hash` (tenant + source_url + hash)
  5. `idx_documents_kb_tenant` (KB + tenant)
  6. `idx_conversation_sessions_lookup` (tenant + chatbot + status)
**Issues Encountered**: Nessuno  
**Actual Time**: 20 min  
**Actual Gain**: Da testare dopo migrazione  
**Next Steps**: 
- [ ] Eseguire EXPLAIN ANALYZE su query lente BEFORE migration
- [ ] Eseguire migration in DEV: `php artisan migrate`
- [ ] Verificare utilizzo indici con EXPLAIN AFTER migration
- [ ] Misurare differenza performance query
- [ ] Se OK, deploy su PROD con downtime minimo  

---

### Optimization #3: N+1 Elimination
**Date**: 2025-10-14  
**Status**: ✅ COMPLETED  
**Notes**: 
- Eliminati 5 punti N+1 in KbSearchService.php
- Batch loading di documents invece di query singole
- Refactored: Generic RAG, Intent-specific, Direct DB fallback, Semantic fallback, Text fallback
- Test script creato: test-n1-fix.php
**Issues Encountered**: Nessuno  
**Actual Time**: 1h 30min  
**Actual Gain**: Da testare con dati reali (expected 2.7x: 700ms → 260ms)  

---

## 🎓 References

- [Performance Analysis Report](.artiforge/performance-bottlenecks-report.md)
- [Technical Debt Report](.artiforge/report.md)
- [Laravel Parallel Docs](https://laravel.com/docs/11.x/helpers#method-parallel)
- [OpenAI Embeddings Limits](https://platform.openai.com/docs/guides/embeddings)
- [PostgreSQL Index Tuning](https://www.postgresql.org/docs/current/indexes.html)

---

**Last Updated**: 14 Ottobre 2025  
**Next Review**: Dopo Week 1 completata  
**Owner**: Development Team

