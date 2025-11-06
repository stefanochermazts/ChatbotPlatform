# 🧪 Test Multi-Chunk Fix - RAG Tester & Widget UI

**Date**: 2025-10-20  
**Fix**: Permettere multiple chunks dello stesso documento  
**Status**: Backend verified ✅, UI testing required

---

## ✅ Backend Status

- **Retrieval**: Verified - 3 chunks di doc 4351 nelle citations (prima solo 1)
- **Chunk Corretto**: Position #8 contiene orari COMANDO POLIZIA LOCALE ✅
- **Cache**: Cleared (`php artisan config:clear && cache:clear`)

---

## 🧪 Test 1: RAG Tester UI

### URL
```
https://chatbotplatform.test:8443/admin/rag/run
```

### Step-by-Step

1. **Login** come admin
2. **Seleziona tenant**: San Cesareo 2 (5)
3. **Query**: `orario comando polizia locale`
4. **Opzioni**:
   - ✅ "Genera risposta con LLM"
   - ⚠️ HyDE: OFF (default)
   - ⚠️ Contesto Conversazionale: OFF
   - Reranker: embedding (default)
5. **Click** "Esegui"

### Expected Result ✅

**Reranking Section** - Top 3:
```
1. Doc 4351.2 sim 0.593 (POLIZIA STRADALE)
2. Doc 4351.1 sim 0.489 (ORARI COMANDO) ← questo è quello corretto
3. Doc 4298.3 sim 0.449
```

**LLM Response** - Should mention:
- "Comando Polizia Locale"
- Orari: Martedì 8:30-12:00, Giovedì 15:00-17:00, Venerdì 8:30-12:00
- Telefono: 06.95898223 (optional)

### Expected Failure ❌ (if fix didn't work)

- Reranker trova chunk corretto ma...
- LLM response: "Non ho trovato informazioni" o risponde con orari sbagliati (POLIZIA STRADALE invece di COMANDO)

---

## 🧪 Test 2: Widget UI

### URL (esempio)
```
https://chatbotplatform.test:8443/admin/tenants/5/widget/preview
```

O usare il widget embeddato in qualsiasi pagina di test.

### Step-by-Step

1. **Open widget** per tenant 5 (San Cesareo 2)
2. **Invia query**: `orario comando polizia locale`
3. **Wait** per risposta (potrebbe richiedere 3-5 secondi)

### Expected Result ✅

**Response** should contain:
```
Gli orari di apertura al pubblico del Comando Polizia Locale sono:

Martedì: 8:30-12:00
Giovedì: 15:00-17:00
Venerdì: 8:30-12:00

[oppure variante simile con orari corretti]
```

**Citations** (se mostrate) dovrebbero includere:
- Doc 4351 (www.comune.sancesareo.rm.it)
- Link alla fonte

### Expected Failure ❌ (if fix didn't work)

- "Non ho trovato informazioni sufficienti"
- Risponde con orari di POLIZIA STRADALE o ATS invece di COMANDO
- Risponde solo con telefono senza orari

---

## 🎯 Query Varianti da Testare (Bonus)

Se il test principale funziona, prova queste varianti:

### Query 1: Solo "orario"
```
orario polizia locale
```
**Expected**: Dovrebbe comunque trovare orari COMANDO

### Query 2: Conversazionale
```
e l'orario del comando di polizia locale?
```
**Note**: Richiede context dalla conversazione precedente, potrebbe non funzionare senza setup conversazionale

### Query 3: Telefono
```
telefono comando polizia locale
```
**Expected**: Dovrebbe trovare 06.95898223

### Query 4: Entrambi
```
orari e telefono comando polizia locale
```
**Expected**: Dovrebbe trovare sia orari che telefono

---

## 📊 Debugging UI

Se il test fallisce, verifica:

### RAG Tester - Sezione "Reranked Results"

**Check**: Chunk 4351.1 è nei top 10 reranked?
- ✅ YES → problema è nel LLM/system prompt
- ❌ NO → problema è nel retrieval/reranking

### Browser Developer Console

```js
// Open Console (F12)
// Verifica chiamata API
// Network tab → filter "rag" o "chat"
// Check Response → "citations" field
```

**Check**: Array `citations` contiene doc 4351 con orari?

### Backend Log

```bash
cd backend
tail -f storage/logs/laravel.log
```

Cerca:
```
[RERANK] Reranked top 10
[MMR] Performance optimization applied
```

---

## ✅ Success Criteria

Test considerato **PASSED** se:

1. ✅ RAG Tester mostra Doc 4351.1 nei reranked results (top 5)
2. ✅ LLM response mentions orari corretti (Martedì 8:30-12:00, etc.)
3. ✅ Widget response mentions orari corretti
4. ✅ NO "Non ho trovato informazioni"
5. ✅ NO orari sbagliati (es. ATS o POLIZIA STRADALE)

---

## 🔄 Se il Test Fallisce

### Scenario 1: Backend OK, LLM sbagliato

**Sintomo**: Reranker trova chunk corretto, ma LLM risponde male

**Causa possibile**:
- System prompt troppo restrittivo
- Context troppo lungo (LLM perde informazioni)
- LLM model issue (rare)

**Fix**:
- Rivedere `custom_system_prompt` per tenant 5
- Ridurre `neighbor_radius` per context più conciso

### Scenario 2: Reranker non trova chunk corretto

**Sintomo**: Chunk 4351.1 non è nei top reranked

**Causa possibile**:
- Query expansion non funziona
- Embedding reranker score troppo basso
- MMR filtra chunk per diversity

**Fix**:
- Test con `llm` reranker invece di `embedding`
- Aumentare `mmr_lambda` per favorire relevance vs diversity
- Verificare embeddings di chunk 4351.1

### Scenario 3: Widget diverso da RAG Tester

**Sintomo**: RAG Tester funziona, Widget no

**Causa possibile**:
- Citation scoring filtra citations nel widget
- System prompt diverso tra i due
- Timeout/caching issues

**Fix**:
- Verificare `rag.scoring.enabled` = false
- Verificare `rag.scoring.min_confidence` < 0.01
- Clear browser cache / hard refresh (Ctrl+Shift+R)

---

## 📝 Report Results

Dopo il test, riporta:

1. ✅/❌ RAG Tester result
2. ✅/❌ Widget result
3. LLM response esatta (copy/paste)
4. Screenshot reranked section (opzionale)
5. Eventuali errori in console/log

---

**Created by**: Cursor Agent  
**Ready for**: Manual UI Testing  
**Estimated time**: 5-10 minuti

