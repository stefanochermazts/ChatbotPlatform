# Analisi funzionale – Piattaforma SaaS per Chatbot multitenant (TALL + RAG avanzato + API compatibili OpenAI Completions)

## 1) Scopo e obiettivi

Piattaforma multitenant per creare, configurare, distribuire e monitorare chatbot basati su LLM (OpenAI), con **RAG avanzato**, **API compatibili OpenAI Chat Completions**, e UI **TALL** (Tailwind, Alpine, Laravel, Livewire). Obiettivi: semplicità di gestione, affidabilità (grounding + citazioni), scalabilità e governance.

## 2) Attori

* **Platform Owner (Super Admin)**
* **Tenant Owner / Admin / Developer / Editor / Analyst / Agent / Viewer**
* **Utente finale** (web/app/SDK)

## 3) Glossario

Tenant, Bot, KB (Knowledge Base), RAG, Chunking, Embeddings, Hybrid Search, Reranking, Citazioni, Guardrail.

## 4) Ambito funzionale (alto livello)

1. Onboarding tenant & piani
2. Gestione utenti/ruoli (RBAC/ABAC)
3. Creazione/gestione bot (prompt, persona, canali)
4. Ingestion & curation KB (upload, crawler, connettori)
5. **RAG** configurabile per bot/collezione
6. Distribuzione (widget JS, SDK, webhook, REST)
7. Guardrail & policy
8. Analytics, feedback, evaluation
9. Cost management & quota
10. Governance & audit

## 5) Requisiti funzionali

### 5.1 Multitenancy

Isolamento dati (DB-per-tenant o scoping), domini custom (CNAME), piani/limiti, API key scoperte.

### 5.2 Gestione bot

Wizard di creazione, system prompt/persona, tool calling, parametri LLM, regole RAG, fallback.

### 5.3 Knowledge Base

Upload (PDF/DOCX/XLSX/PPTX/MD/TXT/ZIP), URL/sitemap, connettori (SharePoint/Drive/S3). Parsing/OCR, metadati, versioning, sync schedulata.

### 5.4 Ricerca & RAG (overview)

Query understanding → Hybrid retrieval (vettoriale+BM25) → Filtri → Reranking → Context pack token-aware → Risposta con citazioni → Logging/usage/feedback.

### 5.5 Widget & Canali

Widget JS themable, SDK browser/server, Webhooks eventi, integrazioni canali (Teams/Slack/WhatsApp—roadmap).

### 5.6 Analytics & Feedback

KPI conversazioni, costi/token, top intent/docs; like/dislike con motivi; LLM‑as‑a‑Judge.

### 5.7 Sicurezza, Compliance, Accessibilità

RBAC/ABAC, audit, cifratura, GDPR, **WCAG 2.1 AA** per admin e widget.

## 6) Requisiti non funzionali

P95 < 2.5s, ingestion 10k pagine/ora/tenant, SLO 99.9% API, logging/tracing, cost guardrails.

## 7) Stack tecnologico (TALL + componenti)

Laravel 11 (+ Octane), Livewire 3 + Alpine + Tailwind, PostgreSQL 16, vector store esterno (**Milvus**/Zilliz Cloud come scelta di riferimento; alternative valutate: Qdrant/Weaviate/Pinecone/RedisSearch/Elasticsearch/OpenSearch), Redis, Meilisearch/Typesense o BM25 DB, S3/Azure Blob, OpenAI (GPT‑4.1/4o; **text-embedding‑3**), OCR.

## 8) GraphQL: dove ha senso

**GraphQL** (Lighthouse) per admin/builder/analytics (query complesse, paginazione, subscriptions). **REST** per runtime chat, webhook, upload. Approccio ibrido.

## 9) Modello dati (logico)

Tenants, Users (ruoli/permessi), Bots, Collections, Documents, Chunks, Conversations, Messages (citations/usage), Feedback, Jobs, Webhooks, Budgets.

## 10) Flussi principali

### 10.1 Ingestion

a) Upload → parsing/OCR → chunking → embeddings → indicizzazione → QA → pronto.

### 10.2 Chat RAG

