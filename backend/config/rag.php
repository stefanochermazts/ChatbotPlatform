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
];




