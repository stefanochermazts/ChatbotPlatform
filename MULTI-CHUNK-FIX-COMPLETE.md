# ✅ Multi-Chunk per Documento Fix Completato

**Date**: 2025-10-20  
**Status**: ✅ FIXED  
**Task**: Permettere multiple chunks dello stesso documento nelle citations finali

---

## 🎯 Problema Identificato

### Root Cause

**File**: `backend/app/Services/RAG/KbSearchService.php` (lines 675-683)

Il sistema faceva **document-level deduplication** quando costruiva le citations finali:

```php
// ❌ OLD CODE (document-level dedup)
$seen = [];
foreach ($selIdx as $i) {
    $base = $mmrRanked[$i] ?? null;
    $docId = (int) $base['document_id'];
    if (isset($seen[$docId])) continue;  // ❌ SKIP if same document!
    $seen[$docId] = true;
```

**Conseguenza**: Solo **1 chunk per documento** veniva incluso nelle citations finali, anche se il reranker aveva trovato più chunk rilevanti dello stesso documento.

### Caso Specifico: Query "orario comando polizia locale"

Il reranker trovava correttamente:
1. Doc 4351, chunk 2 (score 0.593) - POLIZIA STRADALE
2. Doc 4351, chunk 1 (score 0.489) - **ORARI COMANDO POLIZIA LOCALE** ✅

Ma solo chunk 2 veniva incluso nelle citations, escludendo chunk 1 con gli orari richiesti.

---

## 🔧 Soluzione Implementata

### Modifica a KbSearchService.php

**File**: `backend/app/Services/RAG/KbSearchService.php` (lines 675-686)

```php
// ✅ NEW CODE (chunk-level dedup)
$seen = [];
foreach ($selIdx as $i) {
    $base = $mmrRanked[$i] ?? null;
    if ($base === null) { continue; }
    $docId = (int) $base['document_id'];
    $chunkId = (int) $base['chunk_index'];
    // 🔧 MODIFIED: Allow multiple chunks from same document (use chunk-level dedup)
    $chunkKey = "{$docId}:{$chunkId}";
    if (isset($seen[$chunkKey])) continue;
    $seen[$chunkKey] = true;
```

**Cambiamento**: Da document-level (`$seen[$docId]`) a chunk-level (`$seen["{$docId}:{$chunkId}"]`)

**Beneficio**: Più chunk dello stesso documento possono essere inclusi se rilevanti per la query.

---

## ✅ Test e Verifica

### Test 1: Retrieval con Query Problematica

```bash
php backend/check_4351_chunks.php
```

**Result**:
```
✅ Found 3 chunks from doc 4351:

  Position #1 - Chunk 2 - Score: 0.5931
    Has 'polizia locale': ✅

  Position #6 - Chunk 0 - Score: 0.3229
    Has 'polizia locale': ❌

  Position #8 - Chunk 1 - Score: 0.4895
    Has 'orari apertura al pubblico comando': ✅
    Has 'polizia locale': ✅
```

✅ **SUCCESS**: Doc 4351 ha **3 chunks** nelle citations finali (prima aveva solo 1)

### Test 2: Verifica Contenuto Chunk Corretto

