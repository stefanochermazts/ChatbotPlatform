# 🔍 Report Diagnostico - Doc 4350 Escluso da Citations Finali

## 📋 Sommario

**Data**: 2025-10-16  
**Query**: "telefono comando polizia locale"  
**Tenant**: 5  
**Documento Atteso**: 4350 (Orari Comando Polizia Locale)  
**Telefono Corretto**: 06.95898223

---

## ❌ Problema Confermato

### Risposta RAG Tester (Utente)
```
Il numero di telefono del comando della polizia locale del Comune di San Cesareo 
non è specificatamente riportato nel contesto fornito. 
Ti consiglio di contattare direttamente l'Ufficio di Stato Civile al numero 06.9570120, 
oppure puoi fare riferimento ai numeri utili come 113 per la Polizia di Stato.
```

**Verdetto**: ❌ Risposta ERRATA - Doc 4350 NON è nelle citations finali

---

## ✅ Progressi Realizzati

### 1. Doc 4350 Arriva al Fusion Ranking
**Verificato con `backend/test_fusion_ranking.php`**:

```
Fusion Top-20 Ranking:
  #1: Doc 4350, chunk 2 (score: 0.009901) ✅ PRIMO POSTO!
  #6: Doc 4350, chunk 1 (score: 0.009434) ✅ ANCHE #6
```

**Conclusione**: Il problema NON è nel retrieval (vector search + BM25) né nella fusion RRF.

---

### 2. Fix Implementati (Commit `c02e324`)

#### A. Tenant-Aware RAG Config (Artiforge Steps 1-3)
- ✅ `TenantRagConfigService::getRetrievalConfig()` implementato
- ✅ Rimossi `env()` da `config/rag.php`
- ✅ `KbSearchService` refactored per usare config tenant-aware
- ✅ MMR cache key fixed (include `mmrLambda` e `mmrTake`)

#### B. Tenant 5 Config Ottimizzato
```php
'hybrid' => [
    'vector_top_k' => 100,    // was: 80
    'bm25_top_k' => 30,       // was: 150 (boost semantic weight)
    'mmr_take' => 50,
    'mmr_lambda' => 0.7,      // was: 0.25 (70% relevance, 30% diversity)
    'rrf_k' => 60,
    'neighbor_radius' => 1
]
```

---

## 🐛 Root Cause - IPOTESI

### Ipotesi 1: Document Deduplication (MOLTO PROBABILE)
**File**: `backend/app/Services/RAG/KbSearchService.php:648-649`

```php
foreach ($selIdx as $i) {
    $base = $mmrRanked[$i] ?? null;
    if ($base === null) { continue; }
    $docId = (int) $base['document_id'];
    
    if (isset($seen[$docId])) continue;  // ⚠️ SKIP se doc già visto!
    $seen[$docId] = true;
    
    // Build citation...
}
```

**Problema**:
- MMR seleziona ENTRAMBI i chunks di doc 4350 (chunk 2 e chunk 1)
- Citation building prende **solo il primo chunk di ogni documento**
- Se chunk 2 ha snippet vuoto o troppo corto → doc 4350 escluso!

**Docs Competitors**:
- Doc 4304: **44 chunks** → Se alcuni vengono skippati, altri passano
- Doc 4315: **44 chunks** → Idem
- Doc 4350: **2 chunks** → Una sola chance!

---

### Ipotesi 2: Snippet Vuoto o Troppo Corto
**File**: `backend/app/Services/RAG/KbSearchService.php:651`

```php
$snippet = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'], 50000) ?? '';
```

Se `TextSearchService::getChunkSnippet()` ritorna stringa vuota per doc 4350, chunk 2:
- La citation viene creata MA con snippet vuoto
- Context Builder potrebbe escluderla (riga 75-80 di `ContextBuilder.php`)

---

### Ipotesi 3: Context Builder Budget
**File**: `backend/app/Services/RAG/ContextBuilder.php:86-89`

```php
foreach ($parts as $p) {
    if (mb_strlen($rawContext) + mb_strlen($p) + 5 > $maxChars) {
        break;  // Stop se supera budget (default: 4000 chars)
    }
    $rawContext .= ($rawContext === '' ? '' : "\n\n---\n\n").$p;
}
```

Se docs 4304/4315 con snippet lunghi riempiono il budget prima di doc 4350 → Escluso!

---

## 🧪 Piano di Verifica

### Step 1: Avvia PostgreSQL
```bash
# Su Windows/Laragon
Laragon → Start All
```

### Step 2: Run Full Debug Script
```bash
cd c:\laragon\www\ChatbotPlatform
php backend/test_full_debug.php
```

**Output Atteso**:
- Fusion top-20 con doc 4350 in #1 e #6 ✅
- MMR selected indices (es. [0, 1, 2, 5, 7, ...])
- Final citations con o senza doc 4350

