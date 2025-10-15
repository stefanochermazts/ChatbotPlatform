# 🔴 CRITICAL FIX: Make ChunkingService Tenant-Configurable

**Status**: 🔴 **CRITICAL** - ChunkingService bypasses tenant config  
**Impact**: All tenants use SAME chunking parameters from .env  
**Priority**: HIGH - Should be fixed before next production deployment  

---

## 🎯 Problem Summary

### What's Wrong

**Current State**:
```php
// ChunkingService.php:27-28
$maxChars = $options['max_chars'] ?? config('rag.chunk_max_chars', 2200);
$overlapChars = $options['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250);
```

**Issues**:
1. ❌ Does NOT inject `TenantRagConfigService`
2. ❌ Does NOT accept `$tenantId` parameter
3. ❌ Reads global config directly - bypasses tenant-specific settings
4. ❌ All tenants forced to use same chunking from `.env`

**Impact**:
- Tenant with PDF manuals **CANNOT** use larger chunks (3000 chars)
- Tenant with short FAQs **CANNOT** use smaller chunks (1000 chars)
- Admin RAG config UI **CANNOT** control chunking per tenant
- Performance tuning **IMPOSSIBLE** without code changes

---

## ✅ Solution: Tenant-Aware Chunking

### Step 1: Update ChunkingService

**File**: `backend/app/Services/Ingestion/ChunkingService.php`

#### 1.1. Inject TenantRagConfigService

**Add to constructor**:
```php
use App\Services\RAG\TenantRagConfigService;

class ChunkingService implements ChunkingServiceInterface
{
    public function __construct(
        private readonly TenantRagConfigService $tenantConfig // ✅ ADD THIS
    ) {}
    
    // ...
}
```

#### 1.2. Update chunk() Method Signature

**Before**:
```php
public function chunk(string $text, array $options = []): array
```

**After**:
```php
public function chunk(string $text, int $tenantId, array $options = []): array
```

#### 1.3. Use Tenant Config

**Before** (Line 27-28):
```php
$maxChars = $options['max_chars'] ?? config('rag.chunk_max_chars', 2200);
$overlapChars = $options['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250);
```

**After**:
```php
// ✅ Read tenant-specific chunking config
$chunkingConfig = $this->tenantConfig->getChunkingConfig($tenantId);

$maxChars = $options['max_chars'] ?? $chunkingConfig['max_chars'];
$overlapChars = $options['overlap_chars'] ?? $chunkingConfig['overlap_chars'];
```

#### 1.4. Update determineOptimalChunkSize()

**Before** (Line 197):
```php
private function determineOptimalChunkSize(int $textLength): int
{
    if ($textLength < 10000) {
        return config('rag.chunk_max_chars', 2200); // ❌ Hardcoded
    }
    
    return min(3000, config('rag.chunk_max_chars', 2200) + 500); // ❌ Hardcoded
}
```

**After**:
```php
private function determineOptimalChunkSize(int $textLength, int $tenantId): int
{
    $chunkingConfig = $this->tenantConfig->getChunkingConfig($tenantId);
    $baseChunkSize = $chunkingConfig['max_chars'];
    
    if ($textLength < 10000) {
        return $baseChunkSize; // ✅ Tenant-specific
    }
    
    return min(3000, $baseChunkSize + 500); // ✅ Tenant-specific
}
```

---

### Step 2: Update All Callers

Need to pass `$tenantId` to `ChunkingService->chunk()` method.

#### 2.1. DocumentIngestionService

**File**: `backend/app/Services/Ingestion/DocumentIngestionService.php`

**Find** (approximate line):
```php
$chunks = $this->chunkingService->chunk($content);
```

**Replace with**:
```php
$chunks = $this->chunkingService->chunk($content, $document->tenant_id);
```

#### 2.2. IngestUploadedDocumentJob

**File**: `backend/app/Jobs/IngestUploadedDocumentJob.php`

