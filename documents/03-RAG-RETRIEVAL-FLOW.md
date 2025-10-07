# Flusso RAG (Retrieval Augmented Generation)

## Panoramica

Il sistema RAG è il cuore della piattaforma: trasforma le query utente in risposte accurate recuperando chunks rilevanti dalla knowledge base e generando risposte con LLM (GPT-4o-mini).

## Diagramma del Flusso Completo

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. USER QUERY                                                   │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Widget → POST /api/v1/chat/completions                       ││
│ │ {                                                             ││
│ │   "messages": [{"role": "user", "content": "chi è il sindaco?"}]││
│ │   "tenant_id": 5,                                            ││
│ │   "stream": false                                            ││
│ │ }                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. QUERY NORMALIZATION                                          │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ QueryNormalizer::normalize($query)                           ││
│ │                                                               ││
│ │ - Lowercase                                                  ││
│ │ - Remove accents (è → e)                                     ││
│ │ - Trim whitespace                                            ││
│ │ - Remove special chars (?, !, ...)                           ││
│ │                                                               ││
│ │ Output: "chi e il sindaco"                                   ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. SYNONYM EXPANSION                                            │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ SynonymExpander::expand($query, $tenantId)                   ││
│ │                                                               ││
│ │ Tenant synonyms config:                                      ││
│ │ "sindaco" → ["sindaco", "primo cittadino", "mayor"]         ││
│ │                                                               ││
│ │ Expanded query:                                              ││
│ │ "chi e il sindaco OR primo cittadino OR mayor"               ││
│ │                                                               ││
│ │ Usato solo per BM25 text search (non embeddings)            ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. INTENT DETECTION                                             │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ IntentDetector::detect($query)                               ││
│ │                                                               ││
│ │ Intents rilevati:                                            ││
│ │ - phone: "numero di telefono", "chiamare"                    ││
│ │ - email: "indirizzo email", "scrivere a"                     ││
│ │ - address: "dove si trova", "indirizzo"                      ││
│ │ - schedule: "orari di apertura", "quando aperto"             ││
│ │ - thanks: "grazie", "ringrazio"                              ││
│ │                                                               ││
│ │ Se intent detected → Return structured response              ││
│ │ Altrimenti → Continue RAG flow                               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. KB SELECTION (Multi-KB o Single)                             │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ KnowledgeBaseSelector::selectKbs($query, $tenant)            ││
│ │                                                               ││
│ │ A) MULTI-KB MODE (tenant->rag_settings['multi_kb_search']['enabled'])││
│ │    → BM25 scoring su document_chunks per ogni KB            ││
│ │    → Select top KB con score > threshold                     ││
│ │    → Max 3 KB simultanee                                     ││
│ │                                                               ││
│ │ B) SINGLE-KB MODE                                            ││
│ │    → Use tenant->default_kb_id                               ││
│ │                                                               ││
│ │ Output: [kb_id => weight]                                    ││
│ │ Es: [2 => 1.0] (KB "Sito" con weight 100%)                   ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. HyDE EXPANSION (Optional)                                    │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ HyDEExpander::expand($query)                                 ││
│ │                                                               ││
│ │ Se enabled in rag_settings:                                  ││
│ │   LLM genera "hypothetical document" che risponderebbe       ││
│ │   alla query, poi usa QUELLO per embeddings                  ││
│ │                                                               ││
│ │ Example:                                                     ││
│ │ Query: "chi è il sindaco?"                                   ││
│ │ HyDE doc: "Il sindaco del comune è [nome]. È stato eletto..."││
│ │                                                               ││
│ │ Pro: Migliora recall per query ambigue                       ││
│ │ Con: +1 LLM call (latency +500ms)                            ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. PARALLEL RETRIEVAL                                           │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ [THREAD A]                        [THREAD B]                 ││
│ │                                                               ││
│ │ VECTOR SEARCH (Milvus)            BM25 TEXT SEARCH (Postgres)││
│ │ ──────────────────────            ─────────────────────────  ││
│ │ 1. Generate embedding              1. Tsquery con sinonimi   ││
│ │    OpenAI text-embedding-3         2. Full-text search       ││
│ │    [1536 dimensions]                  ts_rank_cd scoring     ││
│ │                                     3. Filter by tenant+KB   ││
│ │ 2. Milvus search                   4. LIMIT 50               ││
│ │    collection: chatbot_vectors                               ││
│ │    partition: tenant_{id}          Output:                   ││
│ │    filter: kb_id IN [...]          [{chunk_id, score}, ...]  ││
│ │    metric: COSINE similarity                                 ││
│ │    top_k: 50                                                 ││
│ │                                                               ││
│ │ Output:                                                       ││
│ │ [{chunk_id, score}, ...]                                     ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. RRF FUSION (Reciprocal Rank Fusion)                         │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ RRFFusion::fuse($milvusResults, $bm25Results)                ││
│ │                                                               ││
│ │ Formula: RRF_score = Σ 1 / (k + rank_i)                      ││
│ │ k = 60 (default constant)                                    ││
│ │                                                               ││
│ │ Example:                                                     ││
│ │ Chunk 3920:                                                  ││
│ │   - Milvus rank: 1 → 1/(60+1) = 0.0164                      ││
│ │   - BM25 rank: 3 → 1/(60+3) = 0.0159                         ││
│ │   - RRF score: 0.0164 + 0.0159 = 0.0323                      ││
│ │                                                               ││
│ │ Sort by RRF score DESC                                       ││
│ │ Take top 20 chunks                                           ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 9. RERANKING                                                    │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Reranker::rerank($query, $chunks, $topN)                     ││
│ │                                                               ││
│ │ Drivers disponibili (config tenant rag_settings):            ││
│ │                                                               ││
│ │ A) NONE - Skip reranking (use RRF scores)                    ││
│ │                                                               ││
│ │ B) EMBEDDING - FastEmbeddingReranker                         ││
│ │    - Lexical scoring (keyword overlap, TF, length)           ││
│ │    - Exact match bonus                                       ││
│ │    - Zero API calls                                          ││
│ │    - Fast (<50ms)                                            ││
│ │                                                               ││
│ │ C) LLM - LLMReranker                                         ││
│ │    - GPT-4o-mini scores each chunk 0-10                      ││
│ │    - Slow (+2s per 20 chunks)                                ││
│ │    - High accuracy                                           ││
│ │                                                               ││
│ │ D) COHERE - CohereReranker (future)                          ││
│ │    - Dedicated reranking API                                 ││
│ │                                                               ││
│ │ Output: Top 10 chunks reranked                               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 10. MMR (Maximal Marginal Relevance)                            │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ MMRSelector::select($chunks, $lambda, $topN)                 ││
│ │                                                               ││
│ │ Goal: Bilanciare relevance e diversity                       ││
│ │                                                               ││
│ │ Formula:                                                     ││
│ │ MMR = λ * sim(chunk, query) - (1-λ) * max sim(chunk, selected)││
│ │                                                               ││
│ │ λ = 0.7 (default)                                            ││
│ │ - λ=1.0 → solo relevance (no diversity)                      ││
│ │ - λ=0.5 → balance                                            ││
│ │ - λ=0.0 → solo diversity                                     ││
│ │                                                               ││
│ │ Iterative selection:                                         ││
│ │ 1. Start con chunk più relevant                              ││
│ │ 2. Per ogni chunk successivo, penalizza quelli troppo simili ││
│ │ 3. Repeat fino a topN (default 5)                            ││
│ │                                                               ││
│ │ Output: 5 chunks diversificati                               ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 11. CONTEXT BUILDING                                            │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ ContextBuilder::build($chunks, $query)                       ││
│ │                                                               ││
│ │ Per ogni chunk:                                              ││
│ │   1. Fetch full DocumentChunk da PostgreSQL                  ││
│ │   2. Fetch Document (per source_url)                         ││
│ │   3. Build context block:                                    ││
│ │                                                               ││
│ │   ──────────────────────────────────────                     ││
│ │   CONTESTO 1:                                                ││
│ │   [Contenuto chunk completo]                                 ││
│ │                                                               ││
│ │   Fonte originale: https://www.comune.sancesareo.rm.it/...   ││
│ │   ──────────────────────────────────────────                 ││
│ │                                                               ││
│ │ Token management:                                            ││
│ │ - Max context tokens: 4000 (config)                          ││
│ │ - Stop adding chunks se total > max                          ││
│ │ - Deduplicazione chunks identici                             ││
│ │                                                               ││
│ │ Output: Formatted context string                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 12. LLM GENERATION                                              │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ OpenAIChatService::chat($messages, $context)                 ││
│ │                                                               ││
│ │ Messages structure:                                          ││
│ │ [                                                             ││
│ │   {                                                           ││
│ │     "role": "system",                                        ││
│ │     "content": "Sei un assistente del Comune di San Cesareo..."││
│ │   },                                                          ││
│ │   {                                                           ││
│ │     "role": "system",                                        ││
│ │     "content": "[CONTESTO RAG]\n{context}"                   ││
│ │   },                                                          ││
│ │   {                                                           ││
│ │     "role": "user",                                          ││
│ │     "content": "chi è il sindaco?"                           ││
│ │   }                                                           ││
│ │ ]                                                             ││
│ │                                                               ││
│ │ API Call:                                                    ││
│ │ POST https://api.openai.com/v1/chat/completions              ││
│ │ {                                                             ││
│ │   "model": "gpt-4o-mini",                                    ││
│ │   "messages": [...],                                         ││
│ │   "temperature": 0.3,                                        ││
│ │   "max_tokens": 1000                                         ││
│ │ }                                                             ││
│ │                                                               ││
│ │ Response:                                                    ││
│ │ {                                                             ││
│ │   "choices": [{                                              ││
│ │     "message": {                                             ││
│ │       "content": "Il sindaco di San Cesareo è Alessandra Sabelli."││
│ │     }                                                         ││
│ │   }],                                                         ││
│ │   "usage": {                                                 ││
│ │     "prompt_tokens": 1234,                                   ││
│ │     "completion_tokens": 56                                  ││
│ │   }                                                           ││
│ │ }                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 13. RESPONSE FORMATTING                                         │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ ResponseFormatter::format($llmResponse, $chunks)             ││
│ │                                                               ││
│ │ {                                                             ││
│ │   "id": "chatcmpl-123",                                      ││
│ │   "object": "chat.completion",                               ││
│ │   "choices": [{                                              ││
│ │     "message": {                                             ││
│ │       "role": "assistant",                                   ││
│ │       "content": "Il sindaco di San Cesareo è Alessandra Sabelli."││
│ │     },                                                         ││
│ │     "finish_reason": "stop"                                  ││
│ │   }],                                                         ││
│ │   "usage": {                                                 ││
│ │     "prompt_tokens": 1234,                                   ││
│ │     "completion_tokens": 56,                                 ││
│ │     "total_tokens": 1290                                     ││
│ │   },                                                          ││
│ │   "x_rag_metadata": {                                        ││
│ │     "chunks_used": 5,                                        ││
│ │     "sources": [                                             ││
│ │       {                                                       ││
│ │         "document_id": 3920,                                 ││
│ │         "source_url": "https://www.comune.sancesareo.rm.it/..."││
│ │       }                                                       ││
│ │     ],                                                        ││
│ │     "profiling": {                                           ││
│ │       "total_ms": 2345,                                      ││
│ │       "breakdown": {                                         ││
│ │         "Milvus Search": 234,                                ││
│ │         "BM25 Search": 123,                                  ││
│ │         "Reranking": 45,                                     ││
│ │         "LLM Generation": 1234                               ││
│ │       }                                                       ││
│ │     }                                                         ││
│ │   }                                                           ││
│ │ }                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 14. CACHING & ANALYTICS                                         │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ A) Cache response (Redis)                                    ││
│ │    Key: rag_cache:{tenant_id}:{query_hash}                   ││
│ │    TTL: 3600s (1 ora)                                        ││
│ │    Invalidation: On KB document update                       ││
│ │                                                               ││
│ │ B) Analytics tracking                                        ││
│ │    - Log query + response                                    ││
│ │    - Track sources used                                      ││
│ │    - Store profiling metrics                                 ││
│ │    - Update conversation context                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Classi Coinvolte

