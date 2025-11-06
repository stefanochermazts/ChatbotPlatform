# Piano: Riattivare Reranker con RAG Config

**Created**: 2025-10-20  
**Priority**: MEDIUM  
**Estimated Time**: 30-45 minuti  
**Status**: Ready for execution

---

## 🎯 Obiettivo

Riattivare il reranker per il tenant 5 (San Cesareo), configurandolo correttamente via `rag.config` in modo che:
1. Non demoti più il documento 4350 (Comando Polizia Locale)
2. Funzioni sia nel RAG Tester che nel Widget
3. Migliori la qualità delle citations mantenendo doc 4350 nelle top positions

---

## 📊 Stato Attuale

### Configurazione Corrente
```sql
-- rag_settings per tenant 5
{
  "reranker": {
    "enabled": false,  ← DISABILITATO
    "driver": "embedding",
    "top_k": 10
  }
}
```

### Problema Precedente
- Il reranker con driver "embedding" demotava doc 4350 perché valutava solo similarità semantica
- Doc 4350 ha contenuto misto (telefoni, orari, label servizi) → score semantico più basso
- Documenti con tabelle più pulite (doc 4304, 4315) venivano promossi

### Recent Fixes (che migliorano situazione)
1. ✅ Semantic-only chunking → chunk più coerenti
2. ✅ Boilerplate removal → contenuto più pulito
3. ✅ Synonym expansion → migliore matching
4. ✅ BM25 OR logic → più risultati rilevanti
5. ⏳ Structured data extraction → associazioni esplicite (IN PROGRESS)

---

## 🔍 Analisi Configurazione Reranker

### Config Globale (`backend/config/rag.php`)

```php
'reranker' => [
    'enabled' => (bool) env('RAG_RERANKER_ENABLED', true),
    'driver' => env('RAG_RERANKER_DRIVER', 'embedding'), // embedding|llm|cohere
    'top_k' => (int) env('RAG_RERANKER_TOP_K', 10),
    'threshold' => (float) env('RAG_RERANKER_THRESHOLD', 0.0),
    
    'embedding' => [
        'model' => env('RAG_RERANKER_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],
    
    'llm' => [
        'model' => env('RAG_RERANKER_LLM_MODEL', 'gpt-4o-mini'),
        'temperature' => 0.0,
    ],
],
```

### Driver Disponibili

#### 1. **embedding** (Semantic Reranker)
- **Pro**: Veloce, economico
- **Con**: Valuta solo similarità semantica (può demotare doc con contenuto strutturato)
- **Uso**: Buono per testi narrativi

#### 2. **llm** (LLM Reranker)
- **Pro**: Comprende contesto e intent, valuta rilevanza non solo similarità
- **Con**: Più lento, più costoso
- **Uso**: Migliore per query complesse con intent specifico

#### 3. **cohere** (Cohere Rerank API)
- **Pro**: Specializzato per reranking, molto accurato
- **Con**: Richiede API key Cohere, costo esterno
- **Uso**: Production con budget

---

## 📋 Piano di Implementazione

### **Step 1**: Verificare Stato Corrente Tenant 5

**Obiettivo**: Confermare che reranker è disabilitato e capire config attuale

**Actions**:
```bash
cd backend
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$tenant = \App\Models\Tenant::find(5);
\$ragSettings = \$tenant->rag_settings ?? [];

echo '🔍 Tenant 5 RAG Settings:' . PHP_EOL;
echo json_encode(\$ragSettings, JSON_PRETTY_PRINT) . PHP_EOL;

// Check TenantRagConfigService
\$service = app(\App\Services\RAG\TenantRagConfigService::class);
\$rerankerConfig = \$service->getRerankerConfig(5);
echo PHP_EOL . '📊 Reranker Config (from TenantRagConfigService):' . PHP_EOL;
echo json_encode(\$rerankerConfig, JSON_PRETTY_PRINT) . PHP_EOL;
"
```

**Expected Output**:
```json
{
  "reranker": {
    "enabled": false,
    "driver": "embedding",
    "top_k": 10
  }
}
```

---

### **Step 2**: Decidere Driver Reranker Ottimale

**Opzioni**:

#### Opzione A: LLM Reranker (RACCOMANDATO per questo caso)
**Rationale**:
- Comprende intent: "telefono comando polizia locale" non è solo semantica
- Valuta rilevanza contestuale: capisce che doc con "Comando Polizia Locale + telefono" è più rilevante di tabella generica
- Può gestire contenuto strutturato meglio di embedding puro

**Config**:
```json
{
  "reranker": {
    "enabled": true,
    "driver": "llm",
    "top_k": 8,
    "threshold": 0.0
  }
}
```

**Prompt LLM Reranker** (da `LlmReranker.php`):
```
Given the query: "{query}"
Rate the relevance of this passage from 0.0 (not relevant) to 1.0 (highly relevant):

{passage}

Respond with ONLY a number between 0.0 and 1.0.
```

#### Opzione B: Embedding Reranker con threshold più basso
**Rationale**:
- Più veloce ed economico
- Con boilerplate removal, doc 4350 dovrebbe avere score migliore

