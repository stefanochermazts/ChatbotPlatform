# Fix Widget Intent Detection - Piano di Esecuzione

## Problema
Il RAG tester funziona perfettamente con intent detection, ma il widget non tiene conto dell'intent detection per le stesse query.

## Analisi Iniziale

**RAG Tester Flow** (funziona):
```
RagTestController::testIntent()
  → KbSearchService::retrieve($tenantId, $query, $debug=true)
    → detectIntents($query, $tenantId)  [linea 82]
      → scoreIntent() per ogni tipo (phone, email, address, schedule, thanks)
      → Filtra per min_score
      → Ordina per score
    → executeIntent($intentType, ...)  [linea 144-165]
      → TextSearchService::findPhonesNearName() / findEmailsNearName() / etc.
```

**Widget Flow** (problema):
```
ChatCompletionsController::create()
  → ChatOrchestrationService::orchestrate()
    → CompleteQueryDetector::detectCompleteIntent($query)  [linea 75]
      → Rileva solo query "complete" (tutti i consiglieri, elenco completo)
      → Restituisce is_complete_query flag
    → if (is_complete_query) retrieveComplete() else retrieve()  [linea 111-113]
      → KbSearchService::retrieve() DOVREBBE chiamare detectIntents() internamente
```

## Piano di Esecuzione

### Step 1: Reproduce the current widget issue
- Testare endpoint widget con query intent
- Verificare che gli intent NON vengano rilevati
- Documentare output attuale

### Step 2: Inspect widget flow
- Tracciare chiamate in ChatOrchestrationService
- Aggiungere log temporanei per confermare ordine esecuzione
- Creare diagramma Mermaid del flusso

### Step 3: Compare widget vs RAG tester
- Confrontare entry points
- Identificare differenze nel flusso
- Aggiornare diagramma con confronto side-by-side

### Step 4: Fix widget flow
- Modificare ChatOrchestrationService per sempre chiamare detectIntents()
- Assicurare che KbSearchService::retrieve() invochi detectIntents()
- Mantenere comportamento "complete queries" invariato

### Step 5: Write tests
- Creare Pest tests per widget intent flow
- Testare tutti gli intent (phone, email, address, schedule, thanks)
- Testare che "complete queries" non triggerino intent normali

### Step 6: Run test suite + manual verification
- Eseguire test completi
- Verifica manuale su tenant 5
- Confermare fix funzionante

### Step 7: Update documentation
- Aggiornare documentazione intent detection
- Includere diagramma flusso unificato
- Documentare differenza "complete query" vs intent standard

---

Generato: 2025-01-27