### 1. Controller
- **`ChatCompletionsController.php`**
  - `chat()` - Main endpoint `/api/v1/chat/completions`
  - Orchestratore intero flusso RAG
  - Gestisce auth, validation, response formatting

### 2. Services RAG
- **`KbSearchService.php`** ⭐ CORE
  - `search(string $query, int $tenantId, array $kbIds): array`
  - Orchestratore: Milvus + BM25 + RRF + Reranking + MMR
  - Profiling dettagliato di ogni step

- **`MilvusClient.php`**
  - `search(array $embedding, int $tenantId, array $kbIds, int $topK): array`
  - Integrazione Milvus via Python bridge
  - Partition-based multitenancy

- **`TextSearchService.php`**
  - `search(string $query, int $tenantId, array $kbIds, int $limit): array`
  - BM25 full-text search con PostgreSQL ts_rank_cd
  - Synonym expansion support

- **`FastEmbeddingReranker.php`**
  - `rerank(string $query, array $candidates, int $topN): array`
  - Lexical/syntactic scoring (no API calls)
  - Keyword overlap + TF + exact match bonus

- **`LLMReranker.php`**
  - `rerank(string $query, array $candidates, int $topN): array`
  - GPT-4o-mini scoring 0-10 per chunk
  - Slow ma accurato