**Se doc 4350 è nelle citations MA non nella risposta LLM**:
→ Il problema è nell'LLM che non estrae l'info correttamente

**Se doc 4350 NON è nelle citations**:
→ Il problema è nella citation building (ipotesi 1 o 2)

---

### Step 3: Inspect Doc 4350 Chunks
```bash
php backend/inspect_doc_4350_chunks.php
```

Verifica:
1. Chunk 2 ha contenuto corretto con telefono?
2. Chunk 1 ha contenuto corretto?
3. I chunks sono indicizzati su Milvus?

---

### Step 4: Test Alternative - RAG Tester con Debug
1. Vai a: `https://chatbotplatform.test:8443/admin/tenants/5/rag-tester`
2. Apri Developer Tools (F12) → Network tab
3. Query: "telefono comando polizia locale"
4. Copia la response JSON completa dal Network tab
5. Cerca `"citations"` nell'output → Verifica se doc 4350 c'è

---

## 🔧 Fix Proposti (Da Implementare)

### Fix A: Aumenta MMR Take
**Razionale**: Con `mmr_take: 50`, MMR può selezionare più chunks. Se doc 4350 è escluso per diversity, aumentare il take può aiutare.

```php
// backend/fix_tenant_5_mmr_take.php
$ragSettings['hybrid']['mmr_take'] = 100;  // was: 50
```

---

### Fix B: Remove Document Deduplication (RISCHIOSO!)
**Razionale**: Permetti più chunks dello stesso documento nelle citations.

```php
// backend/app/Services/RAG/KbSearchService.php:648
// BEFORE:
if (isset($seen[$docId])) continue;
$seen[$docId] = true;

// AFTER:
// Permettiamo max 2 chunks per documento
$seen[$docId] = ($seen[$docId] ?? 0) + 1;
if ($seen[$docId] > 2) continue;
```

**⚠️ ATTENZIONE**: Questo può portare a context pollution (troppi chunks dello stesso doc).

---

### Fix C: Prioritize Small Documents
**Razionale**: Docs con pochi chunks (come 4350) hanno priority nella citation building.

```php
// Ordina mmrRanked per chunk_count ASC (docs piccoli first)
usort($mmrRanked, function($a, $b) {
    $countA = $this->getDocChunkCount($a['document_id']);
    $countB = $this->getDocChunkCount($b['document_id']);
    return $countA <=> $countB;
});
```

---

## 📊 Metriche di Successo

### Prima (STATO ATTUALE)
- ❌ Doc 4350 in fusion #1
- ❌ Doc 4350 escluso da citations
- ❌ Risposta LLM errata (telefono mancante)

### Dopo (TARGET)
- ✅ Doc 4350 in fusion #1
- ✅ Doc 4350 in citations finali
- ✅ Risposta LLM corretta: "06.95898223"

---

## 🚀 Azione Immediata Richiesta

**Per l'Utente**:
1. ✅ Avvia PostgreSQL (Laragon → Start All)
2. ✅ Run script debug: `php backend/test_full_debug.php`
3. ✅ Condividi l'output completo

**Per l'AI Agent**:
1. ⏳ In attesa di output debug
2. ⏳ Analizza dove doc 4350 viene escluso
3. ⏳ Implementa fix appropriato

---

## 📝 Files Coinvolti

### Core Files
- `backend/app/Services/RAG/KbSearchService.php` - Retrieval + Citations
- `backend/app/Services/RAG/ContextBuilder.php` - Context assembly
- `backend/app/Services/RAG/TenantRagConfigService.php` - Config management
- `backend/config/rag.php` - Global defaults

### Debug Scripts
- `backend/test_full_debug.php` - Full trace con debug=true
- `backend/test_fusion_ranking.php` - Fusion ranking verification
- `backend/test_retrieval_config.php` - Config verification
- `backend/check_tenant_5_settings.php` - Tenant config inspection

### Documentation
- `documents/03-RAG-RETRIEVAL-FLOW.md` - RAG retrieval documentation
- `CRITICAL-FIX-CHUNKING-SERVICE.md` - Chunking fixes history
- `SEMANTIC-ONLY-CHUNKING-REPORT.md` - Semantic chunking implementation
- `RETRIEVAL-FIX-COMPLETE-REPORT.md` - Retrieval improvements

---

## 💡 Note Finali

Il problema è **molto vicino alla soluzione**. Doc 4350 arriva correttamente al fusion ranking (#1!) ma viene escluso nell'ultimo miglio (citation building o context assembly).

Con il debug output completo, potremo identificare il punto esatto e implementare il fix definitivo.

**Prossimo commit previsto**: Fix citation building per doc 4350 ✅

---

**Last Updated**: 2025-10-16 21:30 UTC  
**Commit**: c02e324 (Tenant-aware RAG config)  
**Status**: 🟡 In Debugging - Awaiting DB connection

