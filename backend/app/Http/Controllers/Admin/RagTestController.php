<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\MilvusClient;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\HyDEExpander;
use App\Services\RAG\ConversationContextEnhancer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

class RagTestController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Auto-scoping per clienti
        if (!$user->isAdmin()) {
            $tenants = $user->tenants()->wherePivot('role', 'customer')->orderBy('name')->get();
        } else {
            $tenants = Tenant::orderBy('name')->get();
        }
        
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => null]);
    }

    public function run(Request $request, KbSearchService $kb, OpenAIChatService $chat, MilvusClient $milvus)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'query' => ['required', 'string'],
            'with_answer' => ['nullable', 'boolean'],
            'enable_hyde' => ['nullable', 'boolean'],
            'enable_conversation' => ['nullable', 'boolean'],
            'conversation_messages' => ['nullable', 'string'],
            'reranker_driver' => ['nullable', 'string', 'in:embedding,llm,cohere'],
            'max_output_tokens' => ['nullable', 'integer', 'min:32', 'max:8192'],
        ]);
        $tenantId = (int) $data['tenant_id'];
        $tenant = Tenant::find($tenantId);
        
        // Controllo accesso per clienti
        $user = auth()->user();
        if (!$user->isAdmin()) {
            $userTenantIds = $user->tenants()->wherePivot('role', 'customer')->pluck('tenant_id')->toArray();
            if (!in_array($tenantId, $userTenantIds)) {
                abort(403, 'Non hai accesso a questo tenant.');
            }
        }
        $health = $milvus->health();
        
        // REMOVE: Non più gestione override UI - usiamo solo tenant config
        // I checkbox nell'UI ora sono solo informativi, non modificano la configurazione
        
        // Parse conversation messages se forniti (gestito da tenant config)
        $conversationMessages = [];
        if (!empty($data['conversation_messages'])) {
            try {
                $parsedMessages = json_decode($data['conversation_messages'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($parsedMessages)) {
                    $conversationMessages = $parsedMessages;
                    // Aggiungi la query corrente come ultimo messaggio
                    $conversationMessages[] = ['role' => 'user', 'content' => $data['query']];
                }
            } catch (\JsonException $e) {
                // Ignora errori JSON, usa conversazione vuota
            }
        }
        
        try {
            // REMOVE: Non più override - usa solo tenant config da /admin/tenants/{id}/rag-config
            // Tutte le configurazioni vengono lette da TenantRagConfigService
            
            // Invalida solo la cache per assicurarsi di leggere config aggiornate
            Cache::forget("rag_config_tenant_{$tenantId}");
            
            // Usa sempre il service standard (le configurazioni vengono da tenant config)
            $kb = app(KbSearchService::class);
            
            // Gestione query con contesto conversazionale
            $finalQuery = $data['query'];
            $conversationContext = null;
            
            // La gestione conversazione è ora controllata dalla tenant config
            // Non più override dal UI - usa TenantRagConfigService
            if (!empty($conversationMessages)) {
                $conversationEnhancer = app(ConversationContextEnhancer::class);
                $conversationContext = $conversationEnhancer->enhanceQuery(
                    $data['query'],
                    $conversationMessages,
                    $tenantId
                );
                
                if ($conversationContext['context_used']) {
                    $finalQuery = $conversationContext['enhanced_query'];
                }
            }
            
            // 🔍 LOG: Configurazioni RAG Tester (ora da tenant config)
            $tenantCfgSvc = app(\App\Services\RAG\TenantRagConfigService::class);
            $advCfg = (array) $tenantCfgSvc->getAdvancedConfig($tenantId);
            $rerankCfg = (array) $tenantCfgSvc->getRerankerConfig($tenantId);
            
            \Log::info('RagTestController RAG Config', [
                'tenant_id' => $tenantId,
                'original_query' => $data['query'],
                'final_query' => $finalQuery,
                'conversation_enhanced' => $conversationContext ? $conversationContext['context_used'] : false,
                'hyde_enabled' => (bool) (($advCfg['hyde']['enabled'] ?? false) === true),
                'reranker_driver' => (string) ($rerankCfg['driver'] ?? 'embedding'),
                'with_answer' => $data['with_answer'] ?? false,
                'caller' => 'RagTestController',
                'ui_overrides_removed' => true
            ]);
            
            // 🔍 DEBUG: Log configurazione prima del retrieve
            $ragConfig = app(\App\Services\RAG\TenantRagConfigService::class);
            $hybridConfig = $ragConfig->getHybridConfig($tenantId);
            \Log::error('RAG Tester Hybrid Config', [
                'tenant_id' => $tenantId,
                'neighbor_radius' => $hybridConfig['neighbor_radius'] ?? 'not_set',
                'query' => $finalQuery,
                'kb_service_class' => get_class($kb)
            ]);
            
            // ⏱️ Profiling RAG Retrieval
            $retrievalStart = microtime(true);
            $retrieval = $kb->retrieve($tenantId, $finalQuery, true);
            $retrievalTime = round((microtime(true) - $retrievalStart) * 1000, 2);
            
            // 🔍 DEBUG: Aggiungi sempre al trace
            if (!isset($retrieval['debug'])) {
                $retrieval['debug'] = [];
            }
            $retrieval['debug']['rag_tester_debug'] = [
                'neighbor_radius' => $hybridConfig['neighbor_radius'] ?? 'not_set',
                'kb_service_class' => get_class($kb),
                'query_used' => $finalQuery
            ];
            
            // Aggiungi debug conversazione al trace
            if ($conversationContext) {
                $retrieval['debug']['conversation'] = $conversationContext;
            }
            
        } finally {
            // REMOVE: Non più ripristino config - usiamo solo tenant config
            // Pulizia cache tenant per prossime chiamate
            Cache::forget("rag_config_tenant_{$tenantId}");
        }
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);
        $trace = $retrieval['debug'] ?? null;

        // DEBUG: Log per confronto con Widget
        \Log::info("RAG TESTER CITATIONS", [
            'citations_preview' => array_map(function($c) {
                return [
                    'id' => $c['id'] ?? null,
                    'document_id' => $c['document_id'] ?? null,
                    'chunk_index' => $c['chunk_index'] ?? null,
                    'score' => $c['score'] ?? null,
                    'snippet_length' => mb_strlen($c['snippet'] ?? ''),
                    'chunk_text_length' => mb_strlen($c['chunk_text'] ?? ''),
                    'phones' => $c['phones'] ?? [],
                    'phone' => $c['phone'] ?? null,
                    'email' => $c['email'] ?? null,
                    'title' => mb_substr($c['title'] ?? '', 0, 50),
                    'snippet_preview' => mb_substr($c['snippet'] ?? '', 0, 200),
                ];
            }, array_slice($citations, 0, 8)),
        ]);
        // Aggiungi panoramica configurazione RAG effettiva (per-tenant)
        try {
            $tenantCfgSvc = app(\App\Services\RAG\TenantRagConfigService::class);
            $advCfg = (array) $tenantCfgSvc->getAdvancedConfig($tenantId);
            $rerankCfg = (array) $tenantCfgSvc->getRerankerConfig($tenantId);
            if (is_array($trace)) {
                $trace['rag_config'] = [
                    'reranker_driver' => (string) ($rerankCfg['driver'] ?? 'embedding'),
                    'llm_reranker_enabled' => (bool) (($advCfg['llm_reranker']['enabled'] ?? false) === true),
                    'hyde_enabled' => (bool) (($advCfg['hyde']['enabled'] ?? false) === true),
                ];
            }
        } catch (\Throwable $e) {
            // Ignora errori di introspezione config nel tester
        }
        
        // 🔍 DEBUG: Analizza citazioni per telefoni
        \Log::error('RAG Tester Citations Debug', [
            'tenant_id' => $tenantId,
            'query' => $finalQuery,
            'citations_count' => count($citations),
            'first_citation_snippet_preview' => isset($citations[0]) ? substr($citations[0]['snippet'] ?? '', 0, 200) : 'no_citations',
            'phones_in_first_snippet' => isset($citations[0]) ? (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $citations[0]['snippet'] ?? '', $matches) ? $matches[0] : []) : []
        ]);
        $answer = null;
        if ((bool) ($data['with_answer'] ?? false)) {
            $contextText = '';
            if (!empty($citations)) {
                $contextParts = [];
                foreach ($citations as $c) {
                    $title = $c['title'] ?? ('Doc '.$c['id']);
                    // Usa snippet (con chunk vicini) invece di chunk_text (singolo chunk)
                    $content = trim((string) ($c['snippet'] ?? $c['chunk_text'] ?? ''));
                    $extra = '';
                    if (!empty($c['phone'])) {
                        $extra = "\nTelefono: ".$c['phone'];
                    }
                    if (!empty($c['email'])) {
                        $extra .= "\nEmail: ".$c['email'];
                    }
                    if (!empty($c['address'])) {
                        $extra .= "\nIndirizzo: ".$c['address'];
                    }
                    if (!empty($c['schedule'])) {
                        $extra .= "\nOrario: ".$c['schedule'];
                    }
                    // 🔗 Aggiungi URL fonte per evitare allucinazioni nei link  
                    $sourceInfo = '';
                    if (!empty($c['document_source_url'])) {
                        $sourceInfo = "\n[Fonte: ".$c['document_source_url']."]";
                    }
                    
                    if ($content !== '') {
                        $contextParts[] = "[".$title."]\n".$content.$extra.$sourceInfo;
                    } elseif ($extra !== '') {
                        $contextParts[] = "[".$title."]\n".$extra.$sourceInfo;
                    }
                }
                if ($contextParts !== []) {
                    $rawContext = implode("\n\n---\n\n", $contextParts);
                    // Usa il template personalizzato del tenant se disponibile
                    if ($tenant && !empty($tenant->custom_context_template)) {
                        $contextText = "\n\n" . str_replace('{context}', $rawContext, $tenant->custom_context_template);
                    } else {
                        $contextText = "\n\nContesto (estratti rilevanti):\n".$rawContext;
                    }
                }
            }
            // Costruisci i messaggi utilizzando i prompt personalizzati del tenant
            $messages = [];
            
            // Aggiungi il prompt di sistema personalizzato se disponibile
            if ($tenant && !empty($tenant->custom_system_prompt)) {
                $messages[] = ['role' => 'system', 'content' => $tenant->custom_system_prompt];
            } else {
                $messages[] = ['role' => 'system', 'content' => 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so". 

IMPORTANTE per i link:
- Usa SOLO i titoli esatti delle fonti: [Titolo Esatto](URL_dalla_fonte)
- Se citi una fonte, usa format markdown: [Titolo del documento](URL mostrato in [Fonte: URL])
- NON inventare testi descrittivi per i link (es. evita [Gestione Entrate](url_sbagliato))
- NON creare link se non conosci l\'URL esatto della fonte
- Usa il titolo originale del documento, non descrizioni generiche'];
            }
            
            $messages[] = ['role' => 'user', 'content' => "Domanda: ".$data['query']."\n".$contextText];
            
            $payload = [
                'model' => (string) config('openai.chat_model', 'gpt-4o-mini'),
                'messages' => $messages,
                'max_tokens' => (int) ($data['max_output_tokens'] ?? config('openai.max_output_tokens', 1000)), // ⚡ Increased to prevent link truncation
            ];
            
            // ⏱️ Profiling LLM Generation
            $llmStart = microtime(true);
            $rawResponse = $chat->chatCompletions($payload);
            $llmTime = round((microtime(true) - $llmStart) * 1000, 2);
            $answer = $rawResponse['choices'][0]['message']['content'] ?? '';
            
            // ❌ RIMOSSO: Fonte principale eliminata per evitare link sbagliati
            
            if (is_array($trace)) {
                $trace['llm_context'] = $contextText;
                $trace['llm_messages'] = $payload['messages'];
                $trace['llm_raw_response'] = $rawResponse;
                $trace['tenant_prompts'] = [
                    'custom_system_prompt' => $tenant->custom_system_prompt ?? null,
                    'custom_context_template' => $tenant->custom_context_template ?? null,
                    'using_custom_system' => !empty($tenant->custom_system_prompt),
                    'using_custom_context' => !empty($tenant->custom_context_template),
                ];
                
                // ⏱️ Aggiungi profiling completo al trace
                $totalTime = $retrievalTime + $llmTime;
                $trace['performance_detailed'] = [
                    'total_time_ms' => $totalTime,
                    'retrieval_time_ms' => $retrievalTime,
                    'llm_generation_time_ms' => $llmTime,
                    'retrieval_percentage' => round(($retrievalTime / $totalTime) * 100, 1),
                    'llm_percentage' => round(($llmTime / $totalTime) * 100, 1),
                    'status' => $totalTime < 1000 ? '🚀 Excellent' : 
                               ($totalTime < 2500 ? '✅ Good' : '⚠️ Slow')
                ];
                
                // 🔍 DEBUG: Aggiungi configurazione hybrid al trace
                $trace['rag_tester_debug'] = [
                    'neighbor_radius' => $hybridConfig['neighbor_radius'] ?? 'not_set',
                    'kb_service_class' => get_class($kb),
                    'query_used' => $finalQuery
                ];
            }
        }
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.rag.index', ['tenants' => $tenants, 'result' => compact('citations', 'answer', 'confidence', 'health', 'trace'), 'query' => $data['query'], 'tenant_id' => $tenantId]);
    }

    // ❌ RIMOSSO: getBestSourceUrl() - metodo non più necessario

    /**
     * 🧮 Calcola score intelligente per una citazione considerando tutti i fattori
     */
    private function calculateSmartSourceScore(array $citation, array $allCitations): float
    {
        $score = 0.0;
        $weights = [
            'rag_score' => 0.35,        // Score RAG originale (35%)
            'content_quality' => 0.25,   // Qualità contenuto (25%)
            'intent_match' => 0.20,      // Match intent specifico (20%)
            'source_authority' => 0.15,  // Autorità fonte (15%)
            'semantic_relevance' => 0.05 // Rilevanza semantica (5%)
        ];

        // 1. 📊 RAG Score (vettoriale + BM25 fusion)
        $ragScore = (float) ($citation['score'] ?? 0.0);
        $score += $ragScore * $weights['rag_score'];

        // 2. 📝 Content Quality Score
        $contentQualityScore = $this->calculateContentQualityScore($citation);
        $score += $contentQualityScore * $weights['content_quality'];

        // 3. 🎯 Intent Match Score  
        $intentMatchScore = $this->calculateIntentMatchScore($citation);
        $score += $intentMatchScore * $weights['intent_match'];

        // 4. 🏛️ Source Authority Score
        $sourceAuthorityScore = $this->calculateSourceAuthorityScore($citation);
        $score += $sourceAuthorityScore * $weights['source_authority'];

        // 5. 🔍 Semantic Relevance Score (basato su posizione nella lista)
        $semanticRelevanceScore = $this->calculateSemanticRelevanceScore($citation, $allCitations);
        $score += $semanticRelevanceScore * $weights['semantic_relevance'];

        return $score;
    }

    /**
     * 📝 Calcola score qualità contenuto
     */
    private function calculateContentQualityScore(array $citation): float
    {
        $score = 0.0;
        
        // Lunghezza chunk (contenuto più lungo = più informativo)
        $chunkText = $citation['chunk_text'] ?? $citation['snippet'] ?? '';
        $textLength = mb_strlen($chunkText);
        if ($textLength > 800) $score += 0.3;
        elseif ($textLength > 400) $score += 0.2;
        elseif ($textLength > 200) $score += 0.1;

        // Completezza snippet
        $snippet = $citation['snippet'] ?? '';
        if (mb_strlen($snippet) > 150) $score += 0.2;

        // Presenza title significativo
        $title = $citation['title'] ?? '';
        if (!empty($title) && mb_strlen($title) > 10) $score += 0.2;

        // Contenuto strutturato (evidenze di lista, paragrafi, etc.)
        if (preg_match('/[-•·]\s+|\d+\.\s+|\n\s*\n/', $chunkText)) $score += 0.15;

        // Presenza informazioni specifiche
        if (preg_match('/\b(?:telefono|email|indirizzo|orari?|contatti?)\b/i', $chunkText)) $score += 0.15;

        return min(1.0, $score); // Cap a 1.0
    }

    /**
     * 🎯 Calcola score match intent specifico
     */
    private function calculateIntentMatchScore(array $citation): float
    {
        $score = 0.0;
        
        // Campi intent specifici presenti
        $intentFields = ['phone', 'email', 'address', 'schedule'];
        foreach ($intentFields as $field) {
            if (!empty($citation[$field])) {
                $score += 0.25; // 25% per ogni tipo intent
            }
        }

        // Bonus se ha più tipi di contatto
        $contactTypes = array_filter($intentFields, fn($f) => !empty($citation[$f]));
        if (count($contactTypes) >= 2) $score += 0.1;

        // Pattern content matching
        $chunkText = $citation['chunk_text'] ?? $citation['snippet'] ?? '';
        
        // Telefoni
        if (preg_match('/(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/', $chunkText)) $score += 0.1;
        
        // Email
        if (preg_match('/@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $chunkText)) $score += 0.1;
        
        // Indirizzi
        if (preg_match('/\b(?:via|viale|piazza|corso|largo)\s+[A-Z]/i', $chunkText)) $score += 0.1;
        
        // Orari strutturati
        if (preg_match('/\d{1,2}[:\.]?\d{0,2}\s*[-–—]\s*\d{1,2}[:\.]?\d{0,2}/', $chunkText)) $score += 0.1;

        return min(1.0, $score); // Cap a 1.0
    }

    /**
     * 🏛️ Calcola score autorità fonte
     */
    private function calculateSourceAuthorityScore(array $citation): float
    {
        $score = 0.0;
        
        $sourceUrl = $citation['document_source_url'] ?? '';
        $documentType = $citation['document_type'] ?? '';
        $title = $citation['title'] ?? '';

        // Bonus per domini governativi/istituzionali
        if (preg_match('/\.(gov|edu|org|comune\.|provincia\.|regione\.)/i', $sourceUrl)) {
            $score += 0.4;
        }

        // Bonus per tipo documento
        switch (strtolower($documentType)) {
            case 'pdf': $score += 0.2; break;  // PDF spesso più formali
            case 'doc':
            case 'docx': $score += 0.15; break;
            case 'txt':
            case 'md': $score += 0.1; break;
        }

        // Bonus per URL con path specifici (non homepage)
        if (preg_match('/\/[^\/]+\/[^\/]+/', $sourceUrl)) {
            $score += 0.15;
        }

        // Bonus per titoli istituzionali
        if (preg_match('/\b(?:comune|provincia|regione|ufficio|servizio|informazioni)\b/i', $title)) {
            $score += 0.15;
        }

        // Malus per URL molto lunghe (spesso auto-generate)
        if (mb_strlen($sourceUrl) > 150) {
            $score -= 0.1;
        }

        return max(0.0, min(1.0, $score)); // Tra 0 e 1
    }

    /**
     * 🔍 Calcola score rilevanza semantica (basato su posizione)
     */
    private function calculateSemanticRelevanceScore(array $citation, array $allCitations): float
    {
        $citationId = $citation['id'] ?? 0;
        $chunkIndex = $citation['chunk_index'] ?? 0;
        
        // Trova posizione nella lista (posizioni più alte = più rilevanti)
        foreach ($allCitations as $index => $c) {
            if (($c['id'] ?? 0) === $citationId && ($c['chunk_index'] ?? 0) === $chunkIndex) {
                $position = $index + 1;
                $totalCitations = count($allCitations);
                
                // Score inverso: primi risultati hanno score più alto
                return max(0.0, 1.0 - ($position - 1) / max(1, $totalCitations - 1));
            }
        }
        
        return 0.5; // Default se non trovato
    }

    /**
     * 📊 Ottieni breakdown dettagliato del score per debugging
     */
    private function getScoreBreakdown(array $citation, array $allCitations): array
    {
        return [
            'rag_score' => round((float) ($citation['score'] ?? 0.0), 3),
            'content_quality' => round($this->calculateContentQualityScore($citation), 3),
            'intent_match' => round($this->calculateIntentMatchScore($citation), 3),
            'source_authority' => round($this->calculateSourceAuthorityScore($citation), 3),
            'semantic_relevance' => round($this->calculateSemanticRelevanceScore($citation, $allCitations), 3),
            'document_type' => $citation['document_type'] ?? 'unknown',
            'title_preview' => mb_substr($citation['title'] ?? '', 0, 50),
            'content_length' => mb_strlen($citation['chunk_text'] ?? $citation['snippet'] ?? ''),
        ];
    }
}