- **`KnowledgeBaseSelector.php`**
  - `selectKbs(string $query, Tenant $tenant): array`
  - Multi-KB selection con BM25 scoring
  - Fallback a default_kb_id

- **`HyDEExpander.php`**
  - `expand(string $query): string`
  - Hypothetical Document Embeddings
  - +1 LLM call

- **`SynonymExpander.php`**
  - `expand(string $query, int $tenantId): string`
  - Tenant-specific synonyms

- **`IntentDetector.php`**
  - `detect(string $query): ?string`
  - Pattern-based intent detection

### 3. Services LLM
- **`OpenAIChatService.php`**
  - `chat(array $messages, array $options): array`
  - Wrapper OpenAI Chat Completions API

- **`OpenAIEmbeddingsService.php`**
  - `embed(array|string $input): array`
  - Generate embeddings per query/documents

## Esempio Pratico Completo

### Query: "chi è il sindaco?"

#### Step-by-step Execution

```php
// 1. Normalization
$normalized = "chi e il sindaco";

// 2. Synonym expansion
$expanded = "chi e il (sindaco OR \"primo cittadino\" OR mayor)";

// 3. Intent detection
$intent = null; // No intent matched (è una info query)

// 4. KB Selection (Multi-KB enabled)
$selectedKbs = [2 => 1.0]; // KB "Sito" weight 100%

// 5. HyDE (disabled per questo tenant)
$hydeDoc = null;

// 6. Parallel retrieval
// MILVUS:
$milvusResults = [
  ['chunk_id' => 3920, 'score' => 0.87],
  ['chunk_id' => 3921, 'score' => 0.82],
  ...
];

// BM25:
$bm25Results = [
  ['chunk_id' => 3920, 'score' => 4.56],
  ['chunk_id' => 3531, 'score' => 3.21],
  ...
];

// 7. RRF Fusion
$rrfResults = [
  ['chunk_id' => 3920, 'score' => 0.0323], // Top!
  ['chunk_id' => 3921, 'score' => 0.0287],
  ...
];

// 8. Reranking (embedding driver)
$reranked = [
  ['chunk_id' => 3920, 'score' => 0.92], // Exact match bonus
  ['chunk_id' => 3921, 'score' => 0.78],
  ...
];

// 9. MMR (λ=0.7)
$final = [
  3920, // "Sabelli Alessandra - Sindaco"
  3925, // "Contatti Comune" (diversified)
  ...
];

// 10. Context building
$context = <<<CTX
──────────────────────────────────────────
CONTESTO 1:
Sabelli Alessandra - Sindaco
Email: sindaco@comune.sancesareo.rm.it

Fonte originale: https://www.comune.sancesareo.rm.it/amministrazione/politici
──────────────────────────────────────────
CONTESTO 2:
...
CTX;

// 11. LLM Generation
$messages = [
  ['role' => 'system', 'content' => 'Sei un assistente...'],
  ['role' => 'system', 'content' => "[CONTESTO RAG]\n$context"],
  ['role' => 'user', 'content' => 'chi è il sindaco?']
];

$response = $openai->chat([
  'model' => 'gpt-4o-mini',
  'messages' => $messages,
  'temperature' => 0.3
]);

// 12. Response
$answer = "Il sindaco di San Cesareo è Alessandra Sabelli.";
```

