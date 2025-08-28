<?php

$model = env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small');
$dim = match ($model) {
    'text-embedding-3-large' => 3072,
    default => 1536,
};

return [
    'embedding_model' => $model,
    'embedding_dim' => (int) env('OPENAI_EMBEDDING_DIM', $dim),

    // Parametri di chunking testo
    'chunk' => [
        'max_chars' => (int) env('RAG_CHUNK_MAX_CHARS', 2200), // 🔧 Aumentato per tabelle
        'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 250), // 🔧 Aumentato overlap
    ],

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
    'hybrid' => [
        'vector_top_k' => (int) env('RAG_VECTOR_TOP_K', 100), // 🔧 MASSIMO per recuperare tutti i chunk
        'bm25_top_k'   => (int) env('RAG_BM25_TOP_K', 150), // 🔧 MASSIMO per BM25
        'rrf_k'        => (int) env('RAG_RRF_K', 60),
        'mmr_lambda'   => (float) env('RAG_MMR_LAMBDA', 0.1), // 🔧 Ridotto per più diversità
        'mmr_take'     => (int) env('RAG_MMR_TAKE', 50), // 🔧 MASSIMO per tutti i risultati
        'neighbor_radius' => (int) env('RAG_NEIGHBOR_RADIUS', 5), // 🔧 Aumentato per più contesto
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
        'driver' => env('RAG_RERANK_DRIVER', 'llm'), // 🔧 Cambiato da embedding a llm per miglior ranking
        'top_n'  => (int) env('RAG_RERANK_TOP_N', 100), // 🔧 MASSIMO per processare tutto

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
];