**Text di chunk 4351.1** (position #8):
```
06.95898207- 06.95898243 - 06.95898249.

| Giorno | Orario |
| --- | --- |
| Martedì |   8:30 -12:00 |
| Giovedì | 15:00 -17:00 |
| Venerdì |   8:30 -12:00 |
```

✅ **SUCCESS**: Contiene esattamente gli orari del COMANDO POLIZIA LOCALE richiesti

---

## 📊 Performance Impact

### Before Fix
- 1 chunk per documento max
- Query "orario comando" → chunk sbagliato (POLIZIA STRADALE invece di COMANDO)
- LLM non poteva rispondere correttamente perché mancavano gli orari

### After Fix
- N chunks per documento (dedup solo a chunk level)
- Query "orario comando" → 3 chunks di doc 4351, incluso quello con orari ✅
- Context più ricco e completo

### Citation Count Impact
- **No significant increase**: MMR già limita a ~8-15 citations totali
- **Better quality**: Citations più rilevanti vengono incluse invece di essere filtrate per document-level dedup

---

## 🎯 Query Affected (Examples)

Questo fix migliora tutte le query dove:
1. Più chunk dello stesso documento sono rilevanti
2. Informazioni complementari sono in chunk adiacenti
3. Query richiedono dettagli specifici (orari, telefoni, indirizzi) distribuiti in chunk separati

**Examples**:
- "orario comando polizia locale" ✅ fixed
- "telefono e orario biblioteca" (might benefit)
- "contatti ufficio tecnico orari" (might benefit)

---

## 📂 File Modificati

### Produzione
```
backend/
├── app/Services/RAG/KbSearchService.php (lines 675-686) ✅ MODIFIED
```

### Script di Test (temporanei, da cancellare)
```
backend/
├── test_multi_chunk_fix.php
├── debug_citation_keys.php
├── check_4351_chunks.php
├── test_final_llm_response.php
```

---

## 🔄 Rollback Plan

Se il fix causa problemi (unlikely):

```php
// backend/app/Services/RAG/KbSearchService.php lines 675-686
$seen = [];
foreach ($selIdx as $i) {
    $base = $mmrRanked[$i] ?? null;
    if ($base === null) { continue; }
    $docId = (int) $base['document_id'];
    // RESTORE: document-level dedup
    if (isset($seen[$docId])) continue;
    $seen[$docId] = true;
```

---

## 🧪 Next Steps

1. ✅ Backend fix implementato
2. ✅ Retrieval testato con script
3. ⏳ **Test RAG Tester UI** nel browser con query "orario comando polizia locale"
4. ⏳ **Test Widget UI** nel browser con stessa query
5. ⏳ Verifica risposte LLM corrette in entrambi i casi

---

## 📝 Note Tecniche

### Why Document-Level Dedup Existed?

**Original Intent**: Evitare troppi chunk dello stesso documento che dominano le citations (e.g., 8/10 citations dallo stesso doc).

**Why It Backfired**: Per documenti lunghi con struttura gerarchica (es. pagina "Contatti" con sezioni per ufficio), informazioni rilevanti sono distribuite in chunk separati. Filtrare a document-level perde queste informazioni complementari.

### Why Chunk-Level Dedup is Better?

- **Preserva rilevanza**: Se MMR seleziona più chunk dello stesso documento, vuol dire che sono rilevanti e diversificati
- **No duplicati esatti**: Dedup a chunk-level previene chunk identici ma permette chunk diversi dello stesso doc
- **MMR già gestisce diversity**: MMR usa `lambda` per bilanciare relevance vs. diversity, quindi multiple chunks dello stesso doc vengono selezionate solo se davvero rilevanti

### Alternative Approach (Not Implemented)

**Neighbor Expansion**: Invece di prendere più chunk separati, espandere il chunk #1 includendo ±N neighboring chunks.

**Pro**: Contesto continuo, no frammentazione  
**Contro**: Potrebbe includere info non rilevanti, aumenta token count

**Decision**: Preferita la soluzione chunk-level dedup perché più precisa (solo chunk rilevanti) e più efficiente (token count controllato da MMR).

---

## ✅ Summary

**TASK COMPLETED**: ✅  
**Fix Type**: Backend RAG retrieval logic  
**Impact**: Medium - migliora query su documenti lunghi strutturati  
**Risk**: Low - no regressioni attese, MMR già controlla diversity  
**Test Status**: Backend verified ✅, UI testing pending ⏳

---

**Created by**: Cursor Agent  
**Completed**: 2025-10-20  
**Duration**: 30 minuti  
**Test Coverage**: Backend verified, UI testing next

