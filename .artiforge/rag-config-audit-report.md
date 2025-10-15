# 🔍 RAG Configuration Audit Report

**Generated**: 2025-10-15  
**Analysis Type**: Configuration Parameter Usage Audit  
**Focus**: Verify tenant-level RAG configuration is properly used after Phase 4 refactoring

---

## Executive Summary

**Overall Assessment**: 🟡 **MOSTLY COMPLIANT** with minor issues

The RAG pipeline correctly uses `TenantRagConfigService` for **most** configuration parameters. However, there are **2 critical issues** and **3 recommendations** for improvement.

### Key Findings:

✅ **GOOD**:
- `KbSearchService` correctly uses `TenantRagConfigService` for hybrid search parameters
- `ContextBuilder` correctly uses tenant `custom_context_template` (after Phase 4 refactoring)
- `ChatOrchestrationService` correctly injects `TenantRagConfigService`
- Widget and RAG Tester now use **unified ContextBuilder** (parity achieved)

⚠️ **ISSUES**:
1. 🔴 **CRITICAL**: `ChunkingService` bypasses `TenantRagConfigService` - uses global config only
2. 🔴 **CRITICAL**: Chunking parameters are NOT tenant-configurable (hardcoded from .env)
3. 🟡 **MEDIUM**: `config/rag.php` has inconsistent naming (`chunk_max_chars` vs `chunk.max_chars`)

---

## Analysis Results

### 1. ✅ TenantRagConfigService - COMPLIANT

**File**: `backend/app/Services/RAG/TenantRagConfigService.php`

**Status**: ✅ **EXCELLENT** - Well-designed service with proper caching and merge logic

**Features**:
- ✅ 3-level configuration hierarchy: Global → Profile → Tenant-specific
- ✅ Cached configuration with 5-minute TTL
- ✅ Supports per-tenant overrides via `rag_settings` JSON column
- ✅ Provides section-specific getters (`getHybridConfig`, `getRerankerConfig`, etc.)
- ✅ Includes validation logic

**Available Configuration Sections**:
```php
- getHybridConfig()          // ✅ Hybrid search params
- getRerankerConfig()        // ✅ Reranker driver/weights
- getAdvancedConfig()        // ✅ HyDE, LLM reranker, etc.
- getAnswerConfig()          // ✅ Min confidence, fallback message
- getIntentsConfig()         // ✅ Intent detection thresholds
- getKbSelectionConfig()     // ✅ KB auto-selection logic
- getWidgetConfig()          // ✅ Model, temperature, max_tokens
- getChunkingConfig()        // ⚠️ EXISTS but NOT USED by ChunkingService!
```

**getChunkingConfig() Implementation** (Lines 103-112):
```php
public function getChunkingConfig(int $tenantId): array
{
    $config = $this->getConfig($tenantId);
    
    // Fallback ai parametri globali se non configurati per tenant
    return [
        'max_chars' => $config['chunking']['max_chars'] ?? config('rag.chunk.max_chars', 2200),
        'overlap_chars' => $config['chunking']['overlap_chars'] ?? config('rag.chunk.overlap_chars', 250),
    ];
}
```

**Assessment**: ✅ Method exists and is ready to use, but **no service is calling it**!

---

### 2. 🔴 CRITICAL: ChunkingService - NON-COMPLIANT

**File**: `backend/app/Services/Ingestion/ChunkingService.php`

**Status**: ❌ **BYPASSES TENANT CONFIG** - Uses global config directly

**Problem** (Line 27-28):
```php
public function chunk(string $text, array $options = []): array
{
    $maxChars = $options['max_chars'] ?? config('rag.chunk_max_chars', 2200);
    $overlapChars = $options['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250);
    // ...
}
```

**Issues**:
1. ❌ **Does NOT inject** `TenantRagConfigService`
2. ❌ **Does NOT accept** `$tenantId` parameter
3. ❌ **Reads config directly** from `config('rag.chunk_max_chars')` - bypasses tenant config
4. ❌ **No tenant-level customization** possible for chunking

