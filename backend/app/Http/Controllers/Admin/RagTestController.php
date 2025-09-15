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
            
            $retrieval = $kb->retrieve($tenantId, $finalQuery, true);
            
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
                    if ($content !== '') {
                        $contextParts[] = "[".$title."]\n".$content.$extra;
                    } elseif ($extra !== '') {
                        $contextParts[] = "[".$title."]\n".$extra;
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
                $messages[] = ['role' => 'system', 'content' => 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so". Riporta sempre le fonti (titoli) usate.'];
            }
            
            $messages[] = ['role' => 'user', 'content' => "Domanda: ".$data['query']."\n".$contextText];
            
            $payload = [
                'model' => (string) config('openai.chat_model', 'gpt-4o-mini'),
                'messages' => $messages,
                'max_tokens' => (int) ($data['max_output_tokens'] ?? config('openai.max_output_tokens', 700)),
            ];
            $rawResponse = $chat->chatCompletions($payload);
            $answer = $rawResponse['choices'][0]['message']['content'] ?? '';
            
            // 🆕 Aggiungi source_url del documento con confidenza più alta se disponibile
            $bestSourceUrl = $this->getBestSourceUrl($citations);
            if (!empty(trim($bestSourceUrl)) && count($citations) > 0 && $answer !== '') {
                $answer .= "\n\n🔗 **Fonte principale**: " . trim($bestSourceUrl);
            }
            
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

    /**
     * Trova il source_url del documento con la confidenza più alta
     */
    private function getBestSourceUrl(array $citations): ?string
    {
        if (empty($citations)) {
            return null;
        }

        $bestCitation = null;
        $bestScore = -1;

        foreach ($citations as $citation) {
            // Usa il campo score se disponibile, altrimenti usa 1.0 come default
            $score = (float) ($citation['score'] ?? 1.0);
            
            if ($score > $bestScore && !empty($citation['document_source_url'])) {
                $bestScore = $score;
                $bestCitation = $citation;
            }
        }

        return $bestCitation['document_source_url'] ?? null;
    }
}

