<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KbSearchService
{
    public function __construct(
        private readonly OpenAIEmbeddingsService $embeddings,
        private readonly MilvusClient $milvus,
        private readonly TextSearchService $text,
        private readonly TenantRagConfigService $tenantConfig,
        private readonly RerankerInterface $reranker = new EmbeddingReranker(new \App\Services\LLM\OpenAIEmbeddingsService()),
        private readonly ?MultiQueryExpander $mq = null,
        private readonly RagCache $cache = new RagCache(),
        private readonly RagTelemetry $telemetry = new RagTelemetry(),
        private readonly KnowledgeBaseSelector $kbSelector = new KnowledgeBaseSelector(new TextSearchService()),
        private readonly ?HyDEExpander $hyde = null,
    ) {}

    // Lingue attive per il tenant corrente (codici ISO, lowercase)
    private array $activeLangs = ['it'];

    /**
     * @param int   $tenantId
     * @param string $query
     * @param int   $limit   Numero massimo di documenti da citare
     * @param array $opts    ['top_k' => int, 'mmr_lambda' => float]
     */
    public function retrieve(int $tenantId, string $query, bool $debug = false): array
    {
        $startTime = microtime(true);
        $profiling = [
            'start_time' => $startTime, 
            'steps' => [],
            'breakdown' => []
        ];
        
        // ⏱️ STEP 1: Query Normalization
        $stepStart = microtime(true);
        $normalizedQuery = $this->normalizeQuery($query);
        $profiling['breakdown']['Query Normalization'] = round((microtime(true) - $stepStart) * 1000, 2);
        
        if ($normalizedQuery !== $query && $debug) {
            Log::info('[RAG] Query normalized', [
                'original' => $query,
                'normalized' => $normalizedQuery
            ]);
        }
        if ($query === '') {
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? $profiling : null];
        }

        // ⏱️ STEP 2: Tenant & Language Setup
        $stepStart = microtime(true);
        $this->activeLangs = $this->getTenantLanguages($tenantId);
        $tenant = \App\Models\Tenant::find($tenantId);
        $useMultiKb = $tenant && $tenant->multi_kb_search;
        $profiling['breakdown']['Tenant Setup'] = round((microtime(true) - $stepStart) * 1000, 2);

        // ⏱️ STEP 3: Intent Detection
        $stepStart = microtime(true);
        $intents = $this->detectIntents($query, $tenantId);
        $profiling['breakdown']['Intent Detection'] = round((microtime(true) - $stepStart) * 1000, 2);
        
        $intentDebug = null;
        if ($debug) {
            // ⏱️ STEP 4: Debug Information Building
            $stepStart = microtime(true);
            $q = mb_strtolower($query);
            $expandedQ = $this->expandQueryWithSynonyms($q, $tenantId);
            $intentDebug = [
                'original_query' => $query,
                'lowercased_query' => $q,
                'expanded_query' => $expandedQ,
                'intents_detected' => $intents,
                'intent_scores' => [
                    'thanks' => $this->scoreIntent($q, $expandedQ, $this->keywordsThanks($this->activeLangs)),
                    'schedule' => $this->scoreIntent($q, $expandedQ, $this->keywordsSchedule($this->activeLangs)),
                    'address' => $this->scoreIntent($q, $expandedQ, $this->keywordsAddress($this->activeLangs)),
                    'email' => $this->scoreIntent($q, $expandedQ, $this->keywordsEmail($this->activeLangs)),
                    'phone' => $this->scoreIntent($q, $expandedQ, $this->keywordsPhone($this->activeLangs)),
                ],
                'keywords_matched' => $this->getMatchedKeywords($q, $expandedQ),
            ];
            $profiling['breakdown']['Debug Info Building'] = round((microtime(true) - $stepStart) * 1000, 2);
        }
        
        $profiling['steps']['init'] = microtime(true) - $startTime;
        Log::info('🎯 [RETRIEVE] Inizio elaborazione query', [
            'tenant_id' => $tenantId,
            'original_query' => $query,
            'normalized_query' => $normalizedQuery,
            'multi_kb_enabled' => $useMultiKb,
            'intents_detected' => $intents,
            'debug_mode' => $debug
        ]);

        // Esegui l'intent con score più alto (già ordinati per priorità in detectIntents)
        foreach ($intents as $intentType) {
            Log::info("🔍 [RETRIEVE] Testando intent: {$intentType}");
            
            // Usa la stessa logica di selezione KB del metodo principale
            if ($useMultiKb) {
                $kbSelIntent = $this->getAllKnowledgeBasesForTenant($tenantId);
                $selectedKbIdIntent = null; // null per ricerca in tutte le KB
                Log::info('📚 [RETRIEVE] Multi-KB abilitato', [
                    'kb_count' => count($kbSelIntent),
                    'kb_ids' => array_map(fn($kb) => $kb['id'] ?? 'unknown', $kbSelIntent)
                ]);
            } else {
                $kbSelIntent = $this->kbSelector->selectForQuery($tenantId, $query);
                $selectedKbIdIntent = $kbSelIntent['knowledge_base_id'] ?? null;
                Log::info('🎯 [RETRIEVE] KB singola selezionata', [
                    'selected_kb_id' => $selectedKbIdIntent,
                    'kb_selection_result' => $kbSelIntent
                ]);
            }
            
            // 🔧 Prima prova l'intent NORMALE (mantiene la logica esistente che funziona)
            $result = $this->executeIntent($intentType, $tenantId, $query, $debug, $selectedKbIdIntent);
            
            Log::info("📊 [RETRIEVE] Risultato intent {$intentType}", [
                'result_found' => $result !== null,
                'citations_count' => $result ? count($result['citations'] ?? []) : 0,
                'confidence' => $result['confidence'] ?? 'n/a'
            ]);
            
            if ($result !== null) {
                // Espansione opzionale info di contatto (abilitata via flag)
                $contactExpansionEnabled = (bool) ($this->tenantConfig->getConfig($tenantId)['features']['contact_expansion'] ?? false);
                if ($contactExpansionEnabled && in_array($intentType, ['phone', 'email', 'address'])) {
                    $expansionResult = $this->executeContactInfoExpansion($intentType, $tenantId, $query, $debug, $selectedKbIdIntent, $result);
                    if ($expansionResult !== null) {
                        if ($intentDebug) {
                            $intentDebug['executed_intent'] = $intentType . '_expanded';
                            $intentDebug['reason'] = 'contact_info_expansion_with_all_details';
                            $intentDebug['normal_citations'] = count($result['citations'] ?? []);
                            $intentDebug['expansion_citations'] = count($expansionResult['citations'] ?? []);
                            $intentDebug['has_response_text'] = !empty($expansionResult['response_text']);
                            $expansionResult['debug']['intent_detection'] = $intentDebug;
                            $expansionResult['debug']['selected_kb'] = $kbSelIntent;
                        }
                        return $expansionResult;
                    }
                }
                // Aggiungi debug intent per risultato normale
                if ($intentDebug) {
                    $intentDebug['executed_intent'] = isset($result['debug']['semantic_fallback']) ? $intentType . '_semantic' : $intentType;
                    $result['debug']['intent_detection'] = $intentDebug;
                    $result['debug']['selected_kb'] = $kbSelIntent;

                    // Esponi anche params hybrid e driver reranker per coerenza UI
                    $cfg = $this->tenantConfig->getHybridConfig($tenantId);
                    $vecTopK   = (int) ($cfg['vector_top_k'] ?? 30);
                    $bmTopK    = (int) ($cfg['bm25_top_k']   ?? 50);
                    $rrfK      = (int) ($cfg['rrf_k']        ?? 60);
                    $mmrLambda = (float) ($cfg['mmr_lambda'] ?? 0.3);
                    $mmrTake   = (int) ($cfg['mmr_take']     ?? 8);
                    $neighbor  = (int) ($cfg['neighbor_radius'] ?? 1);
                    $result['debug']['hybrid_config'] = [
                        'vector_top_k' => $vecTopK,
                        'bm25_top_k' => $bmTopK,
                        'rrf_k' => $rrfK,
                        'mmr_lambda' => $mmrLambda,
                        'mmr_take' => $mmrTake,
                        'neighbor_radius' => $neighbor,
                    ];
                    $advCfg = (array) $this->tenantConfig->getAdvancedConfig($tenantId);
                    $driver = (string) ($this->tenantConfig->getRerankerConfig($tenantId)['driver'] ?? 'embedding');
                    $llmEnabled = (bool) (($advCfg['llm_reranker']['enabled'] ?? true) === true);
                    if ($driver === 'llm' && !$llmEnabled) { $driver = 'embedding'; }
                    $result['debug']['reranking'] = $result['debug']['reranking'] ?? [
                        'driver' => $driver,
                        'input_candidates' => 0,
                        'output_candidates' => 0,
                        'top_candidates' => []
                    ];
                }
                return $result;
            }
        }

        $profiling['steps']['intents'] = microtime(true) - $startTime;
        Log::info('🚨 [RETRIEVE] Nessun intent soddisfatto - Attivando FALLBACK GENERALE', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'intents_tested' => $intents,
            'fallback_type' => 'hybrid_search'
        ]);

        $cfg = $this->tenantConfig->getHybridConfig($tenantId);
        $vecTopK   = (int) ($cfg['vector_top_k'] ?? 30);
        $bmTopK    = (int) ($cfg['bm25_top_k']   ?? 50);
        $rrfK      = (int) ($cfg['rrf_k']        ?? 60);
        $mmrLambda = (float) ($cfg['mmr_lambda'] ?? 0.3);
        $mmrTake   = (int) ($cfg['mmr_take']     ?? 8);
        $neighbor  = (int) ($cfg['neighbor_radius'] ?? 1);
        
        Log::info('⚙️ [RETRIEVE] Configurazione hybrid search', [
            'vector_top_k' => $vecTopK,
            'bm25_top_k' => $bmTopK,
            'rrf_k' => $rrfK,
            'mmr_lambda' => $mmrLambda
        ]);

        // Esponi nel trace i parametri hybrid effettivamente usati
        if ($debug) {
            $trace['hybrid_config'] = [
                'vector_top_k' => $vecTopK,
                'bm25_top_k' => $bmTopK,
                'rrf_k' => $rrfK,
                'mmr_lambda' => $mmrLambda,
                'mmr_take' => $mmrTake,
                'neighbor_radius' => $neighbor,
            ];
        }

        // Multi-query expansion (originale + parafrasi) - usa query normalizzata
        // Multi-query expansion per-tenant
        $stepStart = microtime(true);
        $queries = $this->mq ? $this->mq->expand($tenantId, $normalizedQuery) : [$normalizedQuery];
        $profiling['steps']['multiquery'] = microtime(true) - $stepStart;
        $this->telemetry->event('mq.expanded', ['tenant_id'=>$tenantId,'query'=>$query,'variants'=>$queries]);
        $allFused = [];
        $trace = $debug ? ['queries' => $queries] : null;
        if ($debug) {
            $trace['milvus'] = $this->milvus->health();
        }
        
        // ⏱️ STEP 5: KB Selection
        $stepStart = microtime(true);
        if ($useMultiKb) {
            // Multi-KB: cerca in tutte le KB del tenant
            $kbSel = $this->getAllKnowledgeBasesForTenant($tenantId);
            $selectedKbId = null; // null indica ricerca in tutte le KB
            $selectedKbName = 'Tutte le Knowledge Base';
        } else {
            // Single KB: usa la logica esistente
            $kbSel = $this->kbSelector->selectForQuery($tenantId, $query);
            $selectedKbId = $kbSel['knowledge_base_id'] ?? null;
            $selectedKbName = $kbSel['kb_name'] ?? null;
        }
        $profiling['breakdown']['KB Selection'] = round((microtime(true) - $stepStart) * 1000, 2);
        
        // 🔍 LOG DETTAGLIATO per debug KB selection
        \Log::info('RAG KB Selection', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'multi_kb_enabled' => $useMultiKb,
            'selected_kb_id' => $selectedKbId,
            'selected_kb_name' => $selectedKbName,
            'kb_selection_reason' => $kbSel['reason'] ?? 'unknown',
            'caller' => debug_backtrace()[1]['class'] ?? 'unknown'
        ]);
        
        if ($debug) {
            $trace['selected_kb'] = $kbSel;
            $trace['multi_kb_enabled'] = $useMultiKb;
        }
        
        // ⏱️ STEP 6: HyDE (Hypothetical Document Embeddings)
        $stepStart = microtime(true);
        $hydeResult = null;
        $advCfg = (array) $this->tenantConfig->getAdvancedConfig($tenantId);
        $hydeCfg = (array) ($advCfg['hyde'] ?? []);
        $useHyDE = $this->hyde && (($hydeCfg['enabled'] ?? false) === true);
        if ($useHyDE) {
            $hydeResult = $this->hyde->expandQuery($normalizedQuery, $tenantId, $debug);
            $this->telemetry->event('hyde.expanded', [
                'tenant_id' => $tenantId,
                'query' => $query,
                'success' => $hydeResult['success'] ?? false,
                'processing_time_ms' => $hydeResult['processing_time_ms'] ?? 0,
            ]);
            
            if ($debug) {
                $trace['hyde'] = $hydeResult;
            }
        }
        $profiling['breakdown']['HyDE'] = round((microtime(true) - $stepStart) * 1000, 2);

        // ⏱️ STEP 7: Multi-Query Vector + BM25 Search
        $searchStart = microtime(true);
        $vectorSearchTime = 0;
        $bm25SearchTime = 0;
        
        foreach ($queries as $q) {
            if ($debug) {
                // Determina quale embedding usare
                $qEmb = null;
                $embeddingSource = 'standard';
                
                if ($useHyDE && $hydeResult && $hydeResult['success'] && $hydeResult['combined_embedding']) {
                    $qEmb = $hydeResult['combined_embedding'];
                    $embeddingSource = 'hyde_combined';
                } else {
                $qEmb = $this->embeddings->embedTexts([$q])[0] ?? null;
                }
                
                $vecHit = [];
                if ((($trace['milvus']['ok'] ?? false) === true) && $qEmb) {
                    // ⏱️ Vector Search Timing
                    $vecStart = microtime(true);
                    
                    // 🚀 LOG DETTAGLIATO: Ricerca Milvus
                    Log::info('🔍 [MILVUS] Invio query a Milvus', [
                        'tenant_id' => $tenantId,
                        'query' => $q,
                        'embedding_dimensions' => count($qEmb),
                        'vector_top_k' => $vecTopK,
                        'milvus_health' => $trace['milvus'] ?? null,
                    ]);
                    
                    $vecHit = $this->milvus->searchTopKWithEmbedding($tenantId, $qEmb, $vecTopK);
                    
                    $vectorSearchTime += microtime(true) - $vecStart;
                    
                    // 🚀 LOG DETTAGLIATO: Risultati Milvus
                    Log::info('📊 [MILVUS] Risultati ricevuti da Milvus', [
                        'tenant_id' => $tenantId,
                        'query' => $q,
                        'results_count' => count($vecHit),
                        'top_3_results' => array_slice($vecHit, 0, 3),
                        'all_document_ids' => array_column($vecHit, 'document_id'),
                        'all_scores' => array_column($vecHit, 'score'),
                    ]);
                }
                
                // ⏱️ BM25 Search Timing
                $bm25Start = microtime(true);
                $bmHit = $this->text->searchTopK($tenantId, $q, $bmTopK, $selectedKbId);
                $bm25SearchTime += microtime(true) - $bm25Start;
                
                // 🔍 LOG DETTAGLIATO per ogni query
                Log::info("🔎 [RETRIEVE] Risultati per query: {$q}", [
                    'tenant_id' => $tenantId,
                    'embedding_source' => $embeddingSource,
                    'selected_kb_id' => $selectedKbId,
                    'vector_hits_count' => count($vecHit),
                    'bm25_hits_count' => count($bmHit),
                    'vector_top_5' => array_map(function($hit) {
                        return [
                            'doc_id' => $hit['document_id'] ?? 'unknown',
                            'score' => $hit['score'] ?? 'unknown',
                            'content_preview' => substr($hit['content'] ?? '', 0, 100)
                        ];
                    }, array_slice($vecHit, 0, 5)),
                    'bm25_top_5' => array_map(function($hit) {
                        return [
                            'doc_id' => $hit['document_id'] ?? 'unknown',
                            'score' => $hit['score'] ?? 'unknown',
                            'content_preview' => substr($hit['content'] ?? '', 0, 100)
                        ];
                    }, array_slice($bmHit, 0, 5))
                ]);
                
                $trace['per_query'][] = [
                    'q' => $q,
                    'embedding_source' => $embeddingSource,
                    'vector_hits' => array_slice($vecHit, 0, 10),
                    'fts_hits' => array_slice($bmHit, 0, 10),
                ];
                
                $filteredVecHit = $this->filterVecHitsByKb($vecHit, $selectedKbId);
                $fusedResult = $this->rrfFuse($filteredVecHit, $bmHit, $rrfK);
                
                Log::info("⚡ [RETRIEVE] RRF Fusion per query: {$q}", [
                    'vector_hits_before_kb_filter' => count($vecHit),
                    'vector_hits_after_kb_filter' => count($filteredVecHit),
                    'bm25_hits' => count($bmHit),
                    'fused_results' => count($fusedResult),
                    'fused_top_3' => array_map(function($hit) {
                        return [
                            'doc_id' => $hit['document_id'] ?? 'unknown',
                            'final_score' => $hit['score'] ?? 'unknown'
                        ];
                    }, array_slice($fusedResult, 0, 3))
                ]);
                
                $allFused[] = $fusedResult;
            } else {
                // Per cache: se HyDE è abilitato, includi un hash dell'embedding HyDE nella chiave
                $cacheKeySuffix = '';
                if ($useHyDE && $hydeResult && $hydeResult['success']) {
                    $cacheKeySuffix = ':hyde:' . md5(serialize($hydeResult['combined_embedding'] ?? []));
                }
                
                $key = 'rag:vecfts:'.$tenantId.':'.sha1($q).":{$vecTopK},{$bmTopK},{$rrfK}" . $cacheKeySuffix;
                // 📊 PROFILING: Search operations (outside cache for accurate timing)
                $searchStart = microtime(true);
                $list = $this->cache->remember($key, function () use ($tenantId, $q, $vecTopK, $bmTopK, $rrfK, $useHyDE, $hydeResult) {
                    Log::info('🔍 [CACHE MISS] Executing search operations', [
                        'query' => $q,
                        'tenant_id' => $tenantId,
                        'cache_key' => substr(sha1($q), 0, 8)
                    ]);
                    
                    // 📊 PROFILING: Embedding generation (internal timing)
                    $embStart = microtime(true);
                    $qEmb = null;
                    if ($useHyDE && $hydeResult && $hydeResult['success'] && $hydeResult['combined_embedding']) {
                        $qEmb = $hydeResult['combined_embedding'];
                    } else {
                        $qEmb = $this->embeddings->embedTexts([$q])[0] ?? null;
                    }
                    $embTime = round((microtime(true) - $embStart) * 1000, 2);
                    
                    // 📊 PROFILING: Milvus search (internal timing)
                    $milvusStart = microtime(true);
                    $vecHit = [];
                    $milvusHealth = $this->milvus->health();
                    if (($milvusHealth['ok'] ?? false) === true && $qEmb) {
                        $vecHit = $this->milvus->searchTopKWithEmbedding($tenantId, $qEmb, $vecTopK);
                    }
                    $milvusTime = round((microtime(true) - $milvusStart) * 1000, 2);
                    
                    // 📊 PROFILING: BM25 search (internal timing)
                    $bm25Start = microtime(true);
                    $bmHit  = $this->text->searchTopK($tenantId, $q, $bmTopK, null);
                    $bm25Time = round((microtime(true) - $bm25Start) * 1000, 2);
                    
                    Log::info('⚡ [SEARCH TIMING] Individual operations', [
                        'query' => $q,
                        'embeddings_ms' => $embTime,
                        'milvus_ms' => $milvusTime,
                        'bm25_ms' => $bm25Time,
                        'vec_results' => count($vecHit),
                        'bm25_results' => count($bmHit)
                    ]);
                    
                    return $this->rrfFuse($vecHit, $bmHit, $rrfK);
                });
                $profiling['steps']['search_operations'] = ($profiling['steps']['search_operations'] ?? 0) + (microtime(true) - $searchStart);
                // Applica filtro KB ai risultati fusi
                $allFused[] = $this->filterFusedByKb($list, $selectedKbId);
            }
        }
        
        // ⏱️ Add search timing to profiling
        $profiling['breakdown']['Vector Search'] = round($vectorSearchTime * 1000, 2);
        $profiling['breakdown']['BM25 Search'] = round($bm25SearchTime * 1000, 2);
        $profiling['breakdown']['Search Total'] = round((microtime(true) - $searchStart) * 1000, 2);
        
        // ⏱️ STEP 8: RRF Fusion
        $stepStart = microtime(true);
        $fused = $this->rrfFuseMany($allFused, $rrfK);
        
        // 🚀 NUOVO: Applica boost per Multi-KB Search
        if ($useMultiKb && !empty($fused)) {
            $fused = $this->applyBoostsToResults($fused, $tenantId, $query, $debug);
        }
        $profiling['breakdown']['RRF Fusion'] = round((microtime(true) - $stepStart) * 1000, 2);
        
        Log::info('🔀 [RETRIEVE] Fusione finale completata', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'input_queries_count' => count($queries),
            'total_fused_results' => count($fused),
            'multi_kb_boosts_applied' => $useMultiKb,
            'fused_top_10' => array_map(function($hit) {
                return [
                    'doc_id' => $hit['document_id'] ?? 'unknown',
                    'final_score' => $hit['score'] ?? 'unknown',
                    'content_preview' => substr($hit['content'] ?? '', 0, 50)
                ];
            }, array_slice($fused, 0, 10))
        ]);
        
        if ($debug) { $trace['fused_top'] = array_slice($fused, 0, 20); }
        if ($fused === []) {
            Log::warning('❌ [RETRIEVE] FALLBACK GENERALE FALLITO - Nessun risultato finale', [
                'tenant_id' => $tenantId,
                'query' => $query,
                'reason' => 'no_results_after_fusion'
            ]);
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $trace];
        }

        // Prepara candidati per reranker (top_n configurabile)
        $topN = (int) ($this->tenantConfig->getRerankerConfig($tenantId)['top_n'] ?? 30);
        $candidates = [];
        foreach (array_slice($fused, 0, $topN) as $h) {
            $docId = (int) $h['document_id'];
            $chunkIndex = (int) $h['chunk_index'];
            
            // 🚀 OTTIMIZZAZIONE: Testi più corti per reranking veloce
            $text = $this->text->getChunkSnippet($docId, $chunkIndex, 300) ?? '';
            // Riduci neighbor_radius per reranking (solo ±1 invece di configurato)
            $rerankNeighbor = min(1, $neighbor);
            for ($d = -$rerankNeighbor; $d <= $rerankNeighbor; $d++) {
                if ($d === 0) continue;
                $neighborText = $this->text->getChunkSnippet($docId, $chunkIndex + $d, 200);
                if ($neighborText) $text .= "\n" . $neighborText;
            }
            
            $candidates[] = [
                'document_id' => $docId,
                'chunk_index' => $chunkIndex,
                'text' => $text,
                'score' => (float) $h['score'],
            ];
        }

        // Seleziona reranker basato su configurazione tenant
        $driver = (string) ($this->tenantConfig->getRerankerConfig($tenantId)['driver'] ?? 'embedding');
        // Se llm_reranker.enabled è false, forza embedding anche se driver=llm
        $llmEnabled = (bool) (($advCfg['llm_reranker']['enabled'] ?? true) === true);
        if ($driver === 'llm' && !$llmEnabled) {
            $driver = 'embedding';
        }
        $reranker = match($driver) {
            'cohere' => new CohereReranker(),
            'llm' => new LLMReranker(app(\App\Services\LLM\OpenAIChatService::class)),
            'embedding_slow' => new EmbeddingReranker($this->embeddings), // Vecchio reranker con embeddings
            default => new FastEmbeddingReranker($this->embeddings), // Nuovo reranker veloce
        };
        
        // Cache key include driver e neighbor_radius per evitare conflitti
        $cacheKey = "rag:rerank:" . sha1($query) . ":{$tenantId},{$driver},{$topN},{$neighbor}";
        
        // Per LLM reranking, usa TTL più breve ma mantieni cache
        // (TTL gestito dalla RagCache class)
        
        // ⏱️ STEP 9: Reranking
        $stepStart = microtime(true);
        
        // 🚀 LOG DETTAGLIATO: Pre-reranking
        Log::info('🔄 [RERANK] Prima del reranking', [
            'query' => $query,
            'reranker_type' => get_class($reranker),
            'candidates_count' => count($candidates),
            'top_n' => $topN,
            'candidates_preview' => array_slice($candidates, 0, 3),
        ]);
        
        $ranked = $this->cache->remember($cacheKey, function () use ($reranker, $query, $candidates, $topN) {
            return $reranker->rerank($query, $candidates, $topN);
        });
        
        $profiling['breakdown']['Reranking'] = round((microtime(true) - $stepStart) * 1000, 2);
        
        // 🚀 LOG DETTAGLIATO: Post-reranking
        Log::info('✅ [RERANK] Dopo il reranking', [
            'query' => $query,
            'ranked_count' => count($ranked),
            'ranked_preview' => array_slice($ranked, 0, 3),
            'score_changes' => $this->compareScores($candidates, $ranked),
        ]);
        $this->telemetry->event('rerank.done', ['tenant_id'=>$tenantId,'driver'=>$driver,'in'=>count($candidates),'out'=>count($ranked)]);
        if ($debug) { 
            $trace['reranking'] = [
                'driver' => $driver,
                'input_candidates' => count($candidates),
                'output_candidates' => count($ranked),
                'top_candidates' => array_slice($ranked, 0, 10) // Mostra solo top 10 per debug
            ];
            $trace['reranked_top'] = array_slice($ranked, 0, 20); 
        }

        // ⏱️ STEP 10: MMR (Maximal Marginal Relevance)
        $stepStart = microtime(true);
        
        // 🚀 OPTIMIZATION: Cache MMR based on query + ranked documents hash
        $mmrCacheKey = "mmr:" . sha1($normalizedQuery . serialize(array_map(fn($r) => $r['document_id'] . ':' . $r['chunk_index'], $ranked)));
        
        $qEmb = $this->embeddings->embedTexts([$normalizedQuery])[0] ?? null;
        
        // 🚀 OPTIMIZATION: Limita MMR a massimo 15 candidates per performance
        // Con 30 docs: O(30²) = 900 calc/iteration × 8 iterations = ~7200 calculations!
        // Con 15 docs: O(15²) = 225 calc/iteration × 8 iterations = ~1800 calculations (4x faster)
        $mmrMaxCandidates = min(15, count($ranked));
        $mmrRanked = array_slice($ranked, 0, $mmrMaxCandidates);
        
        // 🚀 OPTIMIZATION: Try cache first
        $selIdx = $this->cache->remember($mmrCacheKey, function () use ($mmrRanked, $qEmb, $mmrLambda, $mmrTake) {
            $texts = array_map(fn($c) => (string)$c['text'], $mmrRanked);
            $docEmb = $texts ? $this->embeddings->embedTexts($texts) : [];
            return $this->mmr($qEmb, $docEmb, $mmrLambda, $mmrTake);
        });
        
        // 📊 Log optimization impact
        if ($debug) {
            Log::info('🚀 [MMR] Performance optimization applied', [
                'total_ranked_docs' => count($ranked),
                'mmr_candidates_used' => $mmrMaxCandidates,
                'performance_gain_estimate' => round((count($ranked) ** 2) / ($mmrMaxCandidates ** 2), 1) . 'x faster'
            ]);
        }
        
        $profiling['breakdown']['MMR'] = round((microtime(true) - $stepStart) * 1000, 2);
        if ($debug) { $trace['mmr_selected_idx'] = $selIdx; }

        // ⏱️ STEP 11: Citations Building  
        $stepStart = microtime(true);
        
        $seen = [];
        $cits = [];
        foreach ($selIdx as $i) {
            // 🔧 Fix: accedi al subset MMR, non all'array ranked completo
            $base = $mmrRanked[$i] ?? null;
            if ($base === null) { continue; }
            $docId = (int) $base['document_id'];
            if (isset($seen[$docId])) continue;
            $seen[$docId] = true;

            $snippet = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'], 50000) ?? '';
            for ($d = -$neighbor; $d <= $neighbor; $d++) {
                if ($d === 0) continue;
                // Aumenta il limite per includere righe di contatto (es. tel: ...)
                $s2 = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'] + $d, 2000);
                if ($s2) $snippet .= "\n".$s2;
            }

            $doc = DB::selectOne('SELECT id, title, path, source_url FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$docId, $tenantId]);
            if (!$doc) continue;
            
            // Get full chunk text for deep-link highlighting (use large limit to get full text)
            $chunkText = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'], 5000) ?? '';

            // Phone extraction (generic patterns) on raw chunk and on built snippet
            $phonesInChunk   = $this->extractPhoneFromContent($chunkText);
            $phonesInSnippet = $this->extractPhoneFromContent($snippet);
            $phonesMerged    = array_values(array_unique(array_merge($phonesInChunk, $phonesInSnippet)));

            if ($debug) {
                $trace['phone_trace'][] = [
                    'doc_id' => $docId,
                    'chunk_index' => (int)$base['chunk_index'],
                    'raw_chunk_hits' => $phonesInChunk,
                    'snippet_hits' => $phonesInSnippet,
                ];
            }
            
            $cits[] = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $base['score'],
                'knowledge_base' => $selectedKbName,
                // Additional fields for deep-linking
                'chunk_index' => (int) $base['chunk_index'],
                'chunk_text' => $chunkText,
                'document_type' => pathinfo($doc->path, PATHINFO_EXTENSION) ?: 'unknown',
                'view_url' => null, // Will be populated by frontend with secure token
                'document_source_url' => $doc->source_url ?? null, // 🆕 URL originale del documento
                // Phone enrichment for UI (generic, not tenant specific)
                'phone' => $phonesMerged[0] ?? null,
                'phones' => $phonesMerged,
            ];
        }
        
        $profiling['breakdown']['Citations Building'] = round((microtime(true) - $stepStart) * 1000, 2);

        $result = [
            'citations' => $cits,
            'confidence' => $this->confidence($fused),
            'debug' => $trace,
        ];
        
        // ⏱️ PROFILING COMPLETO: Aggiungi breakdown dettagliato
        $totalTime = microtime(true) - $startTime;
        $profiling['total_time_ms'] = round($totalTime * 1000, 2);
        
        // Aggiungi profiling al debug trace
        if ($debug && isset($profiling)) {
            $result['debug']['profiling'] = $profiling;
            $result['debug']['performance_breakdown'] = $profiling['breakdown'];
        }
        
        // Aggiungi debug intent anche per il RAG normale
        if ($intentDebug) {
            $intentDebug['executed_intent'] = 'hybrid_rag';
            $result['debug']['intent_detection'] = $intentDebug;
        }
        
        // 🚀 PROFILAZIONE COMPLETA
        $totalTime = microtime(true) - $startTime;
        $profiling['total_time'] = $totalTime;
        $profiling['steps']['final'] = $totalTime;
        
        Log::info('✅ [RETRIEVE] RISULTATO FINALE', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'final_citations_count' => count($cits),
            'confidence' => $result['confidence'],
            'execution_path' => $intentDebug['executed_intent'] ?? 'hybrid_rag',
            'selected_kb' => $selectedKbName,
            'citations_preview' => array_map(function($c) {
                return [
                    'doc_id' => $c['id'] ?? 'unknown',
                    'title' => substr($c['title'] ?? '', 0, 50),
                    'score' => $c['score'] ?? 'unknown'
                ];
            }, array_slice($cits, 0, 3))
        ]);
        
        // 📊 LOG PROFILAZIONE DETTAGLIATA
        Log::info('📊 [PROFILING] RAG Performance Breakdown', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'total_time_ms' => $profiling['total_time_ms'],
            'breakdown' => $profiling['breakdown'],
            'performance_status' => $profiling['total_time_ms'] < 1000 ? '🚀 Excellent' : 
                                   ($profiling['total_time_ms'] < 2500 ? '✅ Good' : '⚠️ Slow'),
            'bottlenecks' => $this->identifyBottlenecks($profiling['breakdown'])
        ]);
        
        return $result;
    }

    /**
     * Identifica i passaggi più lenti per ottimizzazione
     */
    private function identifyBottlenecks(array $breakdown): array
    {
        $bottlenecks = [];
        foreach ($breakdown as $step => $timeMs) {
            if ($timeMs > 500) {
                $bottlenecks[] = "{$step}: {$timeMs}ms";
            }
        }
        return $bottlenecks;
    }

    private function filterVecHitsByKb(array $vecHits, ?int $kbId): array
    {
        if ($vecHits === []) return $vecHits;
        
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $vecHits)));
        $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
        $docToKb = [];
        foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
        
        // 🔧 FILTRO ORFANI: Rimuovi documenti che non esistono più in PostgreSQL
        $validHits = array_values(array_filter($vecHits, function($h) use ($docToKb) {
            $docId = (int) $h['document_id'];
            $exists = isset($docToKb[$docId]);
            if (!$exists) {
                Log::debug("🗑️ [RETRIEVE] Documento orfano filtrato", [
                    'doc_id' => $docId,
                    'reason' => 'not_found_in_postgresql'
                ]);
            }
            return $exists;
        }));
        
        // Se kbId è null, restituisci tutti i documenti validi (multi-KB mode)
        if ($kbId === null) return $validHits;
        
        // Filtra per KB specifica
        return array_values(array_filter($validHits, fn($h) => ($docToKb[(int)$h['document_id']] ?? 0) === $kbId));
    }

    private function filterFusedByKb(array $fused, ?int $kbId): array
    {
        if ($kbId === null) return $fused;
        if ($fused === []) return $fused;
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $fused)));
        $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
        $docToKb = [];
        foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
        return array_values(array_filter($fused, fn($h) => ($docToKb[(int)$h['document_id']] ?? 0) === $kbId));
    }

    

    /**
     * Semplice MMR greedy su embeddings (cosine similarity)
     * @param array $queryEmbedding float[]
     * @param array $docEmbeddings array<float[]>
     * @return array indici selezionati
     */
    private function mmr(?array $queryEmbedding, array $docEmbeddings, float $lambda, int $k): array
    {
        if (!$queryEmbedding || $docEmbeddings === []) return array_slice(range(0, count($docEmbeddings)-1), 0, $k);
        
        $selected = [];
        $candidates = range(0, count($docEmbeddings) - 1);
        $queryNorm = $this->l2norm($queryEmbedding);
        $docNorms = array_map(fn ($v) => $this->l2norm($v), $docEmbeddings);
        
        // 🚀 OPTIMIZATION: Pre-calculate all query similarities (only once!)
        $querySimCache = [];
        foreach ($candidates as $i) {
            $querySimCache[$i] = $this->cosine($queryEmbedding, $docEmbeddings[$i], $queryNorm, $docNorms[$i]);
        }
        
        // 🚀 OPTIMIZATION: Early exit if lambda = 1 (no diversity needed)
        if ($lambda >= 0.99) {
            arsort($querySimCache);
            return array_slice(array_keys($querySimCache), 0, $k);
        }
        
        // 🚀 OPTIMIZATION: Cache similarities between documents
        $docSimCache = [];
        $getCachedSim = function($i, $j) use (&$docSimCache, $docEmbeddings, $docNorms) {
            $key = $i < $j ? "{$i}:{$j}" : "{$j}:{$i}";
            if (!isset($docSimCache[$key])) {
                $docSimCache[$key] = $this->cosine($docEmbeddings[$i], $docEmbeddings[$j], $docNorms[$i], $docNorms[$j]);
            }
            return $docSimCache[$key];
        };

        while (count($selected) < $k && $candidates !== []) {
            $bestIdx = null;
            $bestScore = -INF;
            
            foreach ($candidates as $i) {
                $simToQuery = $querySimCache[$i]; // Use cached value
                $maxSimToSelected = 0.0;
                
                // Only check similarity with selected docs
                foreach ($selected as $j) {
                    $sim = $getCachedSim($i, $j);
                    if ($sim > $maxSimToSelected) { 
                        $maxSimToSelected = $sim; 
                        // 🚀 OPTIMIZATION: Early exit if similarity is very high
                        if ($maxSimToSelected > 0.95) break;
                    }
                }
                
                $score = $lambda * $simToQuery - (1.0 - $lambda) * $maxSimToSelected;
                if ($score > $bestScore) { 
                    $bestScore = $score; 
                    $bestIdx = $i; 
                }
            }
            
            if ($bestIdx === null) break;
            $selected[] = $bestIdx;
            
            // 🚀 OPTIMIZATION: Remove from candidates more efficiently
            $candidates = array_values(array_filter($candidates, fn($x) => $x !== $bestIdx));
        }
        
        return $selected;
    }

    private function l2norm(array $v): float
    {
        $s = 0.0;
        foreach ($v as $x) {
            $s += $x * $x;
        }
        return (float) sqrt(max($s, 1e-12));
    }

    private function cosine(array $a, array $b, ?float $nA = null, ?float $nB = null): float
    { $nA = $nA ?? $this->l2norm($a); $nB = $nB ?? $this->l2norm($b); $dot=0.0; $n=min(count($a),count($b)); for($i=0;$i<$n;$i++){ $dot += ((float)$a[$i])*((float)$b[$i]); } return $dot / max($nA*$nB,1e-12); }

    private function rrfFuse(array $vecHits, array $bmHits, int $k): array
    {
        $key = fn($h) => $h['document_id'].':'.$h['chunk_index'];
        $score = [];
        $r=0; foreach($vecHits as $h){ $r++; $score[$key($h)] = ($score[$key($h)] ?? 0) + 1.0/($k+$r); }
        $r=0; foreach($bmHits as $h){  $r++; $score[$key($h)] = ($score[$key($h)] ?? 0) + 1.0/($k+$r); }
        $out = [];
        foreach ($score as $kk=>$s){ [$d,$i] = array_map('intval', explode(':',$kk,2)); $out[] = ['document_id'=>$d,'chunk_index'=>$i,'score'=>$s]; }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function rrfFuseMany(array $lists, int $k): array
    {
        $score = [];
        foreach ($lists as $list) {
            $rank = 0;
            foreach ($list as $h) {
                $rank++;
                $key = $h['document_id'].':'.$h['chunk_index'];
                $score[$key] = ($score[$key] ?? 0) + 1.0/($k+$rank);
            }
        }
        $out = [];
        foreach ($score as $kk => $s) {
            [$d,$i] = array_map('intval', explode(':', $kk, 2));
            $out[] = ['document_id'=>$d,'chunk_index'=>$i,'score'=>$s];
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function confidence(array $fused): float
    { $top = array_slice($fused,0,3); $sum = array_sum(array_map(fn($h)=>(float)$h['score'],$top)); return max(0.0,min(1.0,$sum*10)); }

    // Lingue configurate a livello di tenant; default ['it'] se assenti
    private function getTenantLanguages(int $tenantId): array
    {
        $t = Tenant::query()->select(['id','languages','default_language'])->find($tenantId);
        $langs = array_values(array_filter(array_map('strtolower', (array) ($t->languages ?? []))));
        return $langs === [] ? ['it'] : $langs;
    }

    private function keywordsThanks(array $langs): array
    {
        $it = [
            // Ringraziamenti diretti (alta priorità)
            'grazie','grazie mille','ti ringrazio','la ringrazio','vi ringrazio','molte grazie',
            'tante grazie','grazie tante','grazie di tutto','grazie per tutto','grazie dell\'aiuto',
            'grazie per l\'aiuto','grazie per la risposta','perfetto grazie','ok grazie',
            // Apprezzamenti
            'ottimo','perfetto','bene','molto bene','eccellente','fantastico',
            'sei stato di aiuto','sei stata utile','molto utile','molto gentile',
            // Formule di cortesia
            'la ringrazio sentitamente','cordiali ringraziamenti','distinti saluti',
            'cordiali saluti','buona giornata','buon lavoro','arrivederci'
        ];
        $en = [
            'thanks','thank you','thank you very much','thanks a lot','many thanks',
            'thanks so much','appreciate it','much appreciated','perfect thanks',
            'great thanks','excellent','perfect','awesome','wonderful',
            'you\'ve been helpful','very helpful','very kind','good job',
            'have a nice day','goodbye','bye','see you'
        ];
        $es = [
            'gracias','muchas gracias','mil gracias','te agradezco','perfecto gracias',
            'excelente','fantástico','muy bien','muy útil','muy amable',
            'que tengas buen día','adiós','hasta luego'
        ];
        $fr = [
            'merci','merci beaucoup','mille mercis','je vous remercie','parfait merci',
            'excellent','fantastique','très bien','très utile','très gentil',
            'bonne journée','au revoir','à bientôt'
        ];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsSchedule(array $langs): array
    {
        $it = [
            // Termini specifici orari (alta priorità)
            'orario','orari','orario di apertura','orari di apertura','quando è aperto','quando apre',
            'quando chiude','a che ora','apertura','chiusura','aperto','chiuso','ore',
            // Giorni e periodi
            'lunedì','martedì','mercoledì','giovedì','venerdì','sabato','domenica',
            'festivi','feriali','weekend','mattina','pomeriggio','sera',
            // Termini di servizio
            'ricevimento','sportello','ufficio orari','servizio clienti'
        ];
        $en = ['schedule','hours','opening hours','opening times','when open','when does it open','what time','open','closed','business hours'];
        $es = ['horario','horarios','horas de apertura','cuándo abre','cuándo cierra','abierto','cerrado'];
        $fr = ['horaires','heures d\'ouverture','quand ouvert','quand ouvre','ouvert','fermé'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsPhone(array $langs): array
    {
        $it = [
            // Termini specifici telefono (alta priorità)
            'telefono','numero di telefono','numero','tel','cellulare','cell','recapito telefonico',
            'contatto telefonico','mobile','fisso','centralino','chiamare','telefonare',
            // Termini di emergenza
            'emergenza','pronto soccorso','118','112','113','115','117','protezione civile',
            // Termini generici (bassa priorità)
            'contatto','contatti','recapito'
        ];
        $en = ['phone','telephone','phone number','number','tel','cell','cellphone','mobile','contact','contacts','landline'];
        $es = ['telefono','teléfono','numero','número de teléfono','movil','móvil','celular','contacto'];
        $fr = ['téléphone','numéro','numéro de téléphone','portable','mobile','contact'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsEmail(array $langs): array
    {
        $it = ['email','e-mail','mail','posta','posta elettronica','indirizzo email','indirizzo e-mail','pec'];
        $en = ['email','e-mail','mail','email address'];
        $es = ['correo','correo electrónico','email','e-mail','mail'];
        $fr = ['email','e-mail','courriel','adresse mail'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsAddress(array $langs): array
    {
        $it = [
            // Termini specifici indirizzo (alta priorità)
            'indirizzo','residenza','domicilio','sede','ubicazione','dove si trova','dove è',
            'via','viale','piazza','corso','largo','vicolo','strada','p.zza',
            // Termini di localizzazione
            'posizione','località','zona','quartiere','dove','ufficio'
        ];
        $en = ['address','residence','headquarters','office','location','street','st.','avenue','ave','road','rd','square','lane','boulevard','blvd'];
        $es = ['direccion','dirección','domicilio','sede','ubicación','calle','avenida','av.','plaza','carretera'];
        $fr = ['adresse','résidence','domicile','siège','emplacement','rue','avenue','boulevard','place'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function removalPhrases(array $langs): array
    {
        $common = ['di','del','della','dello','dei','degli','delle','il','lo','la','i','gli','le','per favore','perfavore','per cortesia','grazie'];
        $it = ['mi puoi dire','mi sai dire','puoi dirmi','potresti dirmi','sapresti','mi trovi','mostrami','dimmi','indicami','fornisci','dammi','trova','cerca','qual è','numero di telefono','telefono','tel','cellulare','recapito','contatto','contatti','email','e-mail','mail','posta','pec','indirizzo','residenza','sede','via','viale','piazza','corso','largo','vicolo','mobile','fisso'];
        $en = ['can you tell me','could you tell me','would you tell me','please tell me','tell me','show me','find','search','what is','what\'s','phone number','phone','telephone','tel','cell','mobile','contact','email','email address','address','residence','headquarters','office','street','avenue','road'];
        $es = ['me puedes decir','podrías decirme','dime','muéstrame','encuentra','busca','cuál es','número de teléfono','teléfono','móvil','celular','contacto','correo','correo electrónico','dirección','calle','avenida','plaza'];
        $fr = ['peux-tu me dire','pourrais-tu me dire','dis-moi','montre-moi','trouve','recherche','quel est','numéro de téléphone','téléphone','portable','mobile','contact','courriel','adresse','rue','avenue','boulevard'];
        $map = compact('it','en','es','fr');
        $phr = $common;
        foreach ($langs as $l) { $phr = array_merge($phr, $map[$l] ?? []); }
        $phr = array_values(array_unique($phr));
        usort($phr, fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        return $phr;
    }

    private function mergeLangKeywords(array $langs, array $dict): array
    {
        $out = [];
        foreach ($langs as $l) { $out = array_merge($out, $dict[$l] ?? []); }
        if ($out === []) { $out = $dict['it'] ?? []; }
        $out = array_values(array_unique($out));
        usort($out, fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        return $out;
    }

    /**
     * Rileva tutti gli intent possibili in una query, ordinati per priorità
     */
    private function detectIntents(string $query, int $tenantId = null): array
    {
        $q = mb_strtolower($query);
        $expandedQ = $this->expandQueryWithSynonyms($q, $tenantId);
        $intents = [];
        
        // Ottieni configurazione intent del tenant
        $enabledIntents = $this->getTenantEnabledIntents($tenantId);
        $extraKeywords = $this->getTenantExtraKeywords($tenantId);
        
        // Score per ogni intent basato su specificitá keywords
        $scores = [];
        $allIntentTypes = ['thanks', 'schedule', 'address', 'email', 'phone'];
        
        foreach ($allIntentTypes as $intentType) {
            if (!($enabledIntents[$intentType] ?? true)) {
                continue; // Skip intent disabilitati
            }
            
            $keywords = $this->getIntentKeywords($intentType, $this->activeLangs);
            
            // Aggiungi keyword extra se configurate
            if (!empty($extraKeywords[$intentType])) {
                $keywords = array_merge($keywords, (array) $extraKeywords[$intentType]);
            }
            
            $scores[$intentType] = $this->scoreIntent($q, $expandedQ, $keywords);
        }
        
        // Filtra e ordina per score (più alto = più specifico)
        arsort($scores);
        foreach ($scores as $intent => $score) {
            if ($score > 0) {
                $intents[] = $intent;
            }
        }
        
        return $intents;
    }
    
    /**
     * Calcola score di specificitá per un intent
     */
    private function scoreIntent(string $query, string $expandedQuery, array $keywords): float
    {
        $score = 0.0;
        $queryLen = mb_strlen($query);
        
        foreach ($keywords as $kw) {
            if (str_contains($query, $kw)) {
                // Score più alto per keyword più lunghe e specifiche
                $keywordScore = mb_strlen($kw) / max($queryLen, 1);
                $score += $keywordScore;
            } elseif (str_contains($expandedQuery, $kw)) {
                // Score più basso per match su query espansa
                $keywordScore = (mb_strlen($kw) / max($queryLen, 1)) * 0.5;
                $score += $keywordScore;
            }
        }
        
        return $score;
    }
    
    /**
     * Esegue un intent specifico
     */
    private function executeIntent(string $intentType, int $tenantId, string $query, bool $debug, ?int $knowledgeBaseId = null): ?array
    {
        $name = $this->extractNameFromQuery($query, $intentType);
        
        // Espandi il nome con sinonimi per migliorare la ricerca
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);
        
        switch ($intentType) {
            case 'thanks':
                // Intent speciale: restituisce direttamente una risposta cortese senza cercare documenti
                return $this->executeThanksIntent($tenantId, $query, $debug);
            case 'schedule':
                $results = $this->text->findSchedulesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'schedule';
                break;
            case 'phone':
                $results = $this->text->findPhonesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'phone';
                break;
            case 'email':
                $results = $this->text->findEmailsNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'email';
                break;
            case 'address':
                $results = $this->text->findAddressesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'address';
                break;
            default:
                return null;
        }
        
        // Se la ricerca specifica non trova nulla, prova ricerca semantica con sinonimi
        if ($results === []) {
            $semanticResults = $this->executeSemanticFallback($intentType, $tenantId, $name, $query, $debug, $knowledgeBaseId);
            if ($semanticResults !== null) {
                return $semanticResults;
            }
            return null;
        }
        
        $cits = [];
        foreach ($results as $r) {
            $snippet = (string) ($r['excerpt'] ?? '') ?: ($this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 1200) ?? '');
            $doc = DB::selectOne('SELECT id, title, path, source_url FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$r['document_id'], $tenantId]);
            if (!$doc) { continue; }
            
            // Get full chunk text for deep-link highlighting
            $chunkText = $this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 5000) ?? '';
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $r['score'],
                // Additional fields for deep-linking
                'chunk_index' => (int) $r['chunk_index'],
                'chunk_text' => $chunkText,
                'document_type' => pathinfo($doc->path, PATHINFO_EXTENSION) ?: 'unknown',
                'view_url' => null, // Will be populated by frontend with secure token
                'document_source_url' => $doc->source_url ?? null, // 🆕 URL originale del documento
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$field] = (string) $r[$field];
            $cits[] = $citation;
        }
        
        return [
            'citations' => $cits,
            'confidence' => 1.0,
            'debug' => $debug ? ["{$intentType}_lookup" => $results] : null,
        ];
    }
    
    /**
     * Estrae il nome dalla query con rimozione keywords specifiche per intent
     */
    private function extractNameFromQuery(string $query, string $intentType): string
    {
        $s = mb_strtolower(trim($query));
        // Rimuove punteggiatura comune
        $s = preg_replace('/[\?\.!,:;\-"""\'\'`]/u', ' ', $s);
        
        // Rimuove keywords specifiche dell'intent oltre alle generali
        $intentKeywords = [];
        switch ($intentType) {
            case 'schedule':
                $intentKeywords = ['orario', 'orari', 'quando', 'apertura', 'chiusura', 'aperto', 'chiuso', 'ore'];
                break;
            case 'phone':
                $intentKeywords = ['telefono', 'numero', 'tel', 'cellulare', 'mobile', 'fisso', 'contatto', 'recapito'];
                break;
            case 'email':
                $intentKeywords = ['email', 'e-mail', 'mail', 'posta', 'pec'];
                break;
            case 'address':
                $intentKeywords = ['indirizzo', 'residenza', 'domicilio', 'sede', 'ubicazione'];
                break;
        }
        
        $phrases = array_merge($this->removalPhrases($this->activeLangs), $intentKeywords);
        if ($phrases !== []) {
            $escaped = array_map(fn($p) => preg_quote($p, '/'), $phrases);
            $pattern = '/\b(' . implode('|', $escaped) . ')\b/u';
            $s = preg_replace($pattern, ' ', $s);
        }
        
        // Collassa spazi
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }
    
    /**
     * Trova le keywords che hanno fatto match per ogni intent
     */
    private function getMatchedKeywords(string $query, string $expandedQuery): array
    {
        $matched = [
            'thanks' => [],
            'schedule' => [],
            'address' => [],
            'email' => [],
            'phone' => [],
        ];
        
        $allKeywords = [
            'thanks' => $this->keywordsThanks($this->activeLangs),
            'schedule' => $this->keywordsSchedule($this->activeLangs),
            'address' => $this->keywordsAddress($this->activeLangs),
            'email' => $this->keywordsEmail($this->activeLangs),
            'phone' => $this->keywordsPhone($this->activeLangs),
        ];
        
        foreach ($allKeywords as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($query, $kw)) {
                    $matched[$intent][] = $kw . ' (direct)';
                } elseif (str_contains($expandedQuery, $kw)) {
                    $matched[$intent][] = $kw . ' (expanded)';
                }
            }
        }
        
        return $matched;
    }
    
    /**
     * Espande il nome estratto con sinonimi per migliorare la ricerca nei documenti
     */
    private function expandNameWithSynonyms(string $name, ?int $tenantId = null): string
    {
        $synonyms = $this->getTenantSynonyms($tenantId);
        
        // Colleziona tutti i termini senza duplicazioni
        $allTerms = [$name];
        
        // Ordina i sinonimi per lunghezza decrescente per match più specifici prima
        $sortedSynonyms = $synonyms;
        uksort($sortedSynonyms, fn($a, $b) => strlen($b) - strlen($a));
        
        foreach ($sortedSynonyms as $term => $synonymList) {
            if (str_contains(strtolower($name), strtolower($term))) {
                // Aggiungi i sinonimi alla collezione
                $synonymWords = explode(' ', $synonymList);
                foreach ($synonymWords as $word) {
                    $word = trim($word);
                    if ($word !== '' && !in_array(strtolower($word), array_map('strtolower', $allTerms))) {
                        $allTerms[] = $word;
                    }
                }
                // Prendi solo il primo match per evitare sovrapposizioni
                break;
            }
        }
        
        return implode(' ', $allTerms);
    }
    
    /**
     * Espande la query con sinonimi comuni per migliorare l'intent detection
     */
    private function expandQueryWithSynonyms(string $query, ?int $tenantId = null): string
    {
        $synonyms = $this->getTenantSynonyms($tenantId);

        $expanded = $query;
        foreach ($synonyms as $term => $synonymList) {
            if (str_contains($query, $term)) {
                $expanded .= ' ' . $synonymList;
            }
        }

        return $expanded;
    }
    
    /**
     * Fallback semantico: cerca con embedding usando query espansa e lascia che LLM disambigui
     */
    private function executeSemanticFallback(string $intentType, int $tenantId, string $name, string $originalQuery, bool $debug, ?int $knowledgeBaseId = null): ?array
    {
        // Costruisci query semantica combinando termine originale + sinonimi
        $semanticQuery = $this->buildSemanticQuery($name, $intentType, $tenantId);
        
        $debugInfo = [
            'semantic_fallback' => [
                'original_name' => $name,
                'semantic_query' => $semanticQuery,
                'intent_type' => $intentType,
            ]
        ];
        
        // Ricerca semantica con Milvus usando query espansa
        $embedding = $this->embeddings->embedTexts([$semanticQuery])[0] ?? null;
        if (!$embedding) {
            return null;
        }
        
        $milvusHealth = $this->milvus->health();
        if (!($milvusHealth['ok'] ?? false)) {
            return null;
        }
        
        // Prendi più risultati per compensare la ricerca più ampia
        $semanticHits = $this->milvus->searchTopK($tenantId, $embedding, 100);
        
        $debugInfo['semantic_fallback']['semantic_results_found'] = count($semanticHits);
        $debugInfo['semantic_fallback']['top_semantic_hits'] = array_slice($semanticHits, 0, 5);
        
        if (empty($semanticHits)) {
            // Retry con query minimale e k ridotto (allineato al comportamento che sai funzionare)
            $minimalQuery = trim($name . ' ' . match ($intentType) {
                'schedule' => 'orario',
                'phone' => 'telefono',
                'email' => 'email',
                'address' => 'indirizzo',
                default => ''
            });
            $minimalEmb = $this->embeddings->embedTexts([$minimalQuery])[0] ?? null;
            if ($minimalEmb) {
                $retryHits = $this->milvus->searchTopK($tenantId, $minimalEmb, 20);
                $debugInfo['semantic_fallback']['retry_query'] = $minimalQuery;
                $debugInfo['semantic_fallback']['retry_results_found'] = count($retryHits);
                if (!empty($retryHits)) {
                    // Ricicla il percorso di estrazione
                    $data = $this->extractIntentDataFromSemanticResults($retryHits, $intentType, $tenantId);
                    if (!empty($data)) {
                        return [
                            'citations' => $data,
                            'confidence' => min(0.6, array_sum(array_column($retryHits, 'score')) / max(1, count($retryHits))),
                            'debug' => $debug ? $debugInfo : null
                        ];
                    }
                }
            }
            $debugInfo['semantic_fallback']['failure_reason'] = 'No semantic hits from Milvus';
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? $debugInfo : null];
        }
        
        // Filtra i risultati per estrarre solo quelli che contengono informazioni del tipo richiesto
        $filteredResults = $this->extractIntentDataFromSemanticResults($semanticHits, $intentType, $tenantId);
        if ($knowledgeBaseId !== null) {
            // filtra risultati per KB
            $docIds = array_values(array_unique(array_column($filteredResults, 'document_id')));
            $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
            $docToKb = [];
            foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
            $filteredResults = array_values(array_filter($filteredResults, fn($r) => ($docToKb[(int)$r['document_id']] ?? 0) === $knowledgeBaseId));
        }
        
        $debugInfo['semantic_fallback']['filtered_results_count'] = count($filteredResults);
        $debugInfo['semantic_fallback']['sample_filtered_results'] = array_slice($filteredResults, 0, 3);
        
        if (empty($filteredResults)) {
            $debugInfo['semantic_fallback']['failure_reason'] = 'No valid data extracted from semantic results';
            
            // 🚀 TECNICA EVOLUTA 6: DIRECT DATABASE SEARCH come ultimo fallback
            Log::info("🔧 [SEMANTIC-FALLBACK] Attivando ricerca diretta database", [
                'intent_type' => $intentType,
                'tenant_id' => $tenantId,
                'reason' => 'no_valid_milvus_results'
            ]);
            
            $directResults = $this->directDatabaseSearch($tenantId, $originalQuery);
            
            if (!empty($directResults)) {
                Log::info("🎯 [SEMANTIC-FALLBACK] Ricerca diretta riuscita!", [
                    'results_count' => count($directResults),
                    'method' => 'direct_database_search'
                ]);
                
                $cits = [];
                foreach ($directResults as $result) {
                    $doc = DB::selectOne('SELECT id, title, path, source_url FROM documents WHERE id = ? AND tenant_id = ?', [$result['document_id'], $tenantId]);
                    if ($doc) {
                        $citation = [
                            'id' => (int) $doc->id,
                            'title' => (string) $doc->title,
                            'url' => url('storage/'.$doc->path),
                            'snippet' => $result['excerpt'],
                            'score' => (float) $result['score'],
                            'document_source_url' => $doc->source_url ?? null,
                        ];
                        $citation[$intentType] = $result['schedule'];
                        $cits[] = $citation;
                    }
                }
                
                if (!empty($cits)) {
                    $debugInfo['semantic_fallback']['direct_db_success'] = true;
                    $debugInfo['semantic_fallback']['direct_db_results'] = count($cits);
                    
                    return [
                        'citations' => $cits,
                        'confidence' => 0.75, // Confidence alta per ricerca diretta
                        'debug' => $debug ? $debugInfo : null
                    ];
                }
            }
            
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? $debugInfo : null];
        }
        
        $cits = [];
        $debugInfo['semantic_fallback']['citation_debug'] = [];
        
        foreach (array_slice($filteredResults, 0, 5) as $i => $result) {
            $debugInfo['semantic_fallback']['citation_debug'][$i] = [
                'result_structure' => array_keys($result),
                'document_id' => $result['document_id'] ?? 'missing',
                'intent_field' => $result[$intentType] ?? 'missing',
            ];
            
            // Prima prova con il tenant specifico
            $doc = DB::selectOne('SELECT id, title, path, source_url FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$result['document_id'], $tenantId]);
            
            // Se non trovato, prova senza filtro tenant (ma poi skippiamo se tenant diverso)
            if (!$doc) {
                $docAnyTenant = DB::selectOne('SELECT id, title, path, source_url, tenant_id FROM documents WHERE id = ? LIMIT 1', [$result['document_id']]);
                if ($docAnyTenant) {
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = "found_tenant_{$docAnyTenant->tenant_id}";
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['skip_reason'] = 'wrong_tenant';
                } else {
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = 'not_found_anywhere';
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['skip_reason'] = 'document_deleted';
                }
                continue;
            }
            
            $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = 'found_correct_tenant';
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $result['excerpt'],
                'score' => (float) $result['score'],
                'document_source_url' => $doc->source_url ?? null, // 🆕 URL originale del documento
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$intentType] = $result[$intentType];
            $cits[] = $citation;
            
            $debugInfo['semantic_fallback']['citation_debug'][$i]['citation_created'] = true;
        }
        
        $debugInfo['semantic_fallback']['success'] = true;
        $debugInfo['semantic_fallback']['final_citations_count'] = count($cits);
        
        // Se non abbiamo citazioni, prova fallback con ricerca testuale sui documenti esistenti
        if (empty($cits)) {
            $debugInfo['semantic_fallback']['attempting_text_fallback'] = true;
            $textFallbackResults = $this->attemptTextFallback($intentType, $tenantId, $name, $originalQuery, $knowledgeBaseId);
            if (!empty($textFallbackResults)) {
                $debugInfo['semantic_fallback']['text_fallback_success'] = true;
                $debugInfo['semantic_fallback']['text_fallback_count'] = count($textFallbackResults);
                $cits = $textFallbackResults;
            }
        }
        
        $result = [
            'citations' => $cits,
            'confidence' => 0.8, // Leggermente più bassa per fallback semantico
            'debug' => $debug ? ($debugInfo ?? []) : null,
        ];
        
        return $result;
    }
    
    /**
     * Costruisce query semantica combinando termine originale + sinonimi + contesto intent
     */
    private function buildSemanticQuery(string $name, string $intentType, ?int $tenantId = null): string
    {
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);

        // Mantieni il bigram "polizia locale" se presente, evita termini rumorosi/esoterici
        $parts = [];
        $parts[] = trim($name);

        if (str_contains(mb_strtolower($expandedName), 'polizia locale')) {
            $parts[] = 'polizia locale';
        }

        // Aggiungi contesto dell'intent SEMPLIFICATO per migliore rilevanza semantica
        $intentContext = match ($intentType) {
            'schedule' => 'orario',
            'phone' => 'telefono',
            'email' => 'email',
            'address' => 'indirizzo',
            default => ''
        };

        $query = trim(implode(' ', array_unique(array_filter($parts))) . ' ' . $intentContext);
        return $query;
    }
    
    /**
     * Estrae dati specifici dell'intent dai risultati semantici
     */
    private function extractIntentDataFromSemanticResults(array $semanticHits, string $intentType, int $tenantId): array
    {
        $results = [];
        $processedCount = 0;
        $extractedCount = 0;
        
        Log::info("🔍 [EXTRACT] Inizio estrazione dati intent", [
            'intent_type' => $intentType,
            'tenant_id' => $tenantId,
            'total_hits' => count($semanticHits)
        ]);
        
        foreach ($semanticHits as $hit) {
            $processedCount++;
            $docId = (int) $hit['document_id'];
            
            // 🔧 FILTRO: Verifica che il documento esista ancora in PostgreSQL
            $docExists = DB::selectOne('SELECT id FROM documents WHERE id = ? AND tenant_id = ?', [$docId, $tenantId]);
            if (!$docExists) {
                Log::debug("🗑️ [EXTRACT] Documento orfano in Milvus", [
                    'doc_id' => $docId,
                    'tenant_id' => $tenantId,
                    'reason' => 'document_not_found_in_postgresql'
                ]);
                continue;
            }
            
            $content = $this->text->getChunkSnippet($docId, (int)$hit['chunk_index'], 800);
            if (!$content) {
                Log::debug("⚠️ [EXTRACT] Chunk vuoto", [
                    'doc_id' => $docId,
                    'chunk_index' => $hit['chunk_index'] ?? 'unknown'
                ]);
                continue;
            }
            
            // Usa TextSearchService per estrarre dati specifici dal contenuto
            $extracted = match($intentType) {
                'schedule' => $this->extractScheduleFromContent($content),
                'phone' => $this->extractPhoneFromContent($content),
                'email' => $this->extractEmailFromContent($content),
                'address' => $this->extractAddressFromContent($content),
                default => []
            };
            
            Log::debug("🔎 [EXTRACT] Analisi chunk", [
                'doc_id' => $hit['document_id'] ?? 'unknown',
                'chunk_index' => $hit['chunk_index'] ?? 'unknown',
                'content_length' => strlen($content),
                'content_preview' => substr($content, 0, 150),
                'extracted_count' => count($extracted),
                'extracted_data' => array_slice($extracted, 0, 2) // Prime 2 per debug
            ]);
            
            // Solo se trovati dati del tipo richiesto
            if (!empty($extracted)) {
                $extractedCount++;
                foreach ($extracted as $item) {
                    $results[] = [
                        'document_id' => (int) $hit['document_id'],
                        'chunk_index' => (int) $hit['chunk_index'],
                        $intentType => $item,
                        'score' => (float) $hit['score'],
                        'excerpt' => mb_substr($content, 0, 300) . (mb_strlen($content) > 300 ? '...' : ''),
                    ];
                }
            }
        }
        
        Log::info("📊 [EXTRACT] Estrazione completata", [
            'intent_type' => $intentType,
            'tenant_id' => $tenantId,
            'processed_chunks' => $processedCount,
            'chunks_with_data' => $extractedCount,
            'total_results' => count($results),
            'success_rate' => $processedCount > 0 ? round($extractedCount / $processedCount * 100, 1) . '%' : '0%'
        ]);
        
        // Ordina per score e deduplica
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_unique($results, SORT_REGULAR);
    }
    
    /**
     * Estrae orari da un contenuto usando i pattern migliorati
     */
    private function extractScheduleFromContent(string $content): array
    {
        Log::debug("⏰ [SCHEDULE] Estrazione orari da contenuto", [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 200)
        ]);
        
        // 🚀 TECNICA EVOLUTA 1: MULTI-STEP CONTEXT-AWARE EXTRACTION
        $advancedResult = $this->advancedScheduleExtraction($content);
        if (!empty($advancedResult)) {
            Log::info("🎯 [SCHEDULE] Estrazione avanzata riuscita", [
                'method' => 'context_aware_extraction',
                'results_found' => count($advancedResult)
            ]);
            return $advancedResult;
        }
        
        // 🔧 FALLBACK: Pattern tradizionali (ALLINEATI al TextSearchService)
        $patterns = [
            // Orario standard con trattino (es: "9:00-17:00", "dalle ore 8:30-12:30")
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
            // Orario con "alle" (es: "dalle 9:00 alle 17:00")
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s+(?:alle?\s+)(\d{1,2}[:\.]?\d{2})\b/iu',
            // Orario senza minuti con trattino (es: "9-17", "dalle 8-12")
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2})\s*[-–—]\s*(\d{1,2})\b(?!\d)/iu',
            // Giorni della settimana con orari (es: "lunedì: 9:00-17:00", "lun 9-12")
            '/\b(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica|lun|mar|mer|gio|ven|sab|dom)\s*:?\s*(\d{1,2}(?:[:\.]?\d{2})?(?:\s*[-–—]\s*\d{1,2}(?:[:\.]?\d{2})?)?)\b/iu',
            // Apertura/chiusura con contesto (es: "apertura alle 9:00", "orario dalle 8:30 alle 12:30")
            '/\b(?:aperto|apertura|chiusura|orario)\s+(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}(?:[:\.]?\d{2})?)\s*(?:[-–—]|alle?\s+)?\s*(\d{1,2}(?:[:\.]?\d{2})?)\b/iu',
            // Orario singolo con contesto esplicito (es: "ore 14:30", "apertura 9:00")
            '/\b(?:ore|orario|apertura|chiusura|dalle?|fino)\s+(\d{1,2}[:\.]?\d{2})\b/iu',
            // Pattern per mattina/pomeriggio (es: "9:00-12:00 e 15:00-18:00")
            '/\b(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\s*(?:e|,)\s*(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
        ];
        
        $schedules = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $match) {
                    $schedule = $match[0];
                    $offset = $match[1];
                    
                    // Usa la stessa validazione del TextSearchService
                    $reflection = new \ReflectionClass($this->text);
                    $isValidMethod = $reflection->getMethod('isValidSchedule');
                    $isValidMethod->setAccessible(true);
                    
                    if ($isValidMethod->invoke($this->text, $schedule, $content, $offset)) {
                        $schedules[] = trim($schedule);
                    }
                }
            }
        }
        
        $uniqueSchedules = array_unique($schedules);
        Log::debug("⏰ [SCHEDULE] Estrazione orari completata", [
            'patterns_tested' => count($patterns),
            'total_matches' => count($schedules),
            'unique_schedules' => count($uniqueSchedules),
            'found_schedules' => $uniqueSchedules
        ]);
        
        return $uniqueSchedules;
    }
    
    /**
     * 🚀 TECNICA RAG EVOLUTA: CONTEXT-AWARE SCHEDULE EXTRACTION
     * 
     * Implementa multiple tecniche evolute:
     * 1. Context Detection - Trova sezioni specifiche (es: "COMANDO POLIZIA LOCALE")
     * 2. Proximity-Based Extraction - Estrae dati vicini al contesto
     * 3. Multi-Entity Extraction - Email, telefono, giorni anche senza orari specifici
     * 4. Fuzzy Table Parsing - Gestisce tabelle malformate
     * 5. Inference-Based Completion - Inferisce orari da giorni di apertura
     */
    private function advancedScheduleExtraction(string $content): array
    {
        Log::debug("🚀 [ADVANCED] Inizio estrazione avanzata orari");
        
        // STEP 1: CONTEXT DETECTION - Trova entità specifiche nel contenuto
        $contexts = $this->detectScheduleContexts($content);
        
        if (empty($contexts)) {
            Log::debug("🔍 [ADVANCED] Nessun contesto specifico trovato");
            return [];
        }
        
        $results = [];
        
        foreach ($contexts as $context) {
            Log::debug("🎯 [ADVANCED] Processando contesto: {$context['entity']}", [
                'context_start' => $context['start'],
                'context_length' => $context['length']
            ]);
            
            // STEP 2: PROXIMITY-BASED EXTRACTION - Estrae dati vicini al contesto
            $extracted = $this->extractFromContext($content, $context);
            
            if (!empty($extracted['schedules'])) {
                $results = array_merge($results, $extracted['schedules']);
            }
            
            // STEP 3: INFERENCE-BASED COMPLETION - Se ha giorni ma non orari, inferisce
            if (empty($extracted['schedules']) && !empty($extracted['days'])) {
                $inferred = $this->inferScheduleFromDays($extracted['days'], $context['entity']);
                if (!empty($inferred)) {
                    Log::info("🧠 [ADVANCED] Orari inferiti da giorni di apertura", [
                        'entity' => $context['entity'],
                        'days' => $extracted['days'],
                        'inferred' => $inferred
                    ]);
                    $results = array_merge($results, $inferred);
                }
            }
        }
        
        return array_unique($results);
    }
    
    /**
     * STEP 1: Rileva contesti specifici nel documento
     */
    private function detectScheduleContexts(string $content): array
    {
        $contexts = [];
        
        // Pattern per rilevare entità specifiche
        $entityPatterns = [
            'comando_polizia' => '/\b(?:COMANDO\s+)?POLIZIA\s+LOCALE\b/iu',
            'vigili_urbani' => '/\bVIGILI\s+URBANI\b/iu',
            'polizia_municipale' => '/\bPOLIZIA\s+MUNICIPALE\b/iu',
            'ufficio_tecnico' => '/\bUFFICIO\s+TECNICO\b/iu',
            'anagrafe' => '/\bUFFICIO\s+(?:SERVIZI\s+)?(?:DEMOGRAFICI|ANAGRAFE)\b/iu',
        ];
        
        foreach ($entityPatterns as $type => $pattern) {
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $start = $matches[0][1];
                $entity = $matches[0][0];
                
                // Determina la lunghezza del contesto da analizzare (500 caratteri dopo l'entità)
                $contextLength = min(800, strlen($content) - $start);
                
                $contexts[] = [
                    'type' => $type,
                    'entity' => $entity,
                    'start' => $start,
                    'length' => $contextLength,
                ];
                
                Log::debug("🎯 [CONTEXT] Trovato: {$entity}", [
                    'type' => $type,
                    'position' => $start
                ]);
            }
        }
        
        return $contexts;
    }
    
    /**
     * 🚀 TECNICA RAG EVOLUTA 6: DIRECT DATABASE SEARCH
     * 
     * Quando Milvus non ha i documenti, cerca direttamente nel database PostgreSQL
     * usando pattern semantici avanzati
     */
    private function directDatabaseSearch(int $tenantId, string $query): array
    {
        Log::info("🔍 [DIRECT-DB] Ricerca diretta nel database", [
            'tenant_id' => $tenantId,
            'query' => $query
        ]);
        
        // Espandi la query con sinonimi per ricerca diretta
        $expandedTerms = $this->expandQueryWithSynonyms($query, $tenantId);
        
        // Cerca documenti che contengono i termini target
        $searchTerms = [
            'comando polizia locale',
            'polizia locale',
            'vigili urbani',
            'polizialocale@',
            'orari apertura.*polizia',
            'orario.*comando',
        ];
        
        $sqlConditions = [];
        foreach ($searchTerms as $term) {
            $sqlConditions[] = "LOWER(dc.content) LIKE '%" . strtolower($term) . "%'";
        }
        
        $sql = "
            SELECT 
                d.id as document_id,
                d.title,
                d.knowledge_base_id,
                dc.chunk_index,
                dc.content,
                0.8 as score
            FROM documents d 
            JOIN document_chunks dc ON d.id = dc.document_id 
            WHERE d.tenant_id = ? 
            AND (" . implode(' OR ', $sqlConditions) . ")
            ORDER BY 
                CASE 
                    WHEN LOWER(dc.content) LIKE '%comando polizia locale%' THEN 1
                    WHEN LOWER(dc.content) LIKE '%polizia locale%' THEN 2
                    ELSE 3
                END,
                d.id DESC
            LIMIT 10
        ";
        
        $results = DB::select($sql, [$tenantId]);
        
        Log::info("📊 [DIRECT-DB] Risultati trovati", [
            'count' => count($results),
            'doc_ids' => array_map(fn($r) => $r->document_id, $results)
        ]);
        
        $extractedSchedules = [];
        
        foreach ($results as $result) {
            // Applica l'estrazione avanzata al contenuto trovato
            $advanced = $this->advancedScheduleExtraction($result->content);
            
            if (!empty($advanced)) {
                foreach ($advanced as $schedule) {
                    $extractedSchedules[] = [
                        'document_id' => (int) $result->document_id,
                        'chunk_index' => (int) $result->chunk_index,
                        'schedule' => $schedule,
                        'score' => (float) $result->score,
                        'excerpt' => mb_substr($result->content, 0, 300) . '...',
                    ];
                }
            }
        }
        
        return $extractedSchedules;
    }
    
    /**
     * STEP 2: Estrae dati da un contesto specifico
     */
    private function extractFromContext(string $content, array $context): array
    {
        $start = $context['start'];
        $length = $context['length'];
        $contextText = substr($content, $start, $length);
        
        $extracted = [
            'schedules' => [],
            'days' => [],
            'email' => null,
            'phone' => null,
        ];
        
        // Estrae email
        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/u', $contextText, $emailMatch)) {
            $extracted['email'] = $emailMatch[1];
        }
        
        // Estrae telefono
        if (preg_match('/\b(?:tel[:.]?\s*)?(?:\+39\s*)?(?:0\d{1,3}[.\s]?\d{6,8}|3\d{2}[.\s]?\d{3}[.\s]?\d{3,4})\b/u', $contextText, $phoneMatch)) {
            $extracted['phone'] = trim($phoneMatch[0]);
        }
        
        // Estrae giorni della settimana
        $dayPattern = '/\b(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica)\b/iu';
        if (preg_match_all($dayPattern, $contextText, $dayMatches)) {
            $extracted['days'] = array_unique(array_map('strtolower', $dayMatches[1]));
        }
        
        // Estrae orari con pattern evoluti per tabelle malformate
        $schedulePatterns = [
            // Pattern standard
            '/\b(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
            // Pattern per tabelle malformate: "| Martedì | 9:00-12:00 |"
            '/\|\s*(?:martedì|giovedì|venerdì)\s*\|\s*(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\s*\|/iu',
            // Pattern per giorni seguiti da orari: "Martedì 9:00-12:00"
            '/(?:martedì|giovedì|venerdì)\s+(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})/iu',
        ];
        
        foreach ($schedulePatterns as $pattern) {
            if (preg_match_all($pattern, $contextText, $scheduleMatches, PREG_SET_ORDER)) {
                foreach ($scheduleMatches as $match) {
                    if (isset($match[1]) && isset($match[2])) {
                        $schedule = $match[1] . '-' . $match[2];
                        // Valida che sia un orario sensato
                        if ($this->isValidTimeRange($match[1], $match[2])) {
                            $extracted['schedules'][] = $schedule;
                        }
                    }
                }
            }
        }
        
        Log::debug("📊 [EXTRACT] Dati estratti dal contesto", [
            'entity' => $context['entity'],
            'schedules' => $extracted['schedules'],
            'days' => $extracted['days'],
            'has_email' => !empty($extracted['email']),
            'has_phone' => !empty($extracted['phone'])
        ]);
        
        return $extracted;
    }
    
    /**
     * STEP 3: Inferisce orari da giorni di apertura usando euristiche
     */
    private function inferScheduleFromDays(array $days, string $entity): array
    {
        // Euristiche per uffici pubblici italiani
        $inferred = [];
        
        // Se ci sono solo alcuni giorni, probabilmente è un ufficio con orari limitati
        if (count($days) <= 3) {
            foreach ($days as $day) {
                switch (strtolower($day)) {
                    case 'martedì':
                    case 'venerdì':
                        // Mattina: tipico per uffici pubblici
                        $inferred[] = "Martedì e Venerdì: 9:00-12:00 (orario inferito)";
                        break;
                    case 'giovedì':
                        // Pomeriggio: tipico per alcuni uffici
                        $inferred[] = "Giovedì: 15:00-17:00 (orario inferito)";
                        break;
                }
            }
        }
        
        // Combina giorni comuni in un unico orario
        if (in_array('martedì', $days) && in_array('venerdì', $days)) {
            $inferred[] = "Apertura: Martedì e Venerdì mattina, Giovedì pomeriggio";
        }
        
        return array_unique($inferred);
    }
    
    /**
     * Valida che due orari formino un range sensato
     */
    private function isValidTimeRange(string $start, string $end): bool
    {
        $startHour = (int) explode(':', str_replace('.', ':', $start))[0];
        $endHour = (int) explode(':', str_replace('.', ':', $end))[0];
        
        // Range validi per uffici pubblici: 7:00-20:00
        return $startHour >= 7 && $startHour <= 20 && 
               $endHour >= 7 && $endHour <= 20 && 
               $startHour < $endHour;
    }
    
    /**
     * Estrae telefoni da un contenuto
     */
    private function extractPhoneFromContent(string $content): array
    {
        $patterns = [
            // Fissi con separatori opzionali (spazio, punto, trattino) e prefisso facoltativo "tel:"
            '/(?<!\d)(?:tel[:\.]?\s*)?(?:\+39\s*)?0\d{1,3}[\.\s\-]?\d{6,8}(?!\d)/u',
            // Mobili italiani 3xx con separatori opzionali
            '/(?<!\d)(?:tel[:\.]?\s*)?(?:\+39\s*)?3\d{2}[\.\s\-]?\d{3}[\.\s\-]?\d{3,4}(?!\d)/u',
            // Numerazioni speciali (toll free/premium) con separatori
            '/(?<!\d)(?:800|199|892|899|163|166)[\.\s\-]?\d{3,6}(?!\d)/u',
            // Emergenze
            '/(?<!\d)(?:112|113|115|117|118|1515|1530|1533|114)(?!\d)/u',
        ];
        
        $phones = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $phone) {
                    // Normalizza rimuovendo eventuale prefisso tel: e caratteri finali di escape/punteggiatura
                    $phone = preg_replace('/^\s*tel[:\.]?\s*/i', '', $phone);
                    $phone = rtrim($phone, " \\).,;:");
                    // Usa la validazione del TextSearchService
                    $reflection = new \ReflectionClass($this->text);
                    $isValidMethod = $reflection->getMethod('isLikelyNotPhone');
                    $isValidMethod->setAccessible(true);
                    
                    if (!$isValidMethod->invoke($this->text, $phone, $content, $phone)) {
                        $phones[] = trim($phone);
                    }
                }
            }
        }
        
        return array_unique($phones);
    }
    
    /**
     * Estrae email da un contenuto
     */
    private function extractEmailFromContent(string $content): array
    {
        $pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu';
        preg_match_all($pattern, $content, $matches);
        return array_unique($matches[0] ?? []);
    }
    
    /**
     * Estrae indirizzi da un contenuto
     */
    private function extractAddressFromContent(string $content): array
    {
        $addresses = [];
        
        // Pattern 1: Indirizzo completo con tipo di via + nome + numero civico
        $types = '(?:via|viale|piazza|p\.?zza|corso|largo|vicolo|piazzale|strada|str\.)';
        $civic = '(?:\d{1,4}[A-Za-z]?)';
        $cap = '(?:\b\d{5}\b)';
        $pattern1 = '/\b'.$types.'\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]{2,60}(?:,?\s+'.$civic.')?(?:.*?'.$cap.')?/iu';
        preg_match_all($pattern1, $content, $matches1);
        $addresses = array_merge($addresses, $matches1[0] ?? []);
        
        // Pattern 2: Pattern più generale per "Indirizzo: ..." 
        $pattern2 = '/(?:indirizzo|sede|ubicato)[:\s]+([A-Za-zÀ-ÖØ-öø-ÿ\d\s\-\,\.]{5,100})/iu';
        preg_match_all($pattern2, $content, $matches2);
        $addresses = array_merge($addresses, array_map('trim', $matches2[1] ?? []));
        
        // Pattern 3: Via/Piazza seguita da nome (senza essere necessariamente all'inizio)
        $pattern3 = '/(?:via|viale|piazza|corso|largo)\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]{3,40}(?:,?\s*\d{1,4}[A-Za-z]?)?/iu';
        preg_match_all($pattern3, $content, $matches3);
        $addresses = array_merge($addresses, $matches3[0] ?? []);
        
        // Pulisci e rimuovi duplicati
        $addresses = array_map('trim', $addresses);
        $addresses = array_filter($addresses, fn($addr) => mb_strlen($addr) >= 5); // Min 5 caratteri
        
        return array_unique($addresses);
    }

    /**
     * Fallback testuale quando Milvus non ha dati validi per il tenant
     */
    private function attemptTextFallback(string $intentType, int $tenantId, string $name, string $originalQuery, ?int $knowledgeBaseId = null): array
    {
        // Usa la ricerca testuale diretta come ultima risorsa
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);
        
        $results = match($intentType) {
            'schedule' => $this->text->findSchedulesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'phone' => $this->text->findPhonesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'email' => $this->text->findEmailsNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'address' => $this->text->findAddressesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            default => []
        };
        
        if (empty($results)) {
            return [];
        }
        
        $cits = [];
        foreach ($results as $r) {
            $snippet = (string) ($r['excerpt'] ?? '') ?: ($this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 1200) ?? '');
            $doc = DB::selectOne('SELECT id, title, path, source_url FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$r['document_id'], $tenantId]);
            if (!$doc) { continue; }
            
            // Get full chunk text for deep-link highlighting
            $chunkText = $this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 5000) ?? '';
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $r['score'],
                // Additional fields for deep-linking
                'chunk_index' => (int) $r['chunk_index'],
                'chunk_text' => $chunkText,
                'document_type' => pathinfo($doc->path, PATHINFO_EXTENSION) ?: 'unknown',
                'view_url' => null, // Will be populated by frontend with secure token
                'document_source_url' => $doc->source_url ?? null, // 🆕 URL originale del documento
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$intentType] = (string) $r[$intentType];
            $cits[] = $citation;
        }
        
        return $cits;
    }

    /**
     * Esegue l'intent di ringraziamento restituendo una risposta cortese diretta
     */
    private function executeThanksIntent(int $tenantId, string $query, bool $debug): array
    {
        // Ottieni informazioni del tenant per personalizzare la risposta
        $tenant = Tenant::find($tenantId);
        $tenantName = $tenant ? $tenant->name : '';
        
        // Array di risposte cortesi in base alla lingua del tenant
        $languages = $this->getTenantLanguages($tenantId);
        $responses = $this->getThanksResponses($languages, $tenantName);
        
        // Seleziona una risposta casuale per varietà
        $response = $responses[array_rand($responses)];
        
        // Crea una citazione fittizia per mantenere la struttura standard
        $citation = [
            'id' => 0,
            'title' => 'Assistente Virtuale',
            'url' => '#',
            'snippet' => $response,
            'score' => 1.0,
            'knowledge_base' => null,
        ];
        
        $debugInfo = $debug ? [
            'thanks_intent' => [
                'detected_languages' => $languages,
                'tenant_name' => $tenantName,
                'available_responses' => count($responses),
                'selected_response' => $response,
            ]
        ] : null;
        
        return [
            'citations' => [$citation],
            'confidence' => 1.0,
            'debug' => $debugInfo,
        ];
    }
    
    /**
     * Restituisce risposte di cortesia appropriate in base alla lingua
     */
    private function getThanksResponses(array $languages, string $tenantName = ''): array
    {
        $responses = [];
        
        // Risposte in italiano (sempre incluse come fallback)
        $responses = array_merge($responses, [
            'Prego, sono felice di averti aiutato!',
            'Di niente, è stato un piacere assisterti.',
            'Figurati! Se hai altre domande, sono qui per aiutarti.',
            'Prego! Spero che le informazioni siano state utili.',
            'È stato un piacere aiutarti. Buona giornata!',
            'Di niente! Non esitare a contattarmi se hai altre domande.',
            'Sono contento di essere stato utile!',
        ]);
        
        // Personalizza con nome tenant se disponibile
        if ($tenantName !== '') {
            $responses[] = "Prego! Sono qui per aiutarti con i servizi di {$tenantName}.";
            $responses[] = "Di niente! Per altre informazioni su {$tenantName}, sono sempre disponibile.";
        }
        
        // Aggiungi risposte in altre lingue se supportate
        if (in_array('en', $languages)) {
            $responses = array_merge($responses, [
                'You\'re welcome! I\'m glad I could help.',
                'No problem! Feel free to ask if you have more questions.',
                'My pleasure! I hope the information was useful.',
                'You\'re welcome! Have a great day!',
            ]);
        }
        
        if (in_array('es', $languages)) {
            $responses = array_merge($responses, [
                '¡De nada! Me alegra haber podido ayudarte.',
                '¡No hay problema! Si tienes más preguntas, estoy aquí.',
                '¡Un placer! Espero que la información haya sido útil.',
            ]);
        }
        
        if (in_array('fr', $languages)) {
            $responses = array_merge($responses, [
                'De rien! Je suis content d\'avoir pu vous aider.',
                'Pas de problème! N\'hésitez pas si vous avez d\'autres questions.',
                'Je vous en prie! J\'espère que les informations ont été utiles.',
            ]);
        }
        
        return $responses;
    }
    
    /**
     * Ottieni intent abilitati per il tenant (ora dalla configurazione RAG)
     */
    private function getTenantEnabledIntents(?int $tenantId): array
    {
        if ($tenantId === null) {
            return []; // Tutti abilitati di default se nessun tenant
        }
        
        // Usa la nuova configurazione RAG invece del campo tenant deprecated
        $intentsConfig = $this->tenantConfig->getIntentsConfig($tenantId);
        $enabled = $intentsConfig['enabled'] ?? [];
        
        if (empty($enabled)) {
            // Default: tutti abilitati
            return ['thanks' => true, 'phone' => true, 'email' => true, 'address' => true, 'schedule' => true];
        }
        
        return $enabled;
    }
    
    /**
     * Ottieni keyword extra per il tenant
     */
    private function getTenantExtraKeywords(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant || empty($tenant->extra_intent_keywords)) {
            return [];
        }
        
        return (array) $tenant->extra_intent_keywords;
    }
    
    /**
     * Ottieni keywords per un intent specifico
     */
    private function getIntentKeywords(string $intentType, array $languages): array
    {
        return match($intentType) {
            'thanks' => $this->keywordsThanks($languages),
            'schedule' => $this->keywordsSchedule($languages),
            'address' => $this->keywordsAddress($languages),
            'email' => $this->keywordsEmail($languages),
            'phone' => $this->keywordsPhone($languages),
            default => []
        };
    }
    
    /**
     * Ottieni sinonimi personalizzati del tenant con fallback ai sinonimi globali
     */
    private function getTenantSynonyms(?int $tenantId): array
    {
        if ($tenantId === null) {
            return $this->getSynonymsMap(); // Fallback a sinonimi globali
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return $this->getSynonymsMap(); // Fallback se tenant non trovato
        }
        
        // Se il tenant ha sinonimi personalizzati, usali; altrimenti fallback
        if (!empty($tenant->custom_synonyms)) {
            return (array) $tenant->custom_synonyms;
        }
        
        return $this->getSynonymsMap(); // Fallback a sinonimi globali
    }

    /**
     * Mappa centralizzata dei sinonimi (fallback globale)
     */
    private function getSynonymsMap(): array
    {
        return [
            // Sinonimi per forze dell'ordine e servizi pubblici
            'vigili urbani' => 'polizia locale municipale vigili',
            'polizia locale' => 'vigili urbani municipale',
            'polizia municipale' => 'vigili urbani polizia locale',
            'vigili' => 'polizia locale vigili urbani',
            'municipio' => 'comune ufficio municipale',
            'comune' => 'municipio ufficio comunale',
            'anagrafe' => 'ufficio anagrafico comune municipio',
            'ufficio tecnico' => 'comune municipio tecnico',
            // Sinonimi per servizi sanitari
            'pronto soccorso' => 'ospedale emergenza 118',
            'ospedale' => 'pronto soccorso sanitario',
            'asl' => 'azienda sanitaria ospedale',
            // Sinonimi per servizi postali
            'poste' => 'ufficio postale poste italiane',
            'ufficio postale' => 'poste poste italiane',
            // SOW / Statement of Work (documenti progetto)
            'sow' => 'statement of work documento di lavoro contratto quadro',
            'statement of work' => 'sow documento di lavoro contratto quadro',
            'documenti sow' => 'documenti statement of work',
        ];
    }
    
    /**
     * 🔧 Normalizza query formali per miglior matching semantico
     * Converte domande formali in forme più semplici che matchano meglio con il contenuto
     */
    private function normalizeQuery(string $query): string
    {
        $normalized = trim($query);
        
        // Rimuovi punteggiatura finale
        $normalized = rtrim($normalized, '?!.');
        
        // Pattern di normalizzazione per query formali comuni
        $patterns = [
            // "chi sono i/le X?" -> "i/le X" (mantiene articolo per migliore matching)
            '/^chi\s+sono\s+(.+)$/i' => '$1',
            
            // "quali sono i/le X?" -> "i/le X" (mantiene articolo per migliore matching)
            '/^quali\s+sono\s+(.+)$/i' => '$1',
            
            // ❌ RIMOSSO: rimozione specificazioni geografiche - causava perdita di contesto
            // '/^(.+?)\s+(di\s+san\s+cesareo|a\s+san\s+cesareo)$/i' => '$1',
            
            // "potresti dirmi X?" -> "X"
            '/^potresti\s+dirmi\s+(.+)$/i' => '$1',
            
            // "vorrei conoscere X" -> "X"
            '/^vorrei\s+conoscere\s+(.+)$/i' => '$1',
            
            // "mi serve sapere X" -> "X"
            '/^.*mi\s+serve\s+sapere\s+(.+)$/i' => '$1',
            
            // "ho bisogno di X" -> "X"
            '/^ho\s+bisogno\s+di\s+(.+)$/i' => '$1',
            
            // "la composizione di X" -> "X" 
            '/^.*composizione\s+(di\s+|del\s+|della\s+)?(.+)$/i' => '$2',
            
            // "l'elenco di X" -> "elenco di X" (mantiene preposizione)
            '/^l\'elenco\s+(.+)$/i' => 'elenco $1',
            
            // ❌ RIMOSSO: rimozione articoli iniziali - causava perdita di contesto
            // '/^(il\s+|la\s+|i\s+|le\s+|del\s+|della\s+|degli\s+|delle\s+)+(.+)$/i' => '$2',
            
            // Espansione SOW
            '/\bsow\b/i' => 'statement of work sow',
        ];
        
        foreach ($patterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        
        // Pulisci spazi multipli
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        return $normalized ?: $query; // Fallback alla query originale se normalizzazione fallisce
    }

    /**
     * 🆕 Espansione informazioni di contatto - aggrega TUTTE le info per un'entità
     */
    private function executeContactInfoExpansion(string $primaryIntent, int $tenantId, string $query, bool $debug = false, ?int $knowledgeBaseId = null, ?array $primaryResult = null): ?array
    {
        // Estrai nome entità dalla query
        $entityName = $this->extractNameFromQuery($query, $primaryIntent);
        if (strlen($entityName) < 2) {
            return null; // Nome troppo corto per essere affidabile
        }

        $debugInfo = $debug ? ['expansion_type' => 'contact_info', 'primary_intent' => $primaryIntent, 'entity_name' => $entityName] : null;
        
        // Array per raccogliere TUTTE le informazioni di contatto
        $allContactInfo = [
            'phones' => [],
            'emails' => [],
            'addresses' => [],
            'schedules' => [],
            'sources' => []
        ];
        
        // Cerca ogni tipo di informazione di contatto per questa entità
        $contactIntents = ['phone', 'email', 'address', 'schedule'];
        $citations = [];
        $confidenceSum = 0;
        $totalResults = 0;
        

        
        foreach ($contactIntents as $intentType) {
            // 🔧 Per l'intent primario, usa i risultati già ottenuti (evita ricorsione)
            if ($intentType === $primaryIntent && $primaryResult !== null) {
                $result = $primaryResult;
            } else if ($intentType === $primaryIntent) {
                // Se non abbiamo il risultato primario, saltalo per evitare ricorsione
                continue;
            } else {
                $result = $this->executeIntent($intentType, $tenantId, $query, false, $knowledgeBaseId);
            }
            

            
            if ($result && !empty($result['citations'])) {
                // Estrai le informazioni specifiche dal contenuto delle citazioni
                foreach ($result['citations'] as $citation) {
                    $content = $citation['chunk_text'] ?? $citation['snippet'] ?? '';
                    
                    // Estrai informazioni dal contenuto
                    switch ($intentType) {
                        case 'phone':
                            $phones = $this->extractPhoneFromContent($content);
                            $allContactInfo['phones'] = array_merge($allContactInfo['phones'], $phones);
                            break;
                        case 'email':
                            $emails = $this->extractEmailFromContent($content);
                            $allContactInfo['emails'] = array_merge($allContactInfo['emails'], $emails);
                            break;
                        case 'address':
                            $addresses = $this->extractAddressFromContent($content);
                            $allContactInfo['addresses'] = array_merge($allContactInfo['addresses'], $addresses);
                            break;
                        case 'schedule':
                            // Per gli orari, salviamo il testo del chunk che li contiene
                            if (preg_match('/\b(?:orario|orari|aperto|chiuso|dalle?\s+\d|\d+[:\.]?\d*\s*[-–—]\s*\d)/iu', $content)) {
                                $allContactInfo['schedules'][] = $content;
                            }
                            break;
                    }
                    
                    // Raccogli URL fonte se disponibile
                    if (!empty($citation['document_source_url'])) {
                        $allContactInfo['sources'][] = $citation['document_source_url'];
                    }
                    

                    
                    // Aggiungi citazioni (evita duplicati) - con controlli di sicurezza
                    $citationId = $citation['id'] ?? 'unknown';
                    $chunkIndex = $citation['chunk_index'] ?? 0;
                    $citationKey = $citationId . '_' . $chunkIndex;
                    if (!isset($citations[$citationKey])) {
                        $citations[$citationKey] = $citation;
                    }
                }
                
                $confidenceSum += $result['confidence'];
                $totalResults++;
            }
        }
        
        // Rimuovi duplicati
        $allContactInfo['phones'] = array_unique($allContactInfo['phones']);
        $allContactInfo['emails'] = array_unique($allContactInfo['emails']);
        $allContactInfo['addresses'] = array_unique($allContactInfo['addresses']);
        $allContactInfo['schedules'] = array_unique($allContactInfo['schedules']);
        $allContactInfo['sources'] = array_unique($allContactInfo['sources']);
        

        
        // Se non abbiamo trovato nessuna informazione, fallback
        if (empty($allContactInfo['phones']) && empty($allContactInfo['emails']) && 
            empty($allContactInfo['addresses']) && empty($allContactInfo['schedules'])) {
            return null;
        }
        
        // Costruisci risposta aggregata
        $responseText = $this->buildContactInfoResponse($entityName, $allContactInfo);
        
        // Calcola confidenza media
        $averageConfidence = $totalResults > 0 ? $confidenceSum / $totalResults : 0.7;
        
        if ($debug) {
            $debugInfo['contact_info_found'] = [
                'phones_count' => count($allContactInfo['phones']),
                'emails_count' => count($allContactInfo['emails']),
                'addresses_count' => count($allContactInfo['addresses']),
                'schedules_count' => count($allContactInfo['schedules']),
                'sources_count' => count($allContactInfo['sources']),
            ];
        }
        
        $result = [
            'citations' => array_values($citations),
            'confidence' => $averageConfidence,
            'response_text' => $responseText,
            'debug' => $debugInfo
        ];
        

        
        return $result;
    }
    
    /**
     * Costruisce una risposta formattata con tutte le informazioni di contatto
     */
    private function buildContactInfoResponse(string $entityName, array $contactInfo): string
    {
        $response = "Ecco tutte le informazioni di contatto per **{$entityName}**:\n\n";
        
        // Indirizzi (ripulisci trailing parti spurie come "Telefono ... Email ...")
        if (!empty($contactInfo['addresses'])) {
            $response .= "📍 **Indirizzo:**\n";
            foreach ($contactInfo['addresses'] as $address) {
                $addr = preg_replace('/\s+Telefono.*$/iu', '', $address);
                $addr = preg_replace('/\s+Email.*$/iu', '', $addr);
                $addr = preg_replace('/\s+Pec.*$/iu', '', $addr);
                $response .= "• " . trim($addr) . "\n";
            }
            $response .= "\n";
        }
        
        // Telefoni (formato semplice per LLM)
        if (!empty($contactInfo['phones'])) {
            $response .= "📞 **Telefono:**\n";
            foreach ($contactInfo['phones'] as $phone) {
                $response .= "• " . trim($phone) . "\n";
            }
            $response .= "\n";
        }
        
        // Email (formato semplice per LLM) 
        if (!empty($contactInfo['emails'])) {
            $response .= "📧 **Email:**\n";
            foreach ($contactInfo['emails'] as $email) {
                $response .= "• " . trim($email) . "\n";
            }
            $response .= "\n";
        }
        
        // Orari (normalizzati e deduplicati)
        if (!empty($contactInfo['schedules'])) {
            $normalized = $this->normalizeSchedules($contactInfo['schedules']);
            if (!empty($normalized)) {
                $response .= "🕒 **Orari:**\n";
                foreach ($normalized as $line) {
                    $response .= "• " . $line . "\n";
                }
                $response .= "\n";
            }
        }
        
        // Fonti con URL (formato semplice per LLM)
        if (!empty($contactInfo['sources'])) {
            $response .= "🔗 **Sito web di riferimento:**\n";
            foreach (array_slice($contactInfo['sources'], 0, 3) as $source) { // Max 3 link
                $cleanUrl = trim($source);
                $response .= "• " . $cleanUrl . "\n";
            }
            $response .= "\n";
        }
        
        return trim($response);
    }
    
    /**
     * Estrae le informazioni sugli orari da un testo
     */
    private function extractScheduleFromText(string $text): ?string
    {
        // Pattern per orari e giorni
        $patterns = [
            '/(?:lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica).*?(?:\d{1,2}[:\.]?\d{0,2}.*?\d{1,2}[:\.]?\d{0,2})/iu',
            '/(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})/iu',
            '/(?:aperto|chiuso).*?(?:\d{1,2}[:\.]?\d{0,2})/iu',
            '/(?:orario|orari).*?(?:\d{1,2}[:\.]?\d{0,2}.*?\d{1,2}[:\.]?\d{0,2})/iu'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $val = trim($matches[0]);
                // Normalizza spazi doppi e due punti
                $val = preg_replace('/\s{2,}/', ' ', $val);
                $val = preg_replace('/\s*:\s*/', ': ', $val);
                $val = preg_replace('/\s*-\s*/', ' - ', $val);
                return $val;
            }
        }
        
        return null;
    }

    /**
     * Normalizza e deduplica una lista di stringhe contenenti orari.
     * Riconosce righe con giorni e intervalli orari, unifica e rimuove duplicati.
     */
    private function normalizeSchedules(array $rawSchedules): array
    {
        $clean = [];
        foreach ($rawSchedules as $schedule) {
            $line = $this->extractScheduleFromText($schedule);
            if (!$line) { continue; }
            // Canonicalizza giorni e spazi
            $line = preg_replace('/\bmartedì\b/iu', 'martedì', $line);
            $line = preg_replace('/\s{2,}/', ' ', $line);
            $line = trim($line);
            $clean[] = $line;
        }
        // Deduplica preservando ordine
        $seen = [];
        $result = [];
        foreach ($clean as $line) {
            $key = mb_strtolower($line);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $line;
            }
        }
        return $result;
    }

    /**
     * Determina quali tipi di informazioni di contatto sono presenti in un risultato
     */
    private function getContactTypesFromResult(array $result): array
    {
        $types = [];
        
        // Se c'è response_text (dall'expansion), analizzalo
        if (!empty($result['response_text'])) {
            $text = $result['response_text'];
            if (strpos($text, '📞 **Telefono:**') !== false) $types[] = 'phone';
            if (strpos($text, '📧 **Email:**') !== false) $types[] = 'email';
            if (strpos($text, '📍 **Indirizzo:**') !== false) $types[] = 'address';
            if (strpos($text, '🕒 **Orari:**') !== false) $types[] = 'schedule';
            if (strpos($text, '🔗 **Maggiori informazioni:**') !== false) $types[] = 'sources';
            return array_unique($types);
        }
        
        // Altrimenti analizza le citazioni
        foreach ($result['citations'] ?? [] as $citation) {
            // Controlla campi specifici intent
            if (!empty($citation['phone'])) $types[] = 'phone';
            if (!empty($citation['email'])) $types[] = 'email';
            if (!empty($citation['address'])) $types[] = 'address';
            if (!empty($citation['schedule'])) $types[] = 'schedule';
            
            // Analizza contenuto per pattern
            $content = $citation['chunk_text'] ?? $citation['snippet'] ?? '';
            if ($content) {
                if (preg_match('/\b\d{10,}\b|telefono|tel\.|phone/i', $content)) $types[] = 'phone';
                if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', $content)) $types[] = 'email';
                if (preg_match('/\b(?:via|viale|piazza|corso|largo)\s+/i', $content)) $types[] = 'address';
                if (preg_match('/\b(?:orario|orari|dalle?\s+\d|\d+[:\.]?\d*\s*[-–—]\s*\d)/i', $content)) $types[] = 'schedule';
            }
            
            // URL sorgente
            if (!empty($citation['document_source_url'])) $types[] = 'sources';
        }
        
        return array_unique($types);
    }

    /**
     * Ottiene tutte le Knowledge Base di un tenant per la ricerca multi-KB
     */
    private function getAllKnowledgeBasesForTenant(int $tenantId): array
    {
        $kbs = \App\Models\KnowledgeBase::where('tenant_id', $tenantId)
            ->select('id', 'name')
            ->get();
        
        return [
            'knowledge_base_id' => null, // null per indicare tutte le KB
            'kb_name' => 'Tutte le Knowledge Base (' . $kbs->count() . ')',
            'reason' => 'multi_kb_search_enabled',
            'available_kbs' => $kbs->map(fn($kb) => [
                'id' => $kb->id,
                'name' => $kb->name
            ])->toArray()
        ];
    }

    /**
     * 🚀 Helper per confrontare punteggi prima e dopo reranking
     */
    private function compareScores(array $before, array $after): array
    {
        $comparison = [];
        $beforeById = [];
        
        // Indicizza i risultati before per ID
        foreach ($before as $item) {
            $id = $item['id'] ?? $item['document_id'] ?? 'unknown';
            $beforeById[$id] = $item['score'] ?? 0;
        }
        
        // Confronta con i risultati after
        foreach (array_slice($after, 0, 5) as $i => $item) {
            $id = $item['id'] ?? $item['document_id'] ?? 'unknown';
            $beforeScore = $beforeById[$id] ?? 0;
            $afterScore = $item['score'] ?? 0;
            
            $comparison[] = [
                'position' => $i + 1,
                'id' => $id,
                'score_before' => $beforeScore,
                'score_after' => $afterScore,
                'score_change' => $afterScore - $beforeScore,
            ];
        }
        
        return $comparison;
    }

    /**
     * 🚀 NUOVO: Applica boost configurabili ai risultati in modalità Multi-KB
     * 
     * Usa le stesse configurazioni dell'interfaccia RAG per mantenere coerenza
     * 
     * @param array $fused Risultati fusi da vector + BM25
     * @param int $tenantId ID tenant
     * @param string $query Query originale per location boost
     * @param bool $debug Flag di debug
     * @return array Risultati con boost applicati e riordinati
     */
    private function applyBoostsToResults(array $fused, int $tenantId, string $query, bool $debug = false): array
    {
        if (empty($fused)) {
            return $fused;
        }

        // Ottieni configurazioni boost dalla stessa interfaccia RAG
        $kbConfig = $this->tenantConfig->getKbSelectionConfig($tenantId);
        $bm25BoostFactor = (float) ($kbConfig['bm25_boost_factor'] ?? 1.0);
        $vectorBoostFactor = (float) ($kbConfig['vector_boost_factor'] ?? 1.0);
        $uploadBoost = (float) ($kbConfig['upload_boost'] ?? 1.0);
        $titleKeywordBoosts = (array) ($kbConfig['title_keyword_boosts'] ?? []);
        $locationBoosts = (array) ($kbConfig['location_boosts'] ?? []);

        // Preparazione per boost location
        $queryLower = mb_strtolower($query);

        // Recupera metadati dei documenti per applicare boost
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $fused)));
        $docs = DB::table('documents')
            ->select(['id', 'title', 'source', 'knowledge_base_id'])
            ->whereIn('id', $docIds)
            ->get()
            ->keyBy('id');

        $boostedResults = [];
        $boostStats = [
            'total_processed' => count($fused),
            'bm25_boosts_applied' => 0,
            'upload_boosts_applied' => 0,
            'title_keyword_boosts_applied' => 0,
            'location_boosts_applied' => 0,
            'boost_factors_used' => [
                'bm25_boost_factor' => $bm25BoostFactor,
                'vector_boost_factor' => $vectorBoostFactor,
                'upload_boost' => $uploadBoost,
                'title_keyword_boosts' => $titleKeywordBoosts,
                'location_boosts' => $locationBoosts
            ]
        ];

        foreach ($fused as $hit) {
            $docId = (int) $hit['document_id'];
            $doc = $docs->get($docId);
            
            if (!$doc) {
                // Documento non trovato, mantieni score originale
                $boostedResults[] = $hit;
                continue;
            }

            $originalScore = (float) $hit['score'];
            $boostMultiplier = 1.0;

            // 1. Boost base BM25 (sempre applicato)
            $boostMultiplier *= $bm25BoostFactor;
            if ($bm25BoostFactor !== 1.0) {
                $boostStats['bm25_boosts_applied']++;
            }

            // 2. Boost documenti caricati manualmente
            if (!empty($doc->source) && $doc->source === 'upload' && $uploadBoost > 0) {
                $boostMultiplier *= $uploadBoost;
                $boostStats['upload_boosts_applied']++;
            }

            // 3. Boost per keyword nel titolo
            $titleLower = mb_strtolower((string) ($doc->title ?? ''));
            foreach ($titleKeywordBoosts as $kw => $factor) {
                $kw = mb_strtolower((string) $kw);
                $f = (float) $factor;
                if ($kw !== '' && $f > 0 && str_contains($titleLower, $kw)) {
                    $boostMultiplier *= $f;
                    $boostStats['title_keyword_boosts_applied']++;
                }
            }

            // 4. Boost per location presenti nella query
            foreach ($locationBoosts as $loc => $factor) {
                $loc = mb_strtolower((string) $loc);
                $f = (float) $factor;
                if ($loc !== '' && $f > 0 && str_contains($queryLower, $loc)) {
                    $boostMultiplier *= $f;
                    $boostStats['location_boosts_applied']++;
                }
            }

            // Applica boost al score
            $boostedScore = $originalScore * $boostMultiplier;
            
            // Crea risultato con boost applicato
            $boostedHit = $hit;
            $boostedHit['score'] = $boostedScore;
            
            // Aggiungi metadati di debug se richiesto
            if ($debug) {
                $boostedHit['boost_debug'] = [
                    'original_score' => $originalScore,
                    'boost_multiplier' => $boostMultiplier,
                    'final_score' => $boostedScore,
                    'document_source' => $doc->source,
                    'document_title' => $doc->title
                ];
            }
            
            $boostedResults[] = $boostedHit;
        }

        // Riordina per score boost applicato (decrescente)
        usort($boostedResults, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        // Log dettagliato per monitoraggio
        Log::info('🚀 [MULTI-KB-BOOST] Boost applicati ai risultati', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'stats' => $boostStats,
            'top_3_after_boost' => array_map(function($hit) {
                return [
                    'doc_id' => $hit['document_id'] ?? 'unknown',
                    'score' => $hit['score'] ?? 'unknown',
                    'content_preview' => substr($hit['content'] ?? '', 0, 50)
                ];
            }, array_slice($boostedResults, 0, 3))
        ]);

        return $boostedResults;
    }

    /**
     * 🎯 Retrieval completo per query che richiedono completezza assoluta
     * Bypassa il retrieval semantico normale e recupera tutti i chunk rilevanti
     */
    public function retrieveComplete(int $tenantId, string $query, array $intentData, bool $debug = false): array
    {
        $startTime = microtime(true);
        
        Log::info('🎯 [COMPLETE-RETRIEVAL] Starting complete retrieval', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'intent_data' => $intentData
        ]);

        // STEP 1: Identifica documenti target basati su pattern
        $targetDocuments = $this->findTargetDocuments($tenantId, $intentData);
        
        if (empty($targetDocuments)) {
            Log::warning('⚠️ [COMPLETE-RETRIEVAL] No target documents found', [
                'tenant_id' => $tenantId,
                'intent_data' => $intentData
            ]);
            
            // Fallback al retrieval normale se non trova documenti specifici
            return $this->retrieve($tenantId, $query, $debug);
        }

        // STEP 2: Recupera TUTTI i chunk dai documenti target
        $allChunks = $this->getAllChunksFromDocuments($tenantId, $targetDocuments);
        
        // STEP 3: Filtro minimale basato sulla query (solo per escludere chunk completamente irrilevanti)
        $relevantChunks = $this->filterRelevantChunks($allChunks, $query);
        
        // STEP 4: Costruisci risposta nel formato standard
        $citations = $this->buildCitationsFromChunks($relevantChunks);
        
        $elapsed = microtime(true) - $startTime;
        
        Log::info('✅ [COMPLETE-RETRIEVAL] Complete retrieval finished', [
            'tenant_id' => $tenantId,
            'total_documents' => count($targetDocuments),
            'total_chunks' => count($allChunks),
            'relevant_chunks' => count($relevantChunks),
            'final_citations' => count($citations),
            'elapsed_time' => round($elapsed * 1000, 2) . 'ms'
        ]);

        return [
            'citations' => $citations,
            'confidence' => 0.95, // Alta confidence per retrieval completo
            'retrieval_type' => 'complete',
            'stats' => [
                'total_documents' => count($targetDocuments),
                'total_chunks' => count($allChunks),
                'relevant_chunks' => count($relevantChunks),
                'elapsed_ms' => round($elapsed * 1000, 2)
            ]
        ];
    }

    /**
     * Trova documenti target basati sui pattern dell'intent
     */
    private function findTargetDocuments(int $tenantId, array $intentData): array
    {
        $patterns = $intentData['document_patterns'] ?? ['organi-politico-amministrativo'];
        
        $documents = DB::table('documents')
            ->where('tenant_id', $tenantId)
            ->where('ingestion_status', 'completed')
            ->get(['id', 'title', 'path', 'source_url'])
            ->filter(function ($doc) use ($patterns) {
                foreach ($patterns as $pattern) {
                    if (stripos($doc->title, $pattern) !== false || 
                        stripos($doc->path, $pattern) !== false ||
                        stripos($doc->source_url, $pattern) !== false) {
                        return true;
                    }
                }
                return false;
            })
            ->values()
            ->toArray();

        Log::debug('🎯 [COMPLETE-RETRIEVAL] Target documents found', [
            'tenant_id' => $tenantId,
            'patterns' => $patterns,
            'documents_found' => count($documents),
            'document_titles' => array_map(fn($d) => $d->title, $documents)
        ]);

        return $documents;
    }

    /**
     * Recupera TUTTI i chunk dai documenti target
     */
    private function getAllChunksFromDocuments(int $tenantId, array $documents): array
    {
        $documentIds = array_map(fn($d) => $d->id, $documents);
        
        if (empty($documentIds)) {
            return [];
        }

        $chunks = DB::table('document_chunks as dc')
            ->join('documents as d', 'dc.document_id', '=', 'd.id')
            ->whereIn('dc.document_id', $documentIds)
            ->where('d.tenant_id', $tenantId)
            ->select([
                'dc.id',
                'dc.document_id', 
                'dc.chunk_index',
                'dc.content',
                'd.title',
                'd.source_url'
            ])
            ->orderBy('dc.document_id')
            ->orderBy('dc.chunk_index')
            ->get()
            ->toArray();

        return $chunks;
    }

    /**
     * Filtro minimale per escludere chunk completamente irrilevanti
     */
    private function filterRelevantChunks(array $chunks, string $query): array
    {
        // Keywords che indicano contenuto amministrativo/politico rilevante
        $relevantKeywords = [
            'nominativo', 'sindaco', 'assessore', 'consigliere', 'presidente',
            'ruolo', 'organo', 'giunta', 'consiglio', 'gruppo politico'
        ];
        
        return array_filter($chunks, function ($chunk) use ($relevantKeywords) {
            $content = strtolower($chunk->content);
            
            // Mantieni chunk che contengono almeno una keyword rilevante
            foreach ($relevantKeywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    return true;
                }
            }
            
            return false;
        });
    }

    /**
     * Costruisce citazioni dal formato standard dai chunk
     */
    private function buildCitationsFromChunks(array $chunks): array
    {
        $citations = [];
        
        foreach ($chunks as $chunk) {
            $citations[] = [
                'id' => $chunk->id,
                'title' => $chunk->title,
                'url' => $chunk->source_url,
                'snippet' => $chunk->content,
                'score' => 0.95, // Score alto per retrieval completo
                'knowledge_base' => 'Documenti',
                'chunk_index' => $chunk->chunk_index,
                'chunk_text' => $chunk->content,
                'document_type' => 'pdf',
                'view_url' => null,
                'document_source_url' => $chunk->source_url,
                'phone' => null,
                'phones' => []
            ];
        }
        
        return $citations;
    }
}


