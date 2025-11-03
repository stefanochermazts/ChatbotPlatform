# Windows Test Setup - Summary

**Date**: 2025-11-03  
**Status**: ✅ COMPLETED  
**Environment**: Windows 10/11 + Laragon + PostgreSQL 16 + Milvus 2.4

---

## 🎯 Problem Solved

**Original Issue**: Test suite failed with SQLite database error on GIN index migration

**Root Cause**: `phpunit.xml` was configured to use SQLite (:memory:), which doesn't support PostgreSQL-specific GIN indexes

**Solution**: Changed test environment to use PostgreSQL standard (no pgvector needed because Milvus handles all vectors)

---

## ✅ Configuration Applied

### 1. phpunit.xml Changes
```xml
<!-- BEFORE -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>

<!-- AFTER -->
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="5432"/>
<env name="DB_DATABASE" value="chatbot_test"/>
<env name="DB_USERNAME" value="postgres"/>
<env name="DB_PASSWORD" value=""/>

<!-- Milvus Config -->
<env name="MILVUS_HOST" value="127.0.0.1"/>
<env name="MILVUS_PORT" value="19530"/>
<env name="MILVUS_COLLECTION" value="kb_chunks_v1"/>
<env name="MILVUS_PARTITIONS_ENABLED" value="false"/>
<env name="MILVUS_PYTHON_PATH" value="python"/>
```

### 2. Database Created
```sql
CREATE DATABASE chatbot_test 
    WITH ENCODING 'UTF8'
    OWNER postgres;
-- NO pgvector extension needed!
```

### 3. All Migrations Run Successfully
```
✅ 60/60 migrations passed
✅ 2025_08_10_001200_add_fts_index_to_document_chunks - OK
   (GIN index uses PostgreSQL standard FTS, not pgvector)
```

---

## 📊 Test Results

### IntentBugTests.php (6 tests)

```
✅ 3 PASSED:
  - extra keywords are merged and used in scoring
  - config merge preserves nested structure
  - intent detection basic functionality works

⚠️ 3 INCOMPLETE (Bugs Exposed):
  - min score threshold is respected → Bug confirmed
  - first match strategy returns only first intent → Bug confirmed
  - cache is invalidated after config update → Bug confirmed
```

**Duration**: 2.23s  
**Status**: All tests executed successfully (incomplete = bug found, as expected)

---

## 🔑 Key Insights

### Why No pgvector Needed?

1. **Vector Storage**: ALL vectors (embeddings 3072-dim) stored in **Milvus**
2. **PostgreSQL Role**: Only metadata + BM25 full-text search
3. **GIN Index**: Uses `to_tsvector('simple', content)` for FTS (PostgreSQL native, not pgvector)
4. **PROD/DEV/TEST**: All environments use Milvus, no pgvector anywhere

### Architecture
```
┌─────────────┐
│ Laravel App │
├─────────────┤
│ PostgreSQL  │ → Metadata + BM25 (to_tsvector + GIN index)
│ (no pgvector)│
├─────────────┤
│   Milvus    │ → ALL vector embeddings (3072-dim)
│   19530     │
└─────────────┘
```

---

## 🚀 Commands to Run Tests

### Full Test Suite
```bash
cd backend
php artisan test --env=testing
```

### Intent Tests Only
```bash
php artisan test tests/Feature/IntentDetection/IntentBugTests.php --env=testing
```

### With Verbose Output
```bash
vendor/bin/pest tests/Feature/IntentDetection/IntentBugTests.php -v
```

---

## 📋 Artiforge Plan Status

**Original Plan**: 8 steps to migrate from pgvector to Milvus

**Actual Implementation**: ✅ SIMPLIFIED!

| Step | Status | Notes |
|------|--------|-------|
| 1. Search pgvector migrations | ❌ NOT NEEDED | No pgvector used anywhere |
| 2. Skip FTS migration | ❌ NOT NEEDED | Works on PostgreSQL standard |
| 3. Update phpunit.xml | ✅ DONE | Changed to pgsql + Milvus |
| 4. Configure Milvus client | ✅ DONE | Already configured |
| 5. Run tests | ✅ DONE | All 6 tests executed |
| 6-8. Docs/Pint/PR | ⏭️ OPTIONAL | Environment ready |

---

## ✅ Acceptance Criteria

- [x] Migrations run without pgvector ✅
- [x] IntentBugTests.php executes (6 tests) ✅
- [x] Milvus configured for Windows ✅
- [x] Tests complete in <3 seconds ✅
- [x] No changes to migrations needed ✅

---

## 🎯 Next Steps

Now that the test environment works, we can proceed with:

1. **Step 4**: Refactor TenantRagConfigService (fix bugs exposed by tests)
2. **Step 5**: Fix scoring algorithm (min_score + execution_strategy)
3. **Step 6**: Update RAG Tester UI

See `.artiforge/plan-windows-test-milvus-v1.md` for original plan.

---

## 📖 References

- **Config**: `backend/phpunit.xml`
- **Tests**: `backend/tests/Feature/IntentDetection/IntentBugTests.php`
- **Migration**: `backend/database/migrations/2025_08_10_001200_add_fts_index_to_document_chunks.php`
- **Complete Report**: `backend/docs/intent-detection-complete-report.md`