#### Timing Breakdown

```
Total: 2,345 ms
├─ Query normalization: 5 ms
├─ Synonym expansion: 12 ms
├─ KB selection: 45 ms
├─ Milvus search: 234 ms
├─ BM25 search: 123 ms
├─ RRF fusion: 18 ms
├─ Reranking: 45 ms
├─ MMR: 28 ms
├─ Context building: 67 ms
└─ LLM generation: 1,234 ms
```

## Configuration

### Tenant RAG Settings (JSON)

```json
{
  "multi_kb_search": {
    "enabled": true,
    "max_kbs": 3,
    "threshold": 0.3
  },
  "reranking": {
    "driver": "embedding",
    "top_n": 10
  },
  "mmr": {
    "enabled": true,
    "lambda": 0.7,
    "top_n": 5
  },
  "hyde": {
    "enabled": false
  },
  "synonyms": {
    "sindaco": ["primo cittadino", "mayor"],
    "ufficio": ["sportello", "servizio"]
  }
}
```

### Global RAG Config (.env)

```env
RAG_MILVUS_TOP_K=50
RAG_BM25_LIMIT=50
RAG_RRF_K=60
RAG_MAX_CONTEXT_TOKENS=4000
RAG_CACHE_TTL=3600
```

## Troubleshooting