b) Query → retrieval+filtri → reranking → context → generation → citazioni → log/usage.

### 10.3 Evaluation

c) Sampling → judge → punteggi → trend/regressioni.

## 11) Integrazione OpenAI

Modelli selezionabili per bot, embeddings, function/tool calling, retry/backoff, stima costi.

## 12) Accessibilità & UX (TALL)

Componenti accessibili (ARIA, focus), contrasto, input da tastiera, modalità ridotto movimento; widget ad alto contrasto.

## 13) KPI

Groundedness ≥ 0.8; Hallucination < 2%; CSAT ≥ 4/5; Deflection ≥ 60%; Cost/conversation in target.

## 14) Roadmap

v1: multitenancy, ingestion base, RAG ibrido, widget, analytics base. v1.1: evaluation, feedback loop. v1.2: connettori/ocr/reranker avanzato. v2: canali esterni, AB test, subscriptions.

## 15) Criteri di accettazione (estratto)

Creazione bot + KB → risposta con citazioni (≥3 doc). Widget con deep‑link citazioni. Judge settimanale. Limiti piano attivi.

## 16) Compatibilità API stile OpenAI *Chat Completions*

**Endpoint:** `POST /v1/chat/completions` (SSE support). **Auth:** `Authorization: Bearer <API_KEY>`. **Request:** campi standard (model, messages, temperature, tools/tool\_choice, response\_format, stream, stop, n…). **Mapping RAG:** tool `search_kb`; citazioni in messaggi. **Response:** schema OpenAI (choices, message, tool\_calls, usage). **Errori:** compatibili; **Rate‑limit headers**; **Idempotency‑Key**. **Estensioni opz.:** campo `rag{}` non‑breaking. **Test:** suite Postman/Pest.

## 17) Function Calling dai prompt & Prompt Editor assistito

**Prompt Editor** con suggestions OpenAI, snippet, variabili, linting/policy, versioning & diff, A/B. **Tool Registry** (JSON Schema, timeout/retry, scope). **DSL opzionale** (`@tool`, `@guardrails`, `@include`). **Playground** con trace tool\_calls/costi. **Sicurezza:** sandbox, secrets in vault, approval workflow.

## 18) Separazione netta tra API / Backend / Frontend

**Frontends:** Admin (TALL) + Widget/SDK. **Gateway:** REST (OpenAI‑like), GraphQL admin, webhooks. **Service Layer:** RAG, planner, billing, audit. **Workers:** ingestion/embeddings/ocr/eval. **Data Layer:** DB, Vector, Blob, Cache. **Contratti:** OpenAPI + SDL versione `v1`. **Sicurezza:** scopes, RBAC/ABAC. **Osservabilità:** tracing, metrics.

## 19) Deployment: ambienti, infrastruttura e CI/CD

**K8s (Opz. A)** o **VM/Containers (Opz. B)**. WAF/Ingress, autoscaling workers, DB gestito, Redis, Vector, Blob, CDN. CI/CD: lint→test→build→canary/blue‑green→rollback; migrations safe; SLO/alerting; backup/DR (RPO≤15m; RTO≤60m).

## 20) Architettura Dev & MVP (senza Kubernetes né container)

2 VM (APP‑WEB, WORKERS) + servizi gestiti (Postgres/Redis/Search/Blob) + Cloudflare/CDN. NGINX + PHP‑FPM, Supervisor per code; Forge/Envoyer o SSH rsync per deploy; sicurezza minima (TLS, rate‑limit, allow‑list), monitoring Sentry/Uptime/Grafana Cloud.

# Analisi funzionale – Piattaforma SaaS per Chatbot multitenant (TALL + RAG avanzato + API compatibili OpenAI Completions)

## 21) RAG avanzato – architettura e dettagli implementativi (esteso per sviluppo "vibe coding")

### 21.1 Ingestion multi‑fonte e multi‑formato