**Impact**:
- All tenants use **SAME chunking parameters** from `.env`
- Cannot tune chunking for specific tenant needs (e.g., longer documents, different languages)
- Admin RAG config UI (`/admin/tenants/{id}/rag-config`) cannot control chunking

**Also Affected** (Line 197, 201):
```php
private function determineOptimalChunkSize(int $textLength): int
{
    if ($textLength < 10000) {
        return config('rag.chunk_max_chars', 2200); // ❌ Hardcoded global config
    }
    
    return min(3000, config('rag.chunk_max_chars', 2200) + 500); // ❌ Hardcoded
}
```

**Recommendation**: 
```php
// REFACTOR NEEDED
public function __construct(
    private readonly TenantRagConfigService $tenantConfig // ✅ Inject service
) {}

public function chunk(string $text, int $tenantId, array $options = []): array
{
    $chunkingConfig = $this->tenantConfig->getChunkingConfig($tenantId); // ✅ Use tenant config
    $maxChars = $options['max_chars'] ?? $chunkingConfig['max_chars'];
    $overlapChars = $options['overlap_chars'] ?? $chunkingConfig['overlap_chars'];
    // ...
}
```

---

### 3. ✅ KbSearchService - COMPLIANT

**File**: `backend/app/Services/RAG/KbSearchService.php`

**Status**: ✅ **EXCELLENT** - Proper tenant config usage

**Evidence** (Lines 16, 211-217):
```php
public function __construct(
    // ...
    private readonly TenantRagConfigService $tenantConfig, // ✅ Injected!
    // ...
) {}

public function retrieve(int $tenantId, string $query, bool $debug = false): array
{
    // ...
    $cfg = $this->tenantConfig->getHybridConfig($tenantId); // ✅ Uses tenant config!
    $vecTopK   = (int) ($cfg['vector_top_k'] ?? 30);
    $bmTopK    = (int) ($cfg['bm25_top_k']   ?? 50);
    $rrfK      = (int) ($cfg['rrf_k']        ?? 60);
    $mmrLambda = (float) ($cfg['mmr_lambda'] ?? 0.3);
    $mmrTake   = (int) ($cfg['mmr_take']     ?? 8);
    $neighbor  = (int) ($cfg['neighbor_radius'] ?? 1); // ✅ Tenant-configurable!
    // ...
}
```

**Tenant-Configurable Parameters**:
- ✅ `vector_top_k` - Number of vector search results
- ✅ `bm25_top_k` - Number of BM25 search results
- ✅ `rrf_k` - Reciprocal Rank Fusion parameter
- ✅ `mmr_lambda` - Maximum Marginal Relevance lambda (diversity)
- ✅ `mmr_take` - Number of results after MMR reranking
- ✅ `neighbor_radius` - Chunk neighbor inclusion radius

**Logging** (Lines 219-224):
```php
Log::info('⚙️ [RETRIEVE] Configurazione hybrid search', [
    'vector_top_k' => $vecTopK,
    'bm25_top_k' => $bmTopK,
    'rrf_k' => $rrfK,
    'mmr_lambda' => $mmrLambda,
    // ... Logs actual tenant-specific values!
]);
```

**Assessment**: ✅ **PERFECT** - Demonstrates best practice for tenant config usage.

---

### 4. ✅ ContextBuilder - COMPLIANT (After Phase 4 Refactoring)

**File**: `backend/app/Services/RAG/ContextBuilder.php`

**Status**: ✅ **FIXED** - Now properly uses tenant configuration

**Evidence** (Lines 89-94):
```php
// ✅ ADD: Apply tenant custom_context_template (matches RagTestController logic)
$tenant = Tenant::find($tenantId);
if ($tenant && ! empty($tenant->custom_context_template)) {
    $context = "\n\n".str_replace('{context}', $rawContext, $tenant->custom_context_template);
} else {
    $context = "\n\nContesto (estratti rilevanti):\n".$rawContext;
}
```

