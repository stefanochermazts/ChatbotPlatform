# ChatbotPlatform - Documentazione Tecnica dei Flussi

## Indice della Documentazione

Questa cartella contiene la documentazione tecnica completa dei flussi della piattaforma ChatbotPlatform.

### 📋 Documenti Disponibili

1. **[01-SCRAPING-FLOW.md](01-SCRAPING-FLOW.md)** - Flusso completo dello scraping web
   - Configurazione scraper
   - Esecuzione con Puppeteer
   - Salvataggio markdown
   - Dispatch ingestion

2. **[02-INGESTION-FLOW.md](02-INGESTION-FLOW.md)** - Pipeline di ingestion documenti
   - Upload e parsing documenti
   - Chunking intelligente
   - Generazione embeddings
   - Indicizzazione PostgreSQL e Milvus

3. **[03-RAG-RETRIEVAL-FLOW.md](03-RAG-RETRIEVAL-FLOW.md)** - Sistema RAG completo
   - Query processing e normalization
   - Synonym expansion
   - Intent detection
   - Hybrid retrieval (Vector + BM25)
   - Reranking e MMR
   - Context building
   - LLM generation

4. **[04-WIDGET-INTEGRATION-FLOW.md](04-WIDGET-INTEGRATION-FLOW.md)** - Integrazione widget
   - Embedding script
   - Configurazione e theming
   - API communication
   - Markdown rendering
   - Analytics tracking

5. **[05-DATA-MODELS.md](05-DATA-MODELS.md)** - Modelli dati e relazioni
   - Schema database
   - Relazioni tra entità
   - Indici e vincoli

6. **[06-QUEUE-WORKERS.md](06-QUEUE-WORKERS.md)** - Sistema code e workers
   - Configurazione Horizon
   - Code dedicate
   - Job retry e fallback
   - Monitoring

## 🎯 Quick Start

Per comprendere rapidamente il sistema, leggi in ordine:

1. Inizia da **05-DATA-MODELS.md** per capire le entità principali
2. Leggi **01-SCRAPING-FLOW.md** per capire come vengono acquisiti i contenuti
3. Procedi con **02-INGESTION-FLOW.md** per la pipeline di processing
4. Studia **03-RAG-RETRIEVAL-FLOW.md** per il cuore del sistema
5. Finisci con **04-WIDGET-INTEGRATION-FLOW.md** per l'integrazione frontend

## 🔗 Collegamenti Utili

- **Documentazione tecnica esistente**: `backend/docs/`
- **Analisi funzionale**: `docs/analisi-funzionale/`
- **README principale**: `README.md`
- **File AGENTS.md**: `AGENTS.md` (documentazione per AI agents)

## 📊 Diagrammi dei Flussi

Ogni documento contiene:
- Diagramma testuale del flusso
- Descrizione dettagliata di ogni step
- Classi e metodi coinvolti
- Esempi pratici
- Note di troubleshooting

## 🛠️ Convenzioni

- **Jobs**: Operazioni asincrone in coda (queue)
- **Services**: Logica di business riutilizzabile
- **Controllers**: Gestione HTTP requests/responses
- **Models**: Entità database con relationships
- **Commands**: Comandi Artisan CLI

## 📝 Aggiornamenti

Questa documentazione viene aggiornata ad ogni modifica significativa ai flussi principali.

**Ultima revisione**: 2025-10-07
**Versione piattaforma**: 1.0