* **Formati supportati**: PDF, DOCX, XLSX, PPTX, MD, TXT, HTML.
* **Parsing**: pipeline modulare con parser dedicati; OCR Tesseract per documenti scansionati; conversione HTML→Markdown preservando heading, tabelle, liste.
* **Scraping web**: crawler con gestione sitemap, link discovery, crawling profondo, rendering headless per SPA, dedup, rispetto robots.txt, rate‑limit e autenticazione opzionale.
* **Chunking intelligente**: divisione in blocchi basata su heading, paragrafi, tabelle o slide; lunghezza ottimizzata per token LLM.
* **Arricchimento metadati**: lingua, data creazione, autore, origine (file vs web), ACL.
* **Indicizzazione**: embeddings vettoriali (driver configurabile: Milvus/Qdrant/Weaviate/Pinecone/RedisSearch/Elasticsearch/OpenSearch) + indice testuale (BM25/Meilisearch/Typesense) per retrieval ibrido.
* **Versioning & QA**: versionamento documenti e validazione qualità prima di andare live.

### 21.2 Retrieval ibrido e filtrato

* **Query understanding**: espansione query, sinonimi, stemming.
* **Hybrid search**: combinazione ricerca vettoriale + keyword; pesatura dinamica.
* **Filtri**: per tag, lingua, data, ACL.
* **Reranking**: MMR per diversità e cross‑encoder per pertinenza.
* **Recency boost**: incremento punteggio per contenuti recenti.

### 21.3 Context builder e ottimizzazione token

* Packing token‑aware per sfruttare massimo contesto.
* Deduplica e merging contenuti simili.
* Citazioni con deep‑link a posizione esatta in documento o URL.
* Policy anti‑hallucination: confidence minima, fallback "I don’t know".

### 21.4 Generation & post‑processing

* Rispetto stile/persona bot.
* Citazioni obbligatorie con metadati.
* Validazione automatica output: rimozione PII, rispetto compliance.
* Fallback conservativo in caso di basso confidence.
* Caching query e risposte con invalidazione su update KB.

### 21.5 Evaluation & tuning continuo

* LLM‑as‑a‑Judge per valutare groundedness, utilità, sicurezza.
* A/B testing di prompt e strategie retrieval.
* Monitoraggio costi e latenza, tuning parametri K e modello.
* Logging completo per audit e miglioramento continuo.

---

Questo punto è progettato per supportare uno sviluppo modulare e incrementale, facilitando la creazione rapida di un RAG funzionante partendo da ingestion documentale e scraping web, con pipeline chiare e riutilizzabili.

## 22) Webchat totalmente customizzabile + accesso esclusivo a funzionalità backend

Theming/branding/layout, SDK eventi/slot, quick‑actions server‑mediated con JWT breve, HMAC, allow‑list, audit; distribuzione asset custom; criteri di accettazione.

## 23) Sicurezza backend: ruoli, permessi e policy

RBAC+ABAC, matrice permessi, scopes API, RLS per tabelle sensibili, OIDC/MFA, secrets in vault, rate‑limit/WAF, audit & privacy, criteri di accettazione.

## 24) Scelta driver vettoriale e strategie di fallback

Il driver vettoriale di riferimento è **Milvus** (in managed tramite Zilliz Cloud ove opportuno). Sono documentate anche alternative equivalenti; è previsto un degrado funzionale controllato verso retrieval lessicale + reranking in assenza del vettoriale.

### 24.1 Driver/adapter dell'indice vettoriale

- **Interfaccia**: uno strato `VectorIndex` (adapter) dietro configurazione applicativa consente di selezionare il backend senza impatti sul dominio RAG.
- **Configurazione**: chiave `rag.vector_driver` (es. env `RAG_VECTOR_DRIVER`) con valori ammessi: `milvus`, `qdrant`, `weaviate`, `pinecone`, `redis`, `elasticsearch`, `opensearch`, `meilisearch`, `typesense`, `null`.
- **Scoping multitenant**: ogni driver deve isolare dati per tenant/collezione (collection/database/index per tenant) e supportare ACL/filtri.

### 24.2 Opzioni supportate e note operative

