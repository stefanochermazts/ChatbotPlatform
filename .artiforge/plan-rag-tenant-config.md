# Plan: RAG Tenant-Aware Configuration

**Obiettivo**: Garantire che TUTTI i parametri RAG (vector_top_k, bm25_top_k, mmr_take, mmr_lambda, rrf_k, neighbor_radius) vengano letti da TenantRagConfigService e NON da env/hardcoded values.

**Problema Attuale**: `vector_top_k = 20` nel runtime invece di `100` dal config, limitando il retrieval.

---

## Step 1: Extend TenantRagConfigService

**Action**: Creare metodo `getRetrievalConfig(int $tenantId): array` che ritorna tutti i parametri di retrieval.

**Reasoning**: Questo metodo diventa la **single source of truth** per tutti i parametri RAG tenant-aware. Merge tenant JSON overrides con global defaults da `config/rag.php`.

**Implementation**:
- Load global defaults via `config('rag')`
- Retrieve tenant model e decode `rag_config` JSON
- Validate keys, fallback a global se mancanti
- Cast types (int/float) e validate ranges
- Return merged array: `vector_top_k`, `bm25_top_k`, `mmr_take`, `mmr_lambda`, `rrf_k`, `neighbor_radius`
- Add PHPDoc

**Error Handling**:
- `ModelNotFoundException` se tenant non esiste
- Log warning se `rag_config` è null/malformed, fallback a globals
- `try/catch` su JSON decode

**Testing**:
- Unit test: tenant con full JSON → assert valori match override
- Unit test: tenant con partial JSON → assert missing keys da global
- Unit test: malformed JSON → assert fallback + warning logged
- Unit test: non-existent tenant → expect `ModelNotFoundException`

---

## Step 2: Verify config/rag.php Structure

**Action**: Riorganizzare `config/rag.php` per esporre array `'defaults'` con i 6 parametri.

**Reasoning**: Simplifica merge logic in TenantRagConfigService.

**Implementation**:
```php
return [
    'defaults' => [
        'vector_top_k'    => 100,
        'bm25_top_k'      => 50,
        'mmr_take'        => 20,
        'mmr_lambda'      => 0.5,
        'rrf_k'           => 60,
        'neighbor_radius' => 0.8,
    ],
];
```
- Add PHPDoc
- Run `php artisan config:cache`

**Error Handling**:
- Run `php -l config/rag.php` pre-commit

**Testing**:
- Assert `config('rag.defaults.vector_top_k') === 100`
- Run existing test suite

---

## Step 3: Refactor KbSearchService

**Action**: Inject `TenantRagConfigService` e usare `getRetrievalConfig()` invece di hardcoded values.

**Reasoning**: Rimuove hardcoded defaults, garantisce tenant overrides.

**Implementation**:
- Add private `TenantRagConfigService $configService`
- Update constructor per DI
- In `search()` method:
  ```php
  $retrievalConfig = $this->configService->getRetrievalConfig($tenantId);
  $vectorTopK = $retrievalConfig['vector_top_k'];
  $bm25TopK = $retrievalConfig['bm25_top_k'];
  // ...
  ```
- Replace hardcoded numbers con array values
- Pass to MilvusClient, TextSearchService, MMR, RRF

**Error Handling**:
- `RuntimeException` se missing key
- Log effective config at DEBUG level

**Testing**:
- Feature test: 2 tenants (uno con override, uno senza) → assert top_k values
- Unit test: mock TenantRagConfigService → verify values forwarded to MilvusClient
- Regression test: existing tests pass

---

## Step 4: Adjust Downstream Services

**Action**: MilvusClient, TextSearchService, KnowledgeBaseSelector → accept values da KbSearchService, no hardcoded.

**Reasoning**: Hardcoded defaults in stack possono override tenant config.

**Implementation**:
- Search literal numbers (20, 50, etc.)
- Replace con method arguments o setters
- Update Service Container bindings in AppServiceProvider
- Add PHPDoc + type-hints

**Error Handling**:
- Default values deprecated con comments

**Testing**:
- Unit tests: custom value passed → used in query
- Integration test: full pipeline con tenant config

---

## Step 5: Comprehensive Pest Tests

**Action**: Test completi per config flow e no-hardcoded values.

**Reasoning**: Prevent future regressions.

**Implementation**:
- `tests/Unit/Services/RAG/TenantRagConfigServiceTest.php`
- `tests/Feature/RagRetrievalTest.php`: 2 tenants, search API endpoint
- Mock Milvus, OpenAI
- `assertDatabaseHas` per tenant JSON

**Error Handling**:
- Refresh test DB tra runs

**Testing**:
- `./vendor/bin/pest --filter TenantRagConfigServiceTest`
- Coverage ≥ 90%

---

## Step 6: Update Documentation

**Action**: Documentare tenant RAG config in `03-RAG-RETRIEVAL-FLOW.md` e README.

**Reasoning**: Stakeholders need clear guidance.

**Implementation**:
- Section "Tenant RAG Configuration"
- JSON schema example
- List parameters: type, default, range
- Explain merge logic
- Note: `.env` should NOT contain RAG settings

**Testing**:
- Manual review markdown

---

## Step 7: Run Laravel Pint

**Action**: PSR-12 compliance, typed properties, commit formatted code.

**Reasoning**: Code quality + prevent CI failures.

**Implementation**:
- `vendor/bin/pint --dry-run`
- `vendor/bin/pint`
- Verify `composer lint`

**Testing**:
- Full test suite post-formatting

---

## Step 8: Deploy to Staging

**Action**: Deploy, integration tests, manual verify `vector_top_k=100` works.

**Reasoning**: Final verification pre-production.

**Implementation**:
- Push to `feature/rag-tenant-config` branch
- Deploy via CI to staging
- REST client: search for 2 tenants
- Observe DEBUG logs

**Error Handling**:
- Review merge logic if values differ
- Rollback if regressions

**Testing**:
- E2E test: assert response payload hits count

---

## Summary

Questo piano garantisce che:
✅ Tutti i parametri RAG siano tenant-aware
✅ Nessun hardcoded value
✅ Tenant può override ogni parametro
✅ Fallback a config globale se no override
✅ Test completi + documentazione