**Config**:
```json
{
  "reranker": {
    "enabled": true,
    "driver": "embedding",
    "top_k": 10,
    "threshold": 0.0
  }
}
```

**⚠️ Rischio**: Potrebbe ancora demotare doc 4350 se contenuto è troppo eterogeneo

#### Opzione C: Disabilitare Reranker (status quo)
- Mantiene situazione attuale
- Doc 4350 resta in top positions dopo RRF

---

### **Step 3**: Implementare Script per Testare Reranker

**File**: `backend/test_reranker_effect.php`

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenantId = 5;
$query = "telefono comando polizia locale";

echo "🧪 Testing Reranker Effect on Query: '$query'\n\n";

// Get services
$kbSearch = app(\App\Services\RAG\KbSearchService::class);
$ragConfig = app(\App\Services\RAG\TenantRagConfigService::class);

// Test 1: WITHOUT reranker (force disable)
echo "📊 TEST 1: WITHOUT Reranker\n";
echo str_repeat('-', 60) . "\n";

$rerankerConfig = $ragConfig->getRerankerConfig($tenantId);
$originalEnabled = $rerankerConfig['enabled'] ?? false;

// Temporarily disable
DB::table('tenants')->where('id', $tenantId)->update([
    'rag_settings->reranker->enabled' => false
]);

$result1 = $kbSearch->retrieve($tenantId, $query, false);
$citations1 = $result1['citations'] ?? [];

echo "Top 5 Citations:\n";
foreach (array_slice($citations1, 0, 5) as $i => $citation) {
    $docId = $citation['document_id'] ?? 'N/A';
    $chunkIdx = $citation['chunk_index'] ?? 'N/A';
    $score = $citation['score'] ?? 0;
    $hasPhone = str_contains($citation['snippet'] ?? '', '06.95898223') ? '✅' : '';
    echo sprintf("  %d. Doc:%s Chunk:%s Score:%.4f %s\n", $i+1, $docId, $chunkIdx, $score, $hasPhone);
}

// Test 2: WITH reranker (force enable with driver)
echo "\n📊 TEST 2: WITH Reranker (driver: {$rerankerConfig['driver']})\n";
echo str_repeat('-', 60) . "\n";

DB::table('tenants')->where('id', $tenantId)->update([
    'rag_settings->reranker->enabled' => true
]);

// Clear cache to reload config
\Illuminate\Support\Facades\Cache::flush();

$result2 = $kbSearch->retrieve($tenantId, $query, false);
$citations2 = $result2['citations'] ?? [];

echo "Top 5 Citations:\n";
foreach (array_slice($citations2, 0, 5) as $i => $citation) {
    $docId = $citation['document_id'] ?? 'N/A';
    $chunkIdx = $citation['chunk_index'] ?? 'N/A';
    $score = $citation['score'] ?? 0;
    $hasPhone = str_contains($citation['snippet'] ?? '', '06.95898223') ? '✅' : '';
    echo sprintf("  %d. Doc:%s Chunk:%s Score:%.4f %s\n", $i+1, $docId, $chunkIdx, $score, $hasPhone);
}

// Restore original state
DB::table('tenants')->where('id', $tenantId)->update([
    'rag_settings->reranker->enabled' => $originalEnabled
]);

// Compare
echo "\n📈 Comparison:\n";
echo str_repeat('-', 60) . "\n";

$doc4350Pos1 = null;
$doc4350Pos2 = null;

foreach ($citations1 as $i => $c) {
    if (($c['document_id'] ?? 0) == 4350 && str_contains($c['snippet'] ?? '', '06.95898223')) {
        $doc4350Pos1 = $i + 1;
        break;
    }
}

foreach ($citations2 as $i => $c) {
    if (($c['document_id'] ?? 0) == 4350 && str_contains($c['snippet'] ?? '', '06.95898223')) {
        $doc4350Pos2 = $i + 1;
        break;
    }
}

echo "Doc 4350 (with correct phone) position:\n";
echo "  Without reranker: " . ($doc4350Pos1 ?? 'NOT IN TOP 10') . "\n";
echo "  With reranker:    " . ($doc4350Pos2 ?? 'NOT IN TOP 10') . "\n";

if ($doc4350Pos2 && $doc4350Pos2 <= 3) {
    echo "\n✅ SUCCESS: Doc 4350 remains in top 3 with reranker!\n";
} elseif ($doc4350Pos2) {
    echo "\n⚠️  WARNING: Doc 4350 demoted to position {$doc4350Pos2}\n";
} else {
    echo "\n❌ FAIL: Doc 4350 not in top 10 with reranker\n";
}
```

---

### **Step 4**: Testare con Driver Embedding

**Actions**:
```bash
# Set driver to embedding
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::table('tenants')->where('id', 5)->update([
    'rag_settings->reranker->enabled' => true,
    'rag_settings->reranker->driver' => 'embedding',
    'rag_settings->reranker->top_k' => 10
]);
echo '✅ Reranker set to: embedding\n';
"