**Tenant-Configurable Parameters**:
- ✅ `custom_context_template` - Per-tenant context formatting template
- ✅ `compression_enabled` - LLM compression (passed via `$options`)
- ✅ `max_chars` - Context budget (passed via `$options`)

**Usage in ChatOrchestrationService** (Line 177-179):
```php
$contextResult = $this->contextBuilder->build($filteredCitations, $tenantId, [
    'compression_enabled' => false, // ✅ Configurable via options
]);
```

**Assessment**: ✅ **EXCELLENT** - Phase 4 refactoring successfully unified logic.

---

### 5. ✅ ChatOrchestrationService - COMPLIANT

**File**: `backend/app/Services/Chat/ChatOrchestrationService.php`

**Status**: ✅ **COMPLIANT** - Proper service injection

**Evidence** (Lines 43-47):
```php
public function __construct(
    // ...
    private readonly TenantRagConfigService $tenantConfig, // ✅ Injected!
    // ...
) {}
```

**Usage** (Line 410):
```php
private function buildLLMPayload(/* ... */, int $tenantId): array
{
    $widgetConfig = $this->tenantConfig->getWidgetConfig($tenantId); // ✅ Uses tenant config!
    
    $payload = [
        'model' => $request['model'] ?? ($widgetConfig['model'] ?? config('openai.chat_model', 'gpt-4o-mini')),
        'temperature' => (float) ($request['temperature'] ?? ($widgetConfig['temperature'] ?? 0.2)),
        'max_tokens' => (int) ($request['max_tokens'] ?? ($widgetConfig['max_tokens'] ?? 1000)),
    ];
    // ...
}
```

**Tenant-Configurable Parameters**:
- ✅ `model` - OpenAI model (gpt-4o-mini, gpt-4o, etc.)
- ✅ `temperature` - LLM temperature (0.0-2.0)
- ✅ `max_tokens` - Max output tokens

**Assessment**: ✅ **COMPLIANT** - Correctly reads widget config per tenant.

---

### 6. ✅ RAG Tester - COMPLIANT

**File**: `backend/app/Http/Controllers/Admin/RagTestController.php`

**Status**: ✅ **COMPLIANT** - Uses unified ContextBuilder (after Phase 4 refactoring)

**Evidence** (Lines 204-209):
```php
if ((bool) ($data['with_answer'] ?? false)) {
    // ✅ UNIFIED: Use ContextBuilder service (same as Widget!)
    $contextBuilder = app(\App\Services\RAG\ContextBuilder::class);
    $contextResult = $contextBuilder->build($citations, $tenantId, [
        'compression_enabled' => false, // ✅ Same config as Widget!
    ]);
    $contextText = $contextResult['context'] ?? '';
    // ...
}
```

**Assessment**: ✅ **PARITY ACHIEVED** - RAG Tester and Widget now use **IDENTICAL** logic.

---

## 🔴 Critical Issues

### Issue #1: ChunkingService Bypasses Tenant Config

**Severity**: 🔴 **CRITICAL**  
**Impact**: ❌ Chunking parameters cannot be customized per tenant

**Problem**:
- `ChunkingService` reads `config('rag.chunk_max_chars')` directly
- All tenants use **SAME chunking** from `.env` (RAG_CHUNK_MAX_CHARS)
- Admin cannot tune chunking via RAG config UI

**Evidence**:
```php
// backend/app/Services/Ingestion/ChunkingService.php:27-28
$maxChars = $options['max_chars'] ?? config('rag.chunk_max_chars', 2200);
$overlapChars = $options['overlap_chars'] ?? config('rag.chunk_overlap_chars', 250);
```

**Use Cases That Require Tenant-Specific Chunking**:
1. **Long Documents**: Tenant with PDF manuals needs larger chunks (3000 chars)
2. **Short FAQs**: Tenant with Q&A needs smaller chunks (1000 chars)
3. **Different Languages**: Tenant with Chinese text needs different chunk boundaries
4. **Performance**: Tenant with high-traffic needs smaller chunks for faster embedding

