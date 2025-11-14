<?php

/**
 * Default RAG Settings Template for Tenants
 * Questi parametri possono essere personalizzati per ogni tenant
 * nella colonna tenants.rag_settings (JSON)
 */

return [
    // Parametri Hybrid Search (⚡ Optimized for Performance)
    'hybrid' => [
        'vector_top_k' => 25,      // ⚡ Ridotto da 40 per performance
        'bm25_top_k' => 40,        // ⚡ Ridotto da 80 per performance
        'rrf_k' => 60,             // Parametro fusion RRF
        'mmr_lambda' => 0.25,      // Balance rilevanza/diversità (0-1)
        'mmr_take' => 8,           // ⚡ Ridotto da 10 per MMR performance
        'neighbor_radius' => 2,    // Chunk adiacenti da includere
    ],

    // Multi-Query Expansion
    'multiquery' => [
        'enabled' => true,
        'num' => 3,                // Numero query parallele generate
        'temperature' => 0.3,      // Creatività LLM per parafrasi
    ],

    // Thresholds e Fallbacks
    'answer' => [
        'min_citations' => 1,      // Citazioni minime per risposta
        'min_confidence' => 0.08,  // Soglia confidence (0-1)
        'force_if_has_citations' => true,
        'fallback_message' => 'Non lo so con certezza: non trovo riferimenti sufficienti nella base di conoscenza.',
    ],

    // Reranking Strategy
    'reranker' => [
        'driver' => 'embedding',   // embedding | cohere | llm | none
        'top_n' => 25,            // ⚡ Ridotto da 40 per performance
    ],

    // Context Building
    'context' => [
        'max_chars' => 6000,      // Limite caratteri contesto
        'compress_if_over_chars' => 7000,
        'compress_target_chars' => 3500,
    ],

    // Tecniche RAG Avanzate
    'advanced' => [
        'hyde' => [
            'enabled' => false,   // HyDE per query complesse
            'weight_original' => 0.6,
            'weight_hypothetical' => 0.4,
        ],
        'llm_reranker' => [
            'enabled' => false,   // LLM-as-a-Judge reranking
            'batch_size' => 5,
        ],
    ],

    // Intent Detection Personalizzato
    'intents' => [
        'enabled' => [
            'thanks' => true,
            'phone' => true,
            'email' => true,
            'address' => true,
            'schedule' => true,
        ],
        'min_score' => 0.5,       // Soglia minima intent
        'execution_strategy' => 'priority_based',  // priority_based | first_match
    ],

    // Knowledge Base Selection Strategy
    'kb_selection' => [
        'mode' => 'auto',                 // auto | strict | multi
        'bm25_boost_factor' => 1.0,       // Moltiplicatore per score BM25 (>1 favorisce più documenti)
        'vector_boost_factor' => 1.0,     // Moltiplicatore per score vector (futuro)
    ],

    // Configurazioni Avanzate per Tipologia Cliente
    'profiles' => [
        // Profilo PA/Enti Pubblici (⚡ Performance Optimized)
        'public_administration' => [
            'hybrid.vector_top_k' => 30,    // ⚡ Ridotto da 50
            'hybrid.bm25_top_k' => 50,      // ⚡ Ridotto da 100
            'hybrid.mmr_lambda' => 0.3,     // Più diversità
            'hybrid.mmr_take' => 6,         // ⚡ Ridotto per performance
            'answer.min_confidence' => 0.12, // Soglia più alta
        ],
        // Profilo E-commerce (⚡ Performance Optimized)
        'ecommerce' => [
            'hybrid.vector_top_k' => 20,    // ⚡ Ridotto da 30
            'hybrid.bm25_top_k' => 30,      // ⚡ Aggiunto limite BM25
            'hybrid.mmr_lambda' => 0.2,     // Meno diversità, più focus
            'hybrid.mmr_take' => 6,         // ⚡ Ridotto per performance
            'multiquery.num' => 2,          // Meno parafrasi
        ],
        // Profilo FAQ/Customer Service
        'customer_service' => [
            'reranker.driver' => 'cohere',
            'answer.min_confidence' => 0.15,
            'advanced.llm_reranker.enabled' => true,
        ],
    ],

    // Configurazione Widget/API Performance
    'widget' => [
        'max_tokens' => 800,           // Limite tokens LLM
        'max_context_chars' => 15000,  // Limite contesto totale (15KB)
        'max_citation_chars' => 2000,  // Limite per singola citazione
        'enable_context_truncation' => true, // Abilita troncamento per performance
        'model' => 'gpt-4o-mini',      // Modello ottimizzato per velocità
        'temperature' => 0.2,          // Temperatura per consistenza
        'timeout_seconds' => 30,       // Timeout chiamate OpenAI
    ],
];