- **Qdrant (oss/managed)**: HNSW/PQ, filtri ricchi, payload JSON; ottimo rapporto costo/prestazioni; semplice da gestire via Docker o cloud. Pro: veloce, schema flessibile. Contro: componente extra da gestire.
- **Weaviate (oss/managed)**: HNSW, modulo BM25 opzionale; buone feature enterprise. Pro: ricco ecosistema. Contro: footprint maggiore.
- **Pinecone (managed)**: semplicità e SLA; adatto a prod se si accetta vendor lock-in. Pro: gestione zero. Contro: costi/egress.
- **Milvus / Zilliz Cloud**: altissime prestazioni su grandi volumi; richiede competenze ops se self‑hosted. Scelto come default per staging/prod.
- **Redis Stack (RediSearch ≥2.4)**: indice vettoriale HNSW/IVF in‑memory; latenza molto bassa. Pro: già presente spesso per cache/queue. Contro: costi RAM, persistenza.
- **Elasticsearch / OpenSearch**: `dense_vector` + KNN (HNSW); utile se già usato per log/search. Pro: unified stack. Contro: tuning non banale.
- **Meilisearch / Typesense**: supporto “vector search” emergente; validi per hybrid se già usati per testuale. Pro: semplicità. Contro: feature vettoriali meno mature.
- **Fallback Postgres puro (senza estensioni)**: memorizzare embedding come array e calcolare similarità via SQL. Solo per ambienti di sviluppo o piccoli dataset; prestazioni limitate.

### 24.3 Strategia di fallback funzionale (senza indice vettoriale)

Quando `rag.vector_driver = null` o non disponibile:

- **Retrieval lessicale**: BM25 su Meilisearch/Typesense o full‑text Postgres; espansione query (sinonimi/stemming) e query rewriting LLM.
- **Reranking**: opzionale con servizi esterni (es. Cohere Rerank, OpenAI re‑rank via embeddings+cosine in memoria sui top‑N) per migliorare pertinenza.
- **MMR**: diversificazione dei risultati per coprire più aspetti; dedup.
- **Limiti espliciti**: documentare riduzione di recall/groundedness su domini puramente semantici.

### 24.4 Impatti su pipeline ingestion e query

- **Ingestion**: se il driver è vettoriale, creare/aggiornare indice per collezione; se fallback lessicale, saltare fase di upsert vettoriale e mantenere solo indice testuale.
- **Query**: branching in `KbSearchService` per usare top‑K vettoriale (se disponibile) oppure top‑K BM25 → rerank opzionale.
- **Parametri**: esporre in config per‑driver (dimensione embedding, metric: cosine/dot/L2, HNSW/IVF, ef/search lists, MMR λ). Per Milvus: metric predefinita COSINE con normalizzazione; dimensione embedding consigliata `3072` per `text-embedding-3-large` (o `1536` per `text-embedding-3-small`).

### 24.5 SLO, costi e sicurezza

- **Latenza**: vettoriale in‑memory (Redis) più rapido; managed (Pinecone/Qdrant cloud) aggiunge egress → usare regioni vicine.
- **Costi**: valutare prezzo per milione di vettori, storage e ingress/egress; impostare TTL/archiviazione fredda su versioni vecchie.
- **Sicurezza/Compliance**: cifratura at‑rest/in‑transit, data residency; audit su accessi all’indice; isolare per tenant.

### 24.6 Checklist di adozione rapida per ambienti

- Dev locale: `milvus-standalone` via Docker Compose. Env: `RAG_VECTOR_DRIVER=milvus`.
- Staging: Milvus (preferibilmente Zilliz Cloud) con configurazione pari al prod.
- Prod: Milvus managed (Zilliz Cloud) o cluster Milvus self‑hosted; usare regioni vicine all’APP per ridurre egress/RTT.

Ulteriori dettagli operativi, schema consigliato e parametri di indicizzazione sono in `docs/analisi-funzionale/driver-milvus.md`.

### 24.7 Documentazione e test

- Aggiornare `docs/` con guida di setup per ciascun driver scelto.
- Test di integrazione Pest per `KbSearchService`: scenari con driver vettoriale e fallback lessicale.
- Contract test invariati per l’endpoint `/v1/chat/completions`.