**Fix Required**:
```php
// STEP 1: Inject TenantRagConfigService
public function __construct(
    private readonly TenantRagConfigService $tenantConfig
) {}

// STEP 2: Accept tenantId parameter
public function chunk(string $text, int $tenantId, array $options = []): array
{
    $chunkingConfig = $this->tenantConfig->getChunkingConfig($tenantId);
    $maxChars = $options['max_chars'] ?? $chunkingConfig['max_chars'];
    $overlapChars = $options['overlap_chars'] ?? $chunkingConfig['overlap_chars'];
    // ...
}

// STEP 3: Update all callers to pass tenantId
// - DocumentIngestionService
// - IngestUploadedDocumentJob
// - WebScraperService
```

**Estimate**: 2-3 hours (refactoring + testing)

---

### Issue #2: config/rag.php Naming Inconsistency

**Severity**: 🟡 **MEDIUM**  
**Impact**: ⚠️ Confusion between `chunk_max_chars` and `chunk.max_chars`

**Problem**:
```php
// config/rag.php:14-17
'chunk' => [
    'max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
    'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),
],
```

But `ChunkingService` uses:
```php
config('rag.chunk_max_chars', 2200) // ❌ Wrong! Should be 'rag.chunk.max_chars'
```

**Why It Works (Accidentally)**:
- Laravel's `config()` helper returns `null` for `rag.chunk_max_chars`
- Falls back to default `2200` in the code
- But this means `.env` changes are **IGNORED**!

**Fix Required**:
```php
// OPTION 1: Fix config/rag.php structure (RECOMMENDED)
// Change from nested 'chunk' => [...] to flat keys
'chunk_max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
'chunk_overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),

// OPTION 2: Fix ChunkingService to use correct path
config('rag.chunk.max_chars', 2200)
config('rag.chunk.overlap_chars', 250)
```

**Recommendation**: Use **OPTION 1** (flat keys) for consistency with other config keys.

**Estimate**: 30 minutes (config file update + testing)

---

## ✅ Recommendations

### High Priority (This Week)

#### 1. 🔴 Make Chunking Tenant-Configurable

**Action**: Refactor `ChunkingService` to use `TenantRagConfigService`

**Steps**:
1. Inject `TenantRagConfigService` in `ChunkingService` constructor
2. Add `$tenantId` parameter to `chunk()` method
3. Call `$this->tenantConfig->getChunkingConfig($tenantId)` instead of global config
4. Update all callers:
   - `DocumentIngestionService->ingest()`
   - `IngestUploadedDocumentJob->handle()`
   - `WebScraperService->processDocument()`
5. Add chunking config section to admin UI (`/admin/tenants/{id}/rag-config`)

**Benefits**:
- ✅ Per-tenant chunking tuning (essential for different content types)
- ✅ Consistent with other RAG parameters
- ✅ Admin can optimize chunking without code changes

**Estimate**: 3 hours

---

#### 2. 🟡 Fix config/rag.php Naming Inconsistency

**Action**: Flatten `chunk` config structure

**Change**:
```php
// BEFORE
'chunk' => [
    'max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
    'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),
],

// AFTER
'chunk_max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200),
'chunk_overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250),
```

**Update**:
- `TenantRagConfigService->getChunkingConfig()` to use new keys
- Any other code referencing `config('rag.chunk.max_chars')`

**Estimate**: 30 minutes

---

### Medium Priority (Next 2 Weeks)

#### 3. 🟢 Add Chunking Config to Admin UI

**Action**: Extend `/admin/tenants/{id}/rag-config` to include chunking section