**Find** (approximate line):
```php
$chunks = app(ChunkingService::class)->chunk($content);
```

**Replace with**:
```php
$tenantId = $this->document->tenant_id;
$chunks = app(ChunkingService::class)->chunk($content, $tenantId);
```

#### 2.3. WebScraperService

**File**: `backend/app/Services/Scraper/WebScraperService.php`

**Find** (approximate line):
```php
$chunks = $chunkingService->chunk($markdown);
```

**Replace with**:
```php
$tenantId = $config->tenant_id; // ScraperConfig has tenant_id
$chunks = $chunkingService->chunk($markdown, $tenantId);
```

---

### Step 3: Fix config/rag.php Naming

**File**: `backend/config/rag.php`

**Before** (Line 14-17):
```php
'chunk' => [
    'max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
    'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),
],
```

**After** (flatten structure for consistency):
```php
'chunk_max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
'chunk_overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),
```

**Update TenantRagConfigService** (Line 109-110):
```php
// BEFORE
'max_chars' => $config['chunking']['max_chars'] ?? config('rag.chunk.max_chars', 2200),
'overlap_chars' => $config['chunking']['overlap_chars'] ?? config('rag.chunk.overlap_chars', 250),

// AFTER
'max_chars' => $config['chunking']['max_chars'] ?? config('rag.chunk_max_chars', 2200),
'overlap_chars' => $config['chunking']['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250),
```

---

### Step 4: Update Admin UI (Optional but Recommended)

**File**: `backend/resources/views/admin/tenants/rag-config.blade.php`

**Add chunking section**:
```html
<div class="config-section">
    <h3>📐 Chunking Parameters</h3>
    <p class="help-text">Configure how documents are split into chunks for embedding.</p>
    
    <div class="form-group">
        <label for="chunking_max_chars">Max Chunk Size (characters):</label>
        <input type="number" 
               id="chunking_max_chars" 
               name="chunking[max_chars]" 
               value="{{ old('chunking.max_chars', $config['chunking']['max_chars'] ?? 2200) }}" 
               min="500" 
               max="5000" 
               step="100">
        <small class="text-muted">Range: 500-5000 (default: 2200)</small>
    </div>
    
    <div class="form-group">
        <label for="chunking_overlap_chars">Overlap Size (characters):</label>
        <input type="number" 
               id="chunking_overlap_chars" 
               name="chunking[overlap_chars]" 
               value="{{ old('chunking.overlap_chars', $config['chunking']['overlap_chars'] ?? 250) }}" 
               min="0" 
               max="1000" 
               step="50">
        <small class="text-muted">Range: 0-1000 (default: 250)</small>
    </div>
    
    <div class="alert alert-warning">
        ⚠️ <strong>Important</strong>: Changes to chunking parameters require <strong>re-ingestion</strong> of existing documents to take effect.
    </div>
</div>
```

**Update controller validation**:
```php
// TenantRagConfigController->update()
$validated = $request->validate([
    'chunking.max_chars' => 'nullable|integer|min:500|max:5000',
    'chunking.overlap_chars' => 'nullable|integer|min:0|max:1000',
    // ... other fields
]);
```

---

## 📋 Implementation Checklist

- [ ] **Step 1.1**: Inject `TenantRagConfigService` in `ChunkingService`
- [ ] **Step 1.2**: Add `$tenantId` parameter to `chunk()` method
- [ ] **Step 1.3**: Replace `config()` calls with `$this->tenantConfig->getChunkingConfig()`
- [ ] **Step 1.4**: Update `determineOptimalChunkSize()` to accept `$tenantId`
- [ ] **Step 2.1**: Update `DocumentIngestionService` caller
- [ ] **Step 2.2**: Update `IngestUploadedDocumentJob` caller
- [ ] **Step 2.3**: Update `WebScraperService` caller
- [ ] **Step 3**: Fix `config/rag.php` naming (flatten structure)
- [ ] **Step 3**: Update `TenantRagConfigService->getChunkingConfig()` to use new keys
- [ ] **Step 4** (optional): Add chunking config to admin UI
- [ ] **Test 1**: Verify default chunking still works (global config)
- [ ] **Test 2**: Set custom chunking for Tenant 5 via admin UI
- [ ] **Test 3**: Upload document to Tenant 5, verify custom chunking used
- [ ] **Test 4**: Upload document to Tenant 1, verify default chunking used
- [ ] Run linter: `php artisan pint`
- [ ] Commit and push changes

