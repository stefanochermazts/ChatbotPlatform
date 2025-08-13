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
        'max_chars' => 1500,
        'overlap_chars' => 200,
    ],

    // Configurazione adapter indice vettoriale (pluggable)
    'vector' => [
        // Driver: milvus | qdrant | weaviate | pinecone | redis | elasticsearch | opensearch | meilisearch | typesense | null
        'driver' => env('RAG_VECTOR_DRIVER', 'milvus'),

        // Parametri comuni
        'metric' => env('RAG_VECTOR_METRIC', 'cosine'), // cosine | dot | l2
        'top_k' => (int) env('RAG_VECTOR_TOP_K', 20),
        'mmr_lambda' => (float) env('RAG_VECTOR_MMR_LAMBDA', 0.3),

        // Milvus/Zilliz
        'milvus' => [
            'host' => env('MILVUS_HOST', '127.0.0.1'),
            'port' => (int) env('MILVUS_PORT', 19530),
            'uri' => env('MILVUS_URI'), // per Zilliz Cloud
            'token' => env('MILVUS_TOKEN'),
            'tls' => filter_var(env('MILVUS_TLS', false), FILTER_VALIDATE_BOOLEAN),
            'collection' => env('MILVUS_COLLECTION', 'kb_chunks_v1'),
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
        'vector_top_k' => (int) env('RAG_VECTOR_TOP_K', 40),
        'bm25_top_k'   => (int) env('RAG_BM25_TOP_K', 80),
        'rrf_k'        => (int) env('RAG_RRF_K', 60),
        'mmr_lambda'   => (float) env('RAG_MMR_LAMBDA', 0.25),
        'mmr_take'     => (int) env('RAG_MMR_TAKE', 10),
        'neighbor_radius' => (int) env('RAG_NEIGHBOR_RADIUS', 2),
    ],

    // Multi-query expansion (parafrasi della query utente)
    'multiquery' => [
        'enabled' => filter_var(env('RAG_MQ_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'num'     => (int) env('RAG_MQ_NUM', 3), // quante parafrasi oltre all'originale
        'model'   => env('RAG_MQ_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('RAG_MQ_TEMPERATURE', 0.3),
        'max_tokens'  => (int) env('RAG_MQ_MAX_TOKENS', 200),
    ],

    // Fallback “Non lo so” basato su confidenza e citazioni
    'answer' => [
        'min_citations'    => (int) env('RAG_MIN_CITATIONS', 1),
        'min_confidence'   => (float) env('RAG_MIN_CONFIDENCE', 0.08),
        'force_if_has_citations' => filter_var(env('RAG_FORCE_IF_HAS_CITATIONS', true), FILTER_VALIDATE_BOOLEAN),
        'fallback_message' => env('RAG_FALLBACK_MESSAGE', 'Non lo so con certezza: non trovo riferimenti sufficienti nella base di conoscenza.'),
    ],

    // Reranker cross-encoder (pluggable)
    'reranker' => [
        // Driver: embedding | cohere | none
        'driver' => env('RAG_RERANK_DRIVER', 'embedding'),
        'top_n'  => (int) env('RAG_RERANK_TOP_N', 40),

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
        'max_chars' => (int) env('RAG_CTX_MAX_CHARS', 6000),
        'compress_if_over_chars' => (int) env('RAG_CTX_COMPRESS_IF_OVER', 700),
        'compress_target_chars' => (int) env('RAG_CTX_COMPRESS_TARGET', 350),
        'model' => env('RAG_CTX_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('RAG_CTX_TEMPERATURE', 0.1),
    ],

    // Feature flags & cache/telemetria
    'features' => [
        'hybrid'     => filter_var(env('RAG_FEAT_HYBRID', true), FILTER_VALIDATE_BOOLEAN),
        'reranker'   => filter_var(env('RAG_FEAT_RERANKER', true), FILTER_VALIDATE_BOOLEAN),
        'multiquery' => filter_var(env('RAG_FEAT_MULTIQUERY', true), FILTER_VALIDATE_BOOLEAN),
        'context'    => filter_var(env('RAG_FEAT_CONTEXT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'cache' => [
        'enabled' => filter_var(env('RAG_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_seconds' => (int) env('RAG_CACHE_TTL', 120),
    ],

    'telemetry' => [
        'enabled' => filter_var(env('RAG_TELEMETRY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];