**UI Addition**:
```html
<div class="config-section">
    <h3>Chunking Parameters</h3>
    <label>
        Max Chunk Size (characters):
        <input type="number" name="chunking[max_chars]" value="{{ $config['chunking']['max_chars'] ?? 2200 }}" />
    </label>
    <label>
        Overlap Size (characters):
        <input type="number" name="chunking[overlap_chars]" value="{{ $config['chunking']['overlap_chars'] ?? 250 }}" />
    </label>
    <p class="help-text">
        ⚠️ Changes require re-ingestion of documents to take effect.
    </p>
</div>
```

**Controller Update**:
```php
// TenantRagConfigController->update()
$validated = $request->validate([
    'chunking.max_chars' => 'nullable|integer|min:500|max:5000',
    'chunking.overlap_chars' => 'nullable|integer|min:0|max:1000',
    // ... other fields
]);
```

**Estimate**: 2 hours

---

#### 4. 🟢 Add Tenant Config Usage Logging

**Action**: Log which config source is used for each request

**Implementation**:
```php
// TenantRagConfigService->getConfig()
Log::debug('rag_config.loaded', [
    'tenant_id' => $tenantId,
    'profile' => $tenant->rag_profile ?? 'default',
    'has_custom_settings' => !empty($tenant->rag_settings),
    'config_keys' => array_keys($config),
]);
```

**Benefits**:
- ✅ Verify tenant overrides are applied
- ✅ Debug config issues faster
- ✅ Track config usage patterns

**Estimate**: 1 hour

---

### Low Priority (Future)

#### 5. 🔵 Add Config Validation to Admin UI

**Action**: Client-side validation for RAG config form

**Validation Rules**:
- `vector_top_k`: 1-200
- `mmr_lambda`: 0.0-1.0
- `min_confidence`: 0.0-1.0
- `neighbor_radius`: 0-5
- `chunk_max_chars`: 500-5000
- `chunk_overlap_chars`: 0-1000

**Estimate**: 2 hours

---

## 📊 Configuration Parameter Coverage

### ✅ Fully Tenant-Configurable (via TenantRagConfigService)

| Parameter | Service | Status |
|-----------|---------|--------|
| `vector_top_k` | KbSearchService | ✅ COMPLIANT |
| `bm25_top_k` | KbSearchService | ✅ COMPLIANT |
| `rrf_k` | KbSearchService | ✅ COMPLIANT |
| `mmr_lambda` | KbSearchService | ✅ COMPLIANT |
| `mmr_take` | KbSearchService | ✅ COMPLIANT |
| `neighbor_radius` | KbSearchService | ✅ COMPLIANT |
| `reranker.driver` | KbSearchService | ✅ COMPLIANT |
| `reranker.enabled` | KbSearchService | ✅ COMPLIANT |
| `hyde.enabled` | HyDEExpander | ✅ COMPLIANT (assumed) |
| `custom_system_prompt` | ChatOrchestrationService | ✅ COMPLIANT |
| `custom_context_template` | ContextBuilder | ✅ COMPLIANT (Phase 4 fix) |
| `widget.model` | ChatOrchestrationService | ✅ COMPLIANT |
| `widget.temperature` | ChatOrchestrationService | ✅ COMPLIANT |
| `widget.max_tokens` | ChatOrchestrationService | ✅ COMPLIANT |

---

### ❌ NOT Tenant-Configurable (Hardcoded)

| Parameter | Service | Issue |
|-----------|---------|-------|
| `chunk_max_chars` | ChunkingService | ❌ **CRITICAL** - Uses global config only |
| `chunk_overlap_chars` | ChunkingService | ❌ **CRITICAL** - Uses global config only |
| `context.compression_enabled` | ContextBuilder | ⚠️ Passed via `$options` but not from config |

---

### ⚠️ Partially Configurable (via options array)

| Parameter | Service | Notes |
|-----------|---------|-------|
| `compression_enabled` | ContextBuilder | Passed via `$options` in ChatOrchestrationService (hardcoded `false`) |
| `max_chars` | ContextBuilder | Passed via `$options` (defaults to config if not provided) |

---

## 🎯 Widget vs RAG Tester Parity