# Clear cache
php artisan config:clear
php artisan cache:clear

# Run test
php test_reranker_effect.php
```

**Evaluate**:
- Se doc 4350 rimane in top 3 → OK, procedi a Step 6
- Se doc 4350 è demotato → Procedi a Step 5 (LLM reranker)

---

### **Step 5**: Testare con Driver LLM (se Step 4 fallisce)

**Actions**:
```bash
# Set driver to llm
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::table('tenants')->where('id', 5)->update([
    'rag_settings->reranker->enabled' => true,
    'rag_settings->reranker->driver' => 'llm',
    'rag_settings->reranker->top_k' => 8
]);
echo '✅ Reranker set to: llm\n';
"

# Clear cache
php artisan config:clear
php artisan cache:clear

# Run test
php test_reranker_effect.php
```

**Evaluate**:
- Se doc 4350 rimane in top 3 → OK, procedi a Step 6
- Se doc 4350 è ancora demotato → Reranker non adatto, torna a disabled

---

### **Step 6**: Test RAG Tester UI

**Actions**:
1. Aprire browser: `https://chatbotplatform.test:8443/admin/rag/run`
2. Query: "telefono comando polizia locale"
3. ✅ Genera risposta con LLM
4. Submit

**Expected**:
- Citations: 3+ documenti
- Doc 4350 in top 3
- Risposta LLM: "06.95898223"

**Se FAIL**:
- Check console logs
- Verify reranker non ha timeout
- Check `storage/logs/laravel.log` per errori

---

### **Step 7**: Test Widget

**Actions**:
1. Aprire widget embed page o `backend/public/test-widget-phone.html`
2. Query: "telefono comando polizia locale"
3. Wait for response

**Expected**:
- Response: "06.95898223"
- Citations: 3+ documenti
- No "Non ho trovato informazioni"

---

### **Step 8**: Verificare Performance & Costi

**LLM Reranker Cost** (se usato):
- Query: "telefono comando polizia locale"
- Top 20 fused results → 20 rerank calls
- Model: gpt-4o-mini
- ~50 tokens per call × 20 = 1000 tokens
- Cost: ~$0.0003 per query

**Embedding Reranker Cost**:
- 20 embedding calls (già cached se stessi chunk)
- Cost: trascurabile

**Performance**:
- Embedding: +50-100ms
- LLM: +500-1000ms (può essere parallelizzato)

---

## 🎯 Decision Matrix

| Scenario | Doc 4350 Position | Driver | Decision |
|----------|-------------------|--------|----------|
| Embedding keeps doc 4350 in top 3 | ≤3 | embedding | ✅ Use embedding |
| Embedding demotes doc 4350 | >3 | embedding | ❌ Try LLM |
| LLM keeps doc 4350 in top 3 | ≤3 | llm | ✅ Use LLM (acceptable cost) |
| LLM demotes doc 4350 | >3 | llm | ❌ Disable reranker |

---

## 📊 Expected Outcomes

### Best Case
- ✅ Reranker enabled with embedding or LLM
- ✅ Doc 4350 in position #1-2
- ✅ RAG Tester returns "06.95898223"
- ✅ Widget returns "06.95898223"
- ✅ Other queries also benefit from better ranking

### Acceptable Case
- ✅ Reranker enabled
- ✅ Doc 4350 in position #3
- ✅ Correct responses in both RAG Tester and Widget
- ⚠️ Slight performance hit if using LLM

### Worst Case
- ❌ Reranker demotes doc 4350 below top 3
- ❌ Wrong responses
- 🔄 Rollback: disable reranker again

---

## 🔄 Rollback Plan

If reranker causes issues:

```bash
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DB::table('tenants')->where('id', 5)->update([
    'rag_settings->reranker->enabled' => false
]);
echo '✅ Reranker disabled\n';
"

php artisan config:clear
php artisan cache:clear
```

---

## 📝 Files to Modify/Create

1. **Create**: `backend/test_reranker_effect.php` (testing script)
2. **Update**: `tenants` table, `rag_settings` column for tenant 5
3. **Verify**: `backend/app/Services/RAG/KbSearchService.php` respects reranker config
4. **Verify**: `backend/app/Services/RAG/TenantRagConfigService.php` getRerankerConfig()

---

## 🚀 Execution Order

1. ✅ Step 1: Verify current state
2. 🔧 Step 2: Decide optimal driver (embedding first, then LLM if needed)
3. 🔧 Step 3: Create test script
4. 🧪 Step 4: Test with embedding driver
5. 🧪 Step 5: (Conditional) Test with LLM driver
6. ✅ Step 6: Test RAG Tester UI
7. ✅ Step 7: Test Widget UI
8. 📊 Step 8: Verify performance

**Total Time**: 30-45 minuti

---

**Ready to proceed? Choose:**

**A)** Start with Step 1 (verify current state) → Then decide driver  
**B)** Skip to Step 4 (test embedding directly)  
**C)** Skip to Step 5 (test LLM directly, more reliable)  
**D)** Keep reranker disabled (safest, current working state)

