# Report Problema Retrieval - Doc 4350 "Comando Polizia Locale"

**Data**: 2025-10-16
**Query**: "telefono comando polizia locale"
**Tenant ID**: 5
**Documento Target**: 4350 (https://www.comune.sancesareo.rm.it/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20110)

---

## 🎯 Obiettivo

Fare in modo che la query "telefono comando polizia locale" restituisca il documento 4350 (Orari e Contatti degli Uffici) che contiene il telefono corretto del Comando Polizia Locale: **06.95898223**.

---

## ❌ Problema Attuale

Doc 4350 **NON arriva nelle final citations** (top-3 passate all'LLM).

Le final citations sono SEMPRE le stesse:
1. **Doc 4315** (Numeri ed indirizzi utili - generico)
2. **Doc 4298** (Ordinanze della Polizia Locale - menziona "polizia" ma nessun telefono)
3. **Doc 4304** (Numeri ed indirizzi utili - generico)

Nessuno di questi documenti contiene il telefono corretto **06.95898223**.

---

## 🔧 Fix Implementati (Senza Successo)

### Fix #1: Synonym Expansion (✅ Implementato)
**Problema**: BM25 non trovava "telefono" perché il chunk contiene "tel:" invece di "telefono".

**Soluzione**: Espansione sinonimi in `KbSearchService::getSynonymsMap()`:
```php
'telefono' => 'tel phone numero contatto',
```

**Risultato**: BM25 ora matcha "tel:" ma doc 4350 comunque non arriva alle final citations.

**Commit**: `c04defa` (merged)

---

### Fix #2: Semantic-Only Chunking for Scraped Docs (✅ Implementato)
**Problema**: Table-aware chunking spezzava le tabelle in chunk separati, causando context loss.

**Soluzione**: Per documenti `web_scraper`, uso **semantic-only chunking** con tabelle inline per preservare il contesto narrativo.

**File Modificati**:
- `backend/app/Jobs/IngestUploadedDocumentJob.php`
- `documents/02-INGESTION-FLOW.md`

**Risultato**: Doc 4350 ora ha 3 chunk ben formati con tabelle integrate, MA ancora non arriva alle final citations.

**Commit**: `5670fda` (merged)

---

### Fix #3: Boilerplate Removal (✅ Implementato)
**Problema**: Chunk di doc 4350 iniziava con boilerplate (URL, "Scraped on", header) che riduceva la semantic similarity.

**Soluzione**: Rimuovo boilerplate durante chunking per documenti scrape:
- `backend/app/Services/Ingestion/ChunkingService.php` → `removeScraperBoilerplate()`
- `backend/app/Jobs/IngestUploadedDocumentJob.php` → `remove_boilerplate => true`

**Prima**:
```markdown
# www.comune.sancesareo.rm.it
**URL:** https://www.comune.sancesareo.rm.it/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20110
**Scraped on:** 2025-10-16 07:36:18

Orari e Contatti degli Uffici
...
```

**Dopo**:
```markdown
Orari e Contatti degli Uffici
...
```

**Risultato**: Embeddings rigenerati con chunk puliti, MA doc 4350 ancora non arriva alle final citations.

**Commit**: Non ancora committato (pending)

---

### Fix #4: Reduce BM25 Weight (✅ Implementato)
**Problema**: Docs 4315 e 4304 hanno 46 chunks ciascuno con molte occorrenze di "telefono" (generic), dando loro score BM25 molto alto anche se non contengono l'informazione corretta.

**Soluzione**: Ridotto `bm25_top_k` da 150 a 30 in `backend/config/rag.php` per dare più peso al semantic vector search.

**Risultato**: Nessun cambiamento nelle final citations. Doc 4350 ancora non selezionato.

**Commit**: Non ancora committato (pending)

---

## 🔍 Analisi Root Cause

### 1. Doc 4350 arriva nel Fusion Top-10?
**Da log precedenti** (quando funzionava parzialmente):
```json
"fused_top_10": [
  {"doc_id":4315, "final_score":0.01485...},  #1
  {"doc_id":4304, "final_score":0.01470...},  #2
  {"doc_id":4304, "final_score":0.01456...},  #3
  {"doc_id":4350, "final_score":0.01442...},  #4  ← QUI!
  {"doc_id":4315, "final_score":0.01428...},  #5
  {"doc_id":4350, "final_score":0.01415...},  #6  ← QUI!
  ...
]
```

**Risposta**: SÌ, doc 4350 era nei fused top-10 (posizioni #4 e #6) ma **veniva escluso da MMR/Context Building**.

### 2. Perché MMR esclude doc 4350?
**MMR (Maximal Marginal Relevance)** seleziona chunks DIVERSI tra loro per massimizzare la copertura.

**Ipotesi**:
- Doc 4350 ha solo **3 chunks** (piccolo, specifico)
- Docs 4315 e 4304 hanno **46 chunks** ciascuno (grandi, generici con molti "telefono")
- MMR preferisce documenti con più chunks e maggiore diversità, anche se generici

### 3. Config Tenant-Specific
**Verifica**:
```bash
php backend/check_tenant_5_rag_config.php
```

**Output**:
```
Tenant ID: 5
Name: San Cesareo 2
NO CUSTOM RAG CONFIG - using global defaults

Global defaults:
  - vector_top_k: 20  ← ⚠️ TROPPO BASSO!
  - bm25_top_k: 30
  - mmr_take: 50
  - mmr_lambda: 0.1
```

**PROBLEMA TROVATO**: `vector_top_k = 20` invece di `100`!

Anche se nel `backend/config/rag.php` il default è `100`, il runtime mostra `20`. Questo significa che **il retrieval vettoriale recupera SOLO 20 chunks**, limitando drasticamente le possibilità che doc 4350 venga incluso.

---

## 💡 Soluzioni Proposte

### Soluzione #1: Aumentare `vector_top_k` (CRITICO)
**Problema**: `vector_top_k = 20` è troppo basso per un knowledge base con centinaia di chunks.

**Fix**:
1. Verificare perché il config runtime mostra `20` invece di `100`
2. Opzioni:
   - Aggiungere `RAG_VECTOR_TOP_K=100` al `.env`
   - Verificare che non ci sia una sovrascrittura in `TenantRagConfigService`
   - Aumentare il default hardcoded se il config non viene caricato

**Priorità**: 🔴 ALTA

---

### Soluzione #2: Aumentare `mmr_take` per Context Building
**Problema**: Anche se doc 4350 è nei fusion top-10, il context builder prende solo i primi N chunks per costruire il contesto finale.

**Fix**:
- Aumentare `context.max_chars` da 12000 a 20000
- Aumentare numero di citations passate all'LLM da 3 a 5-10
- Ridurre `mmr_lambda` per dare più peso alla rilevanza e meno alla diversità

**Priorità**: 🟠 MEDIA

---

### Soluzione #3: Implementare Document Boosting
**Problema**: Doc 4350 è piccolo (3 chunks) vs docs 4315/4304 (46 chunks ciascuno).

**Fix**:
- Aggiungere un **boost score** per documenti piccoli e specifici
- Penalizzare documenti generici ("Numeri utili") che hanno molte occorrenze ma poca precisione
- Usare **TF-IDF normalizzato** per favorire termini rari e specifici

**Priorità**: 🟢 BASSA (richiede refactoring)

---

### Soluzione #4: Manual Testing & Cache Clearing
**Problema**: Potrebbe esserci cache stale o config non caricato.

**Fix**:
1. Clear all caches:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan optimize:clear
   ```
2. Restart web server (Apache/PHP-FPM)
3. Test manualmente con RAG Tester e Widget

**Priorità**: 🟡 MEDIO (quick win potenziale)

---

## 📋 Next Steps

### Immediate Actions (Do Now)
1. ✅ Commit Fix #3 (boilerplate removal) e Fix #4 (bm25_top_k reduction)
2. ❌ Investigare perché `vector_top_k = 20` invece di `100` nel runtime
3. ❌ Aggiungere `RAG_VECTOR_TOP_K=100` al `.env` come override
4. ❌ Clear all caches e restart server
5. ❌ Test manuale con query "telefono comando polizia locale"

### Follow-up Actions (Later)
6. Implementare document boosting per documenti specifici vs generici
7. Ridurre `mmr_lambda` per dare più peso alla rilevanza
8. Aumentare numero di citations passate all'LLM
9. Aggiungere telemetria per visualizzare fusion ranking e MMR selection in Admin UI

---

## 📄 Files Modificati (Pending Commit)

1. `backend/app/Services/Ingestion/ChunkingService.php` - Boilerplate removal
2. `backend/app/Jobs/IngestUploadedDocumentJob.php` - Enable boilerplate removal for scraped docs
3. `backend/config/rag.php` - Reduce bm25_top_k from 150 to 30
4. `backend/delete_doc_4350_vectors.php` - Script per eliminare vecchi vettori (cleanup)
5. `backend/check_tenant_5_rag_config.php` - Debug script per verificare config tenant

---

## 🎓 Lessons Learned

1. **Semantic Similarity** non è sufficiente se il boilerplate diluisce il contenuto rilevante
2. **BM25** dà score alto a documenti con molte occorrenze, anche se generiche
3. **MMR** può escludere documenti specifici in favore di documenti più grandi e diversi
4. **Config Runtime** può differire dal config file a causa di `.env` overrides o caching
5. **Multi-step Retrieval** (vector + BM25 + fusion + MMR + context building) ha molti punti di failure

---

## ⚠️ Blockers Attuali

1. **`vector_top_k = 20`** invece di `100` - limita drasticamente il retrieval
2. **MMR selection** preferisce documenti grandi e diversi vs specifici e piccoli
3. **Final citations = 3** - troppo poche per coverage completa

---

## ✅ Successi Parziali

1. ✅ Synonym expansion funziona (BM25 ora matcha "tel:")
2. ✅ Semantic-only chunking preserva context (no più table splitting)
3. ✅ Boilerplate removal migliora chunk quality
4. ✅ Doc 4350 arriva nei fusion top-10 (ma viene escluso da MMR)

---

**Conclusione**: Il problema principale è **`vector_top_k = 20`** che limita il retrieval vettoriale. Una volta risolto questo, doc 4350 dovrebbe avere molte più probabilità di arrivare alle final citations.