### Context Building (Phase 4 Fix)

| Component | Context Builder | Config Source | Status |
|-----------|----------------|---------------|--------|
| **Widget** | ✅ `ContextBuilder->build($citations, $tenantId)` | ✅ `TenantRagConfigService` | ✅ COMPLIANT |
| **RAG Tester** | ✅ `ContextBuilder->build($citations, $tenantId)` | ✅ `TenantRagConfigService` | ✅ COMPLIANT |
| **Parity** | ✅ **IDENTICAL** | ✅ **IDENTICAL** | ✅ **ACHIEVED** |

**Evidence**:
- Both use `backend/app/Services/RAG/ContextBuilder.php` (unified implementation)
- Both pass `$tenantId` to respect tenant-specific templates
- Both use same `compression_enabled: false` option

---

### System Prompt (Hotfix #4)

| Component | Prompt Source | Status |
|-----------|--------------|--------|
| **Widget** | ✅ `$tenant->custom_system_prompt` OR strict default | ✅ ALIGNED |
| **RAG Tester** | ✅ `$tenant->custom_system_prompt` OR strict default | ✅ ALIGNED |
| **Parity** | ✅ **IDENTICAL** | ✅ **ACHIEVED** |

---

### Hybrid Search Config

| Component | Config Source | Status |
|-----------|--------------|--------|
| **Widget** | ✅ `TenantRagConfigService->getHybridConfig($tenantId)` | ✅ COMPLIANT |
| **RAG Tester** | ✅ `TenantRagConfigService->getHybridConfig($tenantId)` | ✅ COMPLIANT |
| **Parity** | ✅ **IDENTICAL** | ✅ **ACHIEVED** |

---

## 📝 Conclusion

### Overall Assessment: 🟡 **MOSTLY COMPLIANT**

**Strengths**:
1. ✅ **TenantRagConfigService** is well-designed and properly cached
2. ✅ **KbSearchService** exemplifies best practice for tenant config usage
3. ✅ **Phase 4 refactoring** successfully unified Widget and RAG Tester context building
4. ✅ **Widget-RAG Tester parity** achieved for all major components
5. ✅ Most RAG parameters are tenant-configurable via admin UI

**Critical Gaps**:
1. ❌ **ChunkingService** does NOT use `TenantRagConfigService` (highest priority fix)
2. ⚠️ **config/rag.php** has naming inconsistency (`chunk_max_chars` vs `chunk.max_chars`)

**Impact of Gaps**:
- Chunking parameters cannot be tuned per tenant
- Different content types (manuals vs FAQs) forced to use same chunking
- Performance optimization limited by global chunking

---

## 🚀 Next Steps

### Immediate (Today)
1. Review this audit report
2. Prioritize Issue #1 (ChunkingService refactoring)
3. Create GitHub issue or Jira ticket for tracking

### This Week
1. Implement Recommendation #1 (Make Chunking Tenant-Configurable) - **3 hours**
2. Implement Recommendation #2 (Fix config/rag.php naming) - **30 minutes**
3. Test chunking with multiple tenants
4. Verify RAG config UI reflects changes

### Next 2 Weeks
1. Add chunking config section to admin UI
2. Add config usage logging for debugging
3. Document tenant config capabilities in `docs/rag.md`

---

## 📚 Related Documentation

- Tenant RAG Config Admin UI: `https://chatbotplatform.test:8443/admin/tenants/5/rag-config`
- TenantRagConfigService: `backend/app/Services/RAG/TenantRagConfigService.php`
- Global RAG Config: `backend/config/rag.php`
- Phase 4 Refactoring Report: `.artiforge/report.md`
- Unified Context Builder Guide: `FIX-IMPLEMENTED-TESTING-GUIDE.md`

---

**Report Completed**: 2025-10-15  
**Analysis Duration**: 20 minutes  
**Files Reviewed**: 7 critical files  
**Issues Found**: 2 critical, 3 recommendations  

**End of Report**

