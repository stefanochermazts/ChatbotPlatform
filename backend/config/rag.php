<?php

$model = env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small');
$dim = match ($model) {
    'text-embedding-3-large' => 3072,
    default => 1536,
};

return [
    'embedding_model' => $model,
    'embedding_dim' => (int) env('OPENAI_EMBEDDING_DIM', $dim),

    // ✅ Parametri di chunking testo (flat keys for TenantRagConfigService consistency)
    // Naming: chunk_max_chars (not chunk.max_chars) to match service expectations
    'chunk_max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200), // 🔧 Aumentato per tabelle
    'chunk_overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250), // 🔧 Aumentato overlap

    // Configurazione adapter indice vettoriale (pluggable)
    'vector' => [
        // Driver: milvus | qdrant | weaviate | pinecone | redis | elasticsearch | opensearch | meilisearch | typesense | null
        'driver' => env('RAG_VECTOR_DRIVER', 'milvus'),

            // Parametri comuni
    'metric' => env('RAG_VECTOR_METRIC', 'cosine'), // cosine | dot | l2
    'top_k' => (int) env('RAG_VECTOR_TOP_K', 100), // 🔧 MASSIMO per recuperare tutto
    'mmr_lambda' => (float) env('RAG_VECTOR_MMR_LAMBDA', 0.3),

        // Milvus/Zilliz
        'milvus' => [
            'host' => env('MILVUS_HOST', '127.0.0.1'),
            'port' => (int) env('MILVUS_PORT', 19530),
            'uri' => env('MILVUS_URI'), // per Zilliz Cloud
            'token' => env('MILVUS_TOKEN'),
            'tls' => filter_var(env('MILVUS_TLS', false), FILTER_VALIDATE_BOOLEAN),
            'collection' => env('MILVUS_COLLECTION', 'kb_chunks_v1'),
            'python_path' => env('MILVUS_PYTHON_PATH', 'python'), // Percorso completo a python.exe per Windows
            // Abilita/disabilita la creazione automatica di partizioni per tenant
            // Su Windows può causare problemi con grpcio, impostare a false se necessario
            'partitions_enabled' => filter_var(env('MILVUS_PARTITIONS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'index' => [
                'type' => env('MILVUS_INDEX_TYPE', 'HNSW'), // HNSW | IVF_FLAT | IVF_SQ8 | AUTOINDEX
                'params' => [
                    'M' => (int) env('MILVUS_HNSW_M', 16),
                    'efConstruction' => (int) env('MILVUS_HNSW_EF_CONSTRUCTION', 200),
                ],
                'search_params' => [
                    'ef' => (int) env('MILVUS_HNSW_EF', 96),
                ],
            ],
        ],
    ],

    // Retrieval ibrido e ranking
    // ✅ FIXED: No env() calls - use only hardcoded defaults
    // Tenants can override via rag_settings.hybrid.* JSON field
    // TenantRagConfigService::getRetrievalConfig() is the SINGLE SOURCE OF TRUTH
    'hybrid' => [
        'vector_top_k'    => 100, // Numero max di risultati vector search (1-1000)
        'bm25_top_k'      => 30,  // Numero max di risultati BM25 text search (1-1000)
        'rrf_k'           => 60,  // Parametro K per Reciprocal Rank Fusion (1-100)
        'mmr_lambda'      => 0.1, // Peso diversità vs rilevanza in MMR (0.0-1.0, lower = more diversity)
        'mmr_take'        => 50,  // Numero chunk da passare a MMR reranking (1-200)
        'neighbor_radius' => 5,   // Raggio per neighbor expansion (0-20)
    ],

    // Multi-query expansion (parafrasi della query utente)
    'multiquery' => [
        'enabled' => filter_var(env('RAG_MQ_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'num'     => (int) env('RAG_MQ_NUM', 5), // 🔧 Più parafrasi per recuperare più chunk
        'model'   => env('RAG_MQ_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('RAG_MQ_TEMPERATURE', 0.3),
        'max_tokens'  => (int) env('RAG_MQ_MAX_TOKENS', 200),
    ],

    // Fallback "Non lo so" basato su confidenza e citazioni
    'answer' => [
        'min_citations'    => (int) env('RAG_MIN_CITATIONS', 1),
        'min_confidence'   => (float) env('RAG_MIN_CONFIDENCE', 0.05), // 🔧 Abbassato da 0.08 a 0.05
        'force_if_has_citations' => filter_var(env('RAG_FORCE_IF_HAS_CITATIONS', true), FILTER_VALIDATE_BOOLEAN),
        'fallback_message' => env('RAG_FALLBACK_MESSAGE', 'Non lo so con certezza: non trovo riferimenti sufficienti nella base di conoscenza.'),
    ],

    // Reranker cross-encoder (pluggable)
    'reranker' => [
        // Driver: embedding | cohere | llm
        'driver' => env('RAG_RERANK_DRIVER', 'embedding'), // 🔧 Embedding per velocità (widget), llm per accuratezza (admin)
        'top_n'  => (int) env('RAG_RERANK_TOP_N', 100), // 🔧 AUMENTATO per includere più chunks

        // Cohere
        'cohere' => [
            'api_key' => env('COHERE_API_KEY', ''),
            'model'   => env('COHERE_RERANK_MODEL', 'rerank-english-v3.0'),
            'endpoint'=> env('COHERE_RERANK_ENDPOINT', 'https://api.cohere.com/v1/rerank'),
        ],
    ],

    // Context builder: packing token-aware con compressione e dedup
    'context' => [
        'enabled' => filter_var(env('RAG_CTX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Limite approssimato lato server (caratteri) per prevenire request troppo grandi
        'max_chars' => (int) env('RAG_CTX_MAX_CHARS', 12000), // 🔧 Raddoppiato per più contenuto
        'compress_if_over_chars' => (int) env('RAG_CTX_COMPRESS_IF_OVER', 1400), // 🔧 Raddoppiato
        'compress_target_chars' => (int) env('RAG_CTX_COMPRESS_TARGET', 700), // 🔧 Raddoppiato
        'model' => env('RAG_CTX_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('RAG_CTX_TEMPERATURE', 0.1),
    ],

    // Feature flags & cache/telemetria
    'features' => [
        'hybrid'     => filter_var(env('RAG_FEAT_HYBRID', true), FILTER_VALIDATE_BOOLEAN),
        'reranker'   => filter_var(env('RAG_FEAT_RERANKER', true), FILTER_VALIDATE_BOOLEAN),
        'multiquery' => filter_var(env('RAG_FEAT_MULTIQUERY', true), FILTER_VALIDATE_BOOLEAN),
        'context'    => filter_var(env('RAG_FEAT_CONTEXT', true), FILTER_VALIDATE_BOOLEAN),
        // Espansione informazioni di contatto (address/phone/email/schedule) – FORZATO ATTIVO
        'contact_expansion' => true, // filter_var(env('RAG_CONTACT_EXPANSION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'cache' => [
        'enabled' => filter_var(env('RAG_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_seconds' => (int) env('RAG_CACHE_TTL', 120),
    ],

    'telemetry' => [
        'enabled' => filter_var(env('RAG_TELEMETRY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Tecniche RAG avanzate
    'advanced' => [
        // HyDE (Hypothetical Document Embeddings)
        'hyde' => [
            'enabled' => filter_var(env('RAG_HYDE_ENABLED', true), FILTER_VALIDATE_BOOLEAN), // 🔧 Riabilitato per query generiche
            'model' => env('RAG_HYDE_MODEL', 'gpt-4o-mini'),
            'max_tokens' => (int) env('RAG_HYDE_MAX_TOKENS', 200),
            'temperature' => (float) env('RAG_HYDE_TEMPERATURE', 0.3),
            'weight_original' => (float) env('RAG_HYDE_WEIGHT_ORIG', 0.6),
            'weight_hypothetical' => (float) env('RAG_HYDE_WEIGHT_HYPO', 0.4),
        ],
        
        // LLM Reranker (LLM-as-a-Judge)
        'llm_reranker' => [
            'enabled' => filter_var(env('RAG_LLM_RERANK_ENABLED', true), FILTER_VALIDATE_BOOLEAN), // 🔧 Abilitato per miglior ranking
            'model' => env('RAG_LLM_RERANK_MODEL', 'gpt-4o-mini'),
            'batch_size' => (int) env('RAG_LLM_RERANK_BATCH_SIZE', 10), // 🔧 Aumentato per processare più chunk
            'max_tokens' => (int) env('RAG_LLM_RERANK_MAX_TOKENS', 50),
            'temperature' => (float) env('RAG_LLM_RERANK_TEMPERATURE', 0.1),
        ],
        
        // Query Decomposition (futuro)
        'query_decomposition' => [
            'enabled' => filter_var(env('RAG_QUERY_DECOMP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'max_subqueries' => (int) env('RAG_QUERY_DECOMP_MAX_SUB', 5),
            'complexity_threshold' => (int) env('RAG_QUERY_DECOMP_THRESHOLD', 50),
        ],
    ],
    
    // Gestione contesto conversazionale
    'conversation' => [
        'enabled' => filter_var(env('RAG_CONVERSATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN), // 🔧 Sempre abilitata
        'max_history_messages' => (int) env('RAG_CONVERSATION_MAX_HISTORY', 10),
        'max_summary_length' => (int) env('RAG_CONVERSATION_MAX_SUMMARY', 300),
        'max_context_in_query' => (int) env('RAG_CONVERSATION_MAX_CONTEXT_QUERY', 200),
        'summary_model' => env('RAG_CONVERSATION_SUMMARY_MODEL', 'gpt-4o-mini'),
        'require_min_messages' => (int) env('RAG_CONVERSATION_MIN_MESSAGES', 2),
    ],
    
    // Configurazione specifica per Widget/API Chat Completions
    'widget' => [
        // Parametri di ottimizzazione performance
        'max_tokens' => (int) env('RAG_WIDGET_MAX_TOKENS', 800), // Limite tokens LLM
        'max_context_chars' => (int) env('RAG_WIDGET_MAX_CONTEXT_CHARS', 15000), // Limite contesto totale (15KB)
        'max_citation_chars' => (int) env('RAG_WIDGET_MAX_CITATION_CHARS', 2000), // Limite per singola citazione
        'enable_context_truncation' => filter_var(env('RAG_WIDGET_ENABLE_TRUNCATION', true), FILTER_VALIDATE_BOOLEAN), // Abilita troncamento
        
        // Parametri modello LLM
        'model' => env('RAG_WIDGET_MODEL', 'gpt-4o-mini'), // Modello per risposte widget
        'temperature' => (float) env('RAG_WIDGET_TEMPERATURE', 0.2), // Temperatura per consistenza
        'timeout_seconds' => (int) env('RAG_WIDGET_TIMEOUT', 30), // Timeout chiamate OpenAI
    ],
    
    // 🎯 Citation Scoring Configuration
    // Multi-dimensional scoring for ranking RAG citations
    'scoring' => [
        // Minimum composite score threshold for filtering (0.0-1.0)
        'min_confidence' => (float) env('RAG_SCORING_MIN_CONFIDENCE', 0.30),
        
        // Dimension weights (must sum to ~1.0)
        'weights' => [
            'source' => (float) env('RAG_SCORING_WEIGHT_SOURCE', 0.20),        // Source quality (file type, domain, freshness)
            'quality' => (float) env('RAG_SCORING_WEIGHT_QUALITY', 0.30),      // Content quality (length, structure)
            'authority' => (float) env('RAG_SCORING_WEIGHT_AUTHORITY', 0.25),  // Authority (official docs boost)
            'intent_match' => (float) env('RAG_SCORING_WEIGHT_INTENT', 0.25),  // Intent-specific field presence
        ],
    ],

];




