# ✅ Step 1 Completed: God Classes Analysis

**Date**: 14 Ottobre 2025  
**Duration**: ~1 ora  
**Status**: ✅ **COMPLETED**

---

## 📊 Analysis Summary

### IngestUploadedDocumentJob
- **LOC**: 976 righe (+677 oltre il limite di 300)
- **Methods**: 69 metodi
- **Properties**: 11 proprietà
- **Complexity**: 143 (avg 2.07 per metodo)
- **Dependencies**: Nessuna injection diretta (problema!)
- **External Calls**:
  - Milvus: `upsertVectors`
  - Storage: `disk`
  - Database: `transaction`, `table`

**Responsibilities Identified**:
1. File Extraction
2. Text Processing
3. Chunking
4. Embeddings
5. Vector Indexing
6. Storage
7. RAG Orchestration
8. Fallback Logic
9. Profiling
10. CRUD Operations
11. Filtering
12. Validation

**Critical Issues**:
- ❌ **Metodo `handle()` di 99 righe** - troppo complesso
- ❌ **`readTextFromStoragePath()` di 126 righe** - estrazione file
- ❌ **`chunkText()` di 131 righe** - logica di chunking
- ❌ **`findTablesInText()` di 113 righe** - table-aware parsing
- ❌ **Nessuna dependency injection** - tutto hardcoded

---

### ChatCompletionsController
- **LOC**: 788 righe (+588 oltre il limite di 200 per controller)
- **Methods**: 13 metodi
- **Properties**: Non rilevate (iniettate nel costruttore)
- **Complexity**: 67 (avg 5.15 per metodo)
- **Dependencies**:
  - `OpenAIChatService` (external_api)
  - `KbSearchService` (internal_service)
  - `LinkConsistencyService` (internal_service)
- **External Calls**: Nessuna chiamata diretta (✅ già usa Services)

**Responsibilities Identified**:
1. RAG Orchestration ⭐
2. Fallback Logic
3. Profiling
4. CRUD Operations
5. Filtering
6. Validation

**Critical Issues**:
- ❌ **Metodo `create()` di 350+ righe** - troppo business logic nel controller
- ❌ **6+ metodi privati** che dovrebbero stare in Services:
  - `buildRagTesterContextText()` (60+ righe)
  - `calculateSmartSourceScore()` (30+ righe)
  - `calculateContentQualityScore()` (20+ righe)
  - `calculateIntentMatchScore()` (30+ righe)
  - `calculateSourceAuthorityScore()` (30+ righe)

---

### DocumentAdminController
- **LOC**: 786 righe (+586 oltre il limite di 200 per controller)
- **Methods**: 12 metodi
- **Properties**: Non rilevate
- **Complexity**: 70 (avg 5.83 per metodo)
- **Dependencies**: Nessuna injection (problema!)
- **External Calls**:
  - Milvus: `deleteByTenant`, `deleteByPrimaryIds`
  - Storage: `disk`
  - Database: `table`

**Responsibilities Identified**:
1. CRUD Operations ⭐
2. Filtering ⭐
3. Storage ⭐
4. File Extraction
5. Text Processing
6. Chunking
7. Embeddings
8. Vector Indexing
9. RAG Orchestration
10. Fallback Logic
11. Profiling
12. Validation

**Critical Issues**:
- ❌ **Metodo `index()` con logica complessa di filtering**
- ❌ **Metodo `store()` gestisce upload, validation, e dispatch job**
- ❌ **Chiamate dirette a Milvus e Storage** invece di Services

---

## 🔍 Key Insights

### Pattern Comuni
1. **Nessuna Dependency Injection in Job e Admin Controller**
   - IngestUploadedDocumentJob: 0 dependencies injected
   - DocumentAdminController: 0 dependencies injected
   - ⚠️ Violazione del principio DI

2. **Business Logic nei Controller**
   - ChatCompletionsController: 6 metodi privati di calcolo score
   - DocumentAdminController: Logica di filtering inline

3. **Metodi Troppo Lunghi**
   - 5 metodi >100 LOC identificati
   - Average LOC per metodo: 12-14 righe (target: <30)

4. **Chiamate Dirette a Servizi Esterni**
   - Storage:: chiamato direttamente
   - DB:: chiamato direttamente
   - Milvus chiamato direttamente

### Complessità per Classe

| Classe | Total Complexity | Avg per Method | Risk Level |
|--------|------------------|----------------|------------|
| IngestUploadedDocumentJob | 143 | 2.07 | 🔴 HIGH |
| ChatCompletionsController | 67 | 5.15 | 🟡 MEDIUM |
| DocumentAdminController | 70 | 5.83 | 🟡 MEDIUM |

---

## 📁 Generated Files

1. ✅ **`scripts/analyse_god_classes.php`** - Script di analisi statica
2. ✅ **`backend/storage/temp/god_analysis.json`** - Risultati completi in JSON
3. ✅ **`backend/tests/Unit/AnalyseGodClassesTest.php`** - Test di validazione struttura

---

## 🎯 Raccomandazioni per Step 2

### Priority 1: IngestUploadedDocumentJob
**Nuovi Services da creare**:
1. `DocumentExtractionService` - per `readTextFromStoragePath()` (126 LOC)
2. `TextParsingService` - per normalizzazione e table detection (100+ LOC)
3. `ChunkingService` - per `chunkText()` e metodi correlati (200+ LOC)
4. `EmbeddingBatchService` - per gestione batch OpenAI (50+ LOC)
5. `VectorIndexingService` - per upsert Milvus (50+ LOC)

**LOC Reduction**: 976 → ~150 righe

### Priority 2: ChatCompletionsController
**Nuovi Services da creare**:
1. `ChatOrchestrationService` - per logica RAG pipeline (300+ LOC)
2. `ContextScoringService` - per tutti i metodi `calculate*Score()` (150+ LOC)
3. `FallbackStrategyService` - per gestione fallback (50+ LOC)
4. `ChatProfilingService` - per metrics e logging (50+ LOC)

**LOC Reduction**: 788 → ~80 righe

### Priority 3: DocumentAdminController
**Nuovi Services da creare**:
1. `DocumentCrudService` - per CRUD operations (200+ LOC)
2. `DocumentFilterService` - per query building e filtering (150+ LOC)
3. `DocumentUploadService` - per gestione upload e validation (100+ LOC)
4. `DocumentStorageService` - per S3/Milvus cleanup (100+ LOC)

**LOC Reduction**: 786 → ~120 righe

---

## ✅ Step 1 Deliverables

- [x] Script di analisi statica funzionante
- [x] JSON con metriche complete per 3 classi
- [x] Test unitario per validazione struttura
- [x] Identificazione delle responsabilità
- [x] Mapping di dependencies e external calls
- [x] Calcolo complessità ciclomatica
- [x] Raccomandazioni per Step 2

---

## 📊 Next Steps

**Step 2**: Diagramma decomposizione responsabilità (Mermaid)  
**Estimated Effort**: 2 ore  
**Ready to proceed**: ✅ YES