---

## 🧪 Testing Plan

### Test 1: Verify Default Chunking

1. **Clear tenant config**:
   ```sql
   UPDATE tenants SET rag_settings = NULL WHERE id = 5;
   ```

2. **Upload test document** via admin UI

3. **Check log** for chunking params:
   ```bash
   tail -f storage/logs/laravel.log | grep chunking
   ```

4. **Expected**: Uses `.env` defaults (2200, 250)

---

### Test 2: Tenant-Specific Chunking

1. **Set custom chunking** via admin UI:
   ```
   URL: /admin/tenants/5/rag-config
   
   Chunking > Max Chunk Size: 3000
   Chunking > Overlap Size: 300
   
   Save
   ```

2. **Upload test document** to Tenant 5

3. **Check log**:
   ```bash
   tail -f storage/logs/laravel.log | grep "chunking.*3000"
   ```

4. **Expected**: Uses tenant-specific params (3000, 300)

---

### Test 3: Verify Different Tenants Use Different Configs

1. **Tenant 5**: Custom chunking (3000, 300)
2. **Tenant 1**: Default chunking (2200, 250)

3. **Upload to both tenants**, compare logs

4. **Expected**: Different chunking params per tenant

---

## ⏱️ Time Estimate

| Task | Estimate |
|------|----------|
| Step 1: Refactor ChunkingService | 1 hour |
| Step 2: Update callers (3 files) | 1 hour |
| Step 3: Fix config/rag.php naming | 30 minutes |
| Step 4: Add admin UI (optional) | 1 hour |
| Testing (all 3 scenarios) | 1 hour |
| **Total** | **4.5 hours** (3.5 hours without UI) |

---

## 🎉 Expected Benefits

After this fix:

✅ **Per-tenant chunking optimization**
- Tenant with long PDFs can use 3000 char chunks
- Tenant with short FAQs can use 1000 char chunks
- Performance tuning via admin UI (no code changes)

✅ **Consistent with other RAG parameters**
- All RAG config centralized in `TenantRagConfigService`
- No hardcoded values in services

✅ **Admin UI control**
- Chunking configurable via `/admin/tenants/{id}/rag-config`
- Real-time changes (no deployment needed)

✅ **Better embeddings quality**
- Optimal chunk size per content type
- Better retrieval relevance

---

## 📚 Related Files

- **ChunkingService**: `backend/app/Services/Ingestion/ChunkingService.php`
- **TenantRagConfigService**: `backend/app/Services/RAG/TenantRagConfigService.php`
- **Global Config**: `backend/config/rag.php`
- **Admin UI**: `backend/resources/views/admin/tenants/rag-config.blade.php`
- **Callers**:
  - `backend/app/Services/Ingestion/DocumentIngestionService.php`
  - `backend/app/Jobs/IngestUploadedDocumentJob.php`
  - `backend/app/Services/Scraper/WebScraperService.php`

---

## 🚀 When to Implement

**Recommendation**: **This Week**

**Why**:
- Critical architectural gap (hardcoded values)
- Affects all document ingestion
- Prevents tenant-specific optimization
- Easy to fix (4.5 hours)

**Alternative**: Can be done **before next feature** that requires custom chunking.

---

**NEXT ACTION**: Review this plan and decide when to implement! 🎯

