# Driver Milvus per indice vettoriale (scelta corrente)

Questa guida definisce setup, configurazione e best practice per usare Milvus (o Zilliz Cloud) come vector store per il RAG. L'obiettivo è mantenere l'architettura pluggable dietro un adapter `VectorIndex` e garantire scoping multitenant, performance e affidabilità.

## 1) Configurazione applicativa

- Driver: `RAG_VECTOR_DRIVER=milvus`
- Connessione (self-hosted):
  - `MILVUS_HOST=localhost`
  - `MILVUS_PORT=19530` (gRPC)
  - `MILVUS_TLS=false` (true in prod)
- Connessione (Zilliz Cloud):
  - `MILVUS_URI=` URI fornita (gRPC/HTTPS)
  - `MILVUS_TOKEN=` API key/token
  - `MILVUS_TLS=true`
- Parametri embedding:
  - Dimensione: `3072` per `text-embedding-3-large` (consigliato), `1536` per `text-embedding-3-small`
  - Metrica: `COSINE` (normalizzare i vettori lato applicazione)

Suggerimento: centralizzare questi valori in `backend/config/rag.php` (sezione `vector`) con override via `.env`.

### 1.1 Esempio .env

```env
# OpenAI embeddings
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
# opzionale: OPENAI_EMBEDDING_DIM=3072

# RAG vector driver
RAG_VECTOR_DRIVER=milvus
RAG_VECTOR_METRIC=cosine
RAG_VECTOR_TOP_K=20
RAG_VECTOR_MMR_LAMBDA=0.3

# Milvus (self-hosted)
MILVUS_HOST=127.0.0.1
MILVUS_PORT=19530
MILVUS_TLS=false
MILVUS_COLLECTION=kb_chunks_v1

# Index params
MILVUS_INDEX_TYPE=HNSW
MILVUS_HNSW_M=16
MILVUS_HNSW_EF_CONSTRUCTION=200
MILVUS_HNSW_EF=96

# Zilliz Cloud (in alternativa al blocco self-hosted)
# MILVUS_URI=
# MILVUS_TOKEN=
# MILVUS_TLS=true
```

## 2) Topologia e scoping multitenant

Opzione consigliata:
- Una collection unica per ambiente (es. `kb_chunks_v1`) con:
  - Partizioni Milvus per `tenant_id` (es. `tenant_123`)
  - Campo scalare `collection_id` per filtrare per knowledge base
- Vantaggi: meno overhead gestionale, compattazione e indicizzazione uniformi, filtri efficienti.

Alternative:
- Collection per tenant o per KB nei casi di isolamento forte o requisiti di retention per tenant.

## 3) Schema della collection (consigliato)

- `id` (primary key auto-generata da Milvus o stringa esterna hashed)
- `tenant_id` (string)
- `collection_id` (string o int)
- `doc_id` (string o int)
- `chunk_id` (string o int)
- `vector` (FloatVector, dim = 3072/1536)
- Scalar fields per filtri: `lang` (string), `tags` (array<string> opzionale tramite ripetizione), `created_at` (int64 epoch)

Nota: mantenere i metadati ricchi e il contenuto completo in Postgres; in Milvus salvare solo riferimenti/filtri minimi e il vettore.

## 4) Indici e parametri di ricerca

- Index type: `HNSW` (default consigliato)
  - `M=16`, `efConstruction=200`
- Search params (HNSW): `ef=64~128` (tuning per latenza/recall)
- Alternative: `IVF_FLAT`/`IVF_SQ8` (dataset molto grandi), `AUTOINDEX` (gestione automatica in Zilliz Cloud)
- MMR: applicato in applicazione per diversità risultati

## 5) Operatività

- Dev locale (Docker Compose): Milvus standalone

```yaml
version: "3.8"
services:
  milvus-standalone:
    image: milvusdb/milvus:latest
    container_name: milvus-standalone
    environment:
      MILVUS_LOG_LEVEL: info
    command: ["milvus", "run", "standalone"]
    ports:
      - "19530:19530" # gRPC
      - "9091:9091"   # metrics
    volumes:
      - milvus_data:/var/lib/milvus
volumes:
  milvus_data:
```

- Staging/Prod:
  - Preferire Zilliz Cloud o cluster Milvus HA
  - Abilitare TLS, usare token/API key, principle of least privilege
  - Co-location regione con app/web/workers per ridurre RTT/egress
  - Monitorare build degli indici, compaction, latenza p95, recall@K

## 6) Pipeline ingestion e query

- Ingestion:
  - Calcolo embedding (dimensione coerente) -> upsert vettore in Milvus con scalar fields e riferimenti
  - Aggiornare indice/partizione se nuovi tenant/KB
- Query:
  - Recupero top-K per `tenant_id` + eventuali filtri (`collection_id`, `lang`, `tags`)
  - Reranking opzionale e MMR in applicazione
  - Costruzione contesto token-aware e citazioni

## 7) Sicurezza e compliance

- TLS end-to-end, token management sicuro (vault)
- Data residency/regioni compatibili con i requisiti del tenant
- Audit su creazione/alterazione collection/partizioni e accessi

## 8) Tuning e SLO

- Target: p95 < 100ms per la fase di search vettoriale su dataset medi (hardware/plan dipendente)
- Parametri da ottimizzare: `ef`, `M`, dimensione K, filtri
- Compaction schedulata e controllo frammentazione storage

## 9) Migrazioni e versioning

- Versionare la collection (es. `kb_chunks_v1`, `kb_chunks_v2`) per cambi schema/parametri
- Piano di reindicizzazione progressiva in background (code workers) con flag di cutover

---

Per dettagli su integrazione a livello di codice, utilizzare l'adapter `VectorIndex` lato servizio `KbSearchService`, mantenendo branch per driver e fallback lessicale documentati nell'analisi funzionale.