### Problema: Risultati non rilevanti

**Sintomo**: LLM risponde "Non ho informazioni"

**Debug**:
```php
// Check profiling logs
Log::info('RAG profiling', [
  'milvus_results' => count($milvusResults),
  'bm25_results' => count($bm25Results),
  'rrf_top_score' => $rrfResults[0]['score'] ?? 0,
  'reranked_top_score' => $reranked[0]['score'] ?? 0
]);

// Verifica chunks retrieved
foreach ($finalChunks as $chunk) {
  Log::debug('Chunk used', [
    'id' => $chunk['chunk_id'],
    'preview' => substr($chunk['content'], 0, 100)
  ]);
}
```

**Soluzioni**:
1. Disable reranking temporaneamente: `'driver' => 'none'`
2. Aumenta top_k Milvus: `RAG_MILVUS_TOP_K=100`
3. Check synonym expansion: aggiungi sinonimi tenant-specific
4. Verifica chunks esistono per quella query (admin panel)

### Problema: Reranker penalizza chunk rilevanti

**Sintomo**: Chunk corretto rank basso dopo reranking

**Soluzione**:
```json
// Usa 'none' reranker per debug
{
  "reranking": {
    "driver": "none"
  }
}

// Oppure usa LLM reranker (più lento ma più accurato)
{
  "reranking": {
    "driver": "llm",
    "model": "gpt-4o-mini"
  }
}
```

### Problema: Multi-KB selection sbagliata

**Sintomo**: Seleziona KB irrilevante per query

**Debug**:
```php
Log::info('KB selection', [
  'query' => $query,
  'selected_kbs' => $selectedKbs,
  'bm25_scores' => $bm25Scores
]);
```

**Soluzioni**:
1. Disabilita multi-KB: `'enabled' => false`
2. Aumenta threshold: `'threshold' => 0.5`
3. Manual KB selection nel widget (user sceglie)

## Best Practices

1. **Cache aggressivo**: 1 ora TTL, invalida su document update
2. **Profiling sempre on**: Log timing di ogni step
3. **Reranking leggero**: Default `embedding` (fast), `llm` solo se necessario
4. **Multi-KB con cautela**: Solo se tenant ha >3 KB ben separate
5. **Synonym expansion**: Mantieni lista corta (<5 synonyms per term)
6. **HyDE solo per query ambigue**: Ha costo +500ms
7. **MMR lambda=0.7**: Good balance relevance/diversity
8. **Context max 4000 tokens**: Evita context truncation

## Metriche KPI

### Target Performance
- **Latenza P95**: <2.5s
- **Cache hit rate**: >40%
- **Groundedness**: ≥0.8 (% risposte basate su context)
- **Hallucination rate**: <2%

### Monitoring
```php
// Log per ogni query
Log::info('RAG query completed', [
  'tenant_id' => $tenantId,
  'query' => $query,
  'total_ms' => $totalMs,
  'chunks_used' => count($chunks),
  'cache_hit' => $cacheHit,
  'tokens_used' => $usage['total_tokens']
]);
```

## Prossimi Step

1. → **[04-WIDGET-INTEGRATION-FLOW.md](04-WIDGET-INTEGRATION-FLOW.md)** - Frontend integration
2. → **[06-QUEUE-WORKERS.md](06-QUEUE-WORKERS.md)** - Background processing

