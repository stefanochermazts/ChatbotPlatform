<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\ContextBuilder;
use App\Services\RAG\ConversationContextEnhancer;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class ChatCompletionsController extends Controller
{
    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly KbSearchService $kb,
        private readonly ContextBuilder $ctx,
        private readonly ConversationContextEnhancer $conversationEnhancer,
        private readonly TenantRagConfigService $tenantConfig,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $requestStartTime = microtime(true);
        $profiling = ['request_start' => $requestStartTime, 'steps' => []];
        $tenantId = (int) $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'model' => ['required', 'string', 'max:128'],
            'messages' => ['required', 'array'],
            'temperature' => ['nullable', 'numeric'],
            'stream' => ['nullable', 'boolean'],
            'tools' => ['nullable', 'array'],
            'tool_choice' => ['nullable'],
            'response_format' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::query()->find($tenantId);
        $queryText = $this->extractUserQuery($validated['messages']);
        
        // Conversational enhancement solo per retrieval (come nel tester)
        $conversationContext = null;
        $finalQuery = $queryText;
        if ($this->conversationEnhancer->isEnabled() && count($validated['messages']) > 1) {
            $conversationContext = $this->conversationEnhancer->enhanceQuery(
                $queryText,
                $validated['messages'],
                $tenantId
            );
            if ($conversationContext['context_used']) {
                $finalQuery = $conversationContext['enhanced_query'];
            }
        }

        // Usa la stessa configurazione del RAG Tester (per-tenant)
        $kb = $this->kb;

        // 🔍 LOG: Configurazioni RAG applicate
        \Log::info('ChatCompletionsController RAG Config', [
            'tenant_id' => $tenantId,
            'original_query' => $queryText,
            'final_query' => $finalQuery,
            'conversation_enhanced' => $conversationContext ? $conversationContext['context_used'] : false,
            'hyde_enabled' => config('rag.advanced.hyde.enabled'),
            'reranker_driver' => config('rag.reranker.driver'),
            'min_citations' => config('rag.answer.min_citations'),
            'min_confidence' => config('rag.answer.min_confidence'),
            'force_if_has_citations' => config('rag.answer.force_if_has_citations'),
            'caller' => 'ChatCompletionsController'
        ]);

        // REMOVE: Non più override nel widget - usa solo tenant config
        
        // Retrieval come nel RAG tester (usa la query originale per intent detection)
        $stepStart = microtime(true);
        $retrieval = $kb->retrieve($tenantId, $queryText, true);
        $profiling['steps']['rag_retrieval'] = microtime(true) - $stepStart;
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);
        
        // DEBUG: Leggi configurazione RAG da tenant config
        $tenantCfgSvc = app(\App\Services\RAG\TenantRagConfigService::class);
        $advCfg = (array) $tenantCfgSvc->getAdvancedConfig($tenantId);
        $rerankCfg = (array) $tenantCfgSvc->getRerankerConfig($tenantId);
        $hybridCfg = (array) $tenantCfgSvc->getHybridConfig($tenantId);
        
        \Log::info("WIDGET RAG DEBUG START", [
            'tenant_id' => $tenantId,
            'query' => $queryText,
            'citations_count' => count($citations),
            'confidence' => $confidence,
            'tenant_config' => [
                'hyde_enabled' => (bool) (($advCfg['hyde']['enabled'] ?? false) === true),
                'reranker_driver' => (string) ($rerankCfg['driver'] ?? 'embedding'),
                'source' => 'TenantRagConfigService'
            ]
        ]);

        // Debug esteso per tracciare configurazioni e comportamento
        $debug = $retrieval['debug'] ?? [];
        $debug['query_info'] = [
            'original_query' => $queryText,
            'final_query' => $finalQuery,
            'conversation_used' => $conversationContext ? $conversationContext['context_used'] : false,
        ];
        
        $debug['rag_config'] = [
            'hyde_enabled' => (bool) (($advCfg['hyde']['enabled'] ?? false) === true),
            'reranker_driver' => (string) ($rerankCfg['driver'] ?? 'embedding'),
            'vector_top_k' => (int) ($hybridCfg['vector_top_k'] ?? 30),
            'bm25_top_k' => (int) ($hybridCfg['bm25_top_k'] ?? 50),
            'mmr_take' => (int) ($hybridCfg['mmr_take'] ?? 8),
            'mmr_lambda' => (float) ($hybridCfg['mmr_lambda'] ?? 0.3),
            'neighbor_radius' => (int) ($hybridCfg['neighbor_radius'] ?? 1),
            'reranker_top_n' => (int) ($rerankCfg['top_n'] ?? 10),
            'source' => 'TenantRagConfigService',
        ];
        $debug['kb_info'] = [
            'selected_kb' => $retrieval['debug']['selected_kb'] ?? null,
            'tenant_id' => $tenantId,
        ];
        $retrieval['debug'] = $debug;

        // Costruzione contextText come nel RAG tester
        $stepStart = microtime(true);
        $contextText = $this->buildRagTesterContextText($tenant, $queryText, $citations);
        $profiling['steps']['context_building'] = microtime(true) - $stepStart;

        // DEBUG: Log citazioni e contesto
        \Log::info("WIDGET RAG CITATIONS", [
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
            'context_length' => mb_strlen($contextText),
            'context_preview' => mb_substr($contextText, 0, 500),
        ]);

        // Costruisci payload partendo dai messaggi forniti, ma inserendo system prompt e context come nel tester
        $payload = $validated;

        // Converti parametri numerici da stringhe a numeri
        if (isset($payload['temperature'])) {
            $payload['temperature'] = (float) $payload['temperature'];
        }
        if (isset($payload['max_tokens'])) {
            $payload['max_tokens'] = (int) $payload['max_tokens'];
        }
        if (isset($payload['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = (int) $payload['max_completion_tokens'];
        }

        // 🚀 CONFIGURAZIONE WIDGET: Usa configurazione per-tenant
        $widgetConfig = $this->tenantConfig->getWidgetConfig($tenantId);
        
        // Modello: prima da request, poi da widget config, infine da global config
        if (!isset($payload['model'])) {
            $payload['model'] = $widgetConfig['model'] ?? config('openai.chat_model', 'gpt-4o-mini');
        }
        
        // Temperature: da widget config se non specificata
        if (!isset($payload['temperature'])) {
            $payload['temperature'] = (float) ($widgetConfig['temperature'] ?? 0.2);
        }
        
        // Max tokens: da widget config se non specificato
        if (!isset($payload['max_tokens']) && !isset($payload['max_completion_tokens'])) {
            $payload['max_tokens'] = (int) ($widgetConfig['max_tokens'] ?? 800);
        }

        // Inserisci system prompt: custom del tenant oppure default come nel tester
        $systemPrompt = $tenant && !empty($tenant->custom_system_prompt)
            ? $tenant->custom_system_prompt
            : 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se il contesto contiene tabelle, estrai e formatta i dati in modo chiaro e leggibile. Se non sono sufficienti, rispondi: "Non lo so". Riporta sempre le fonti (titoli) usate.';
        $payload['messages'] = array_merge([
            ['role' => 'system', 'content' => $systemPrompt],
        ], $payload['messages']);

        // Appendi il contextText all'ultimo messaggio user, prefissando "Domanda: ..."
        for ($i = count($payload['messages']) - 1; $i >= 0; $i--) {
            if (($payload['messages'][$i]['role'] ?? '') === 'user') {
                $original = (string) ($payload['messages'][$i]['content'] ?? $queryText);
                $payload['messages'][$i]['content'] = 'Domanda: '.$queryText.(
                    $contextText !== '' ? "\n".$contextText : ''
                );
                break;
            }
        }

        // DEBUG: Log payload finale all'LLM
        \Log::info("WIDGET RAG LLM PAYLOAD", [
            'model' => $payload['model'] ?? null,
            'temperature' => $payload['temperature'] ?? null,
            'max_tokens' => $payload['max_tokens'] ?? null,
            'system_prompt' => $payload['messages'][0]['content'] ?? null,
            'user_message_length' => mb_strlen($payload['messages'][count($payload['messages'])-1]['content'] ?? ''),
            'user_message_preview' => mb_substr($payload['messages'][count($payload['messages'])-1]['content'] ?? '', 0, 300),
        ]);

        // Non aggiungiamo ulteriori system messages legati a expansion
        $stepStart = microtime(true);
        $result = $this->chat->chatCompletions($payload);
        $profiling['steps']['llm_completion'] = microtime(true) - $stepStart;

        // Mantieni salvaguardia contro output più povero se era presente un'expansion (ma non sovrascrivere per schedule)
        if (!empty($retrieval['response_text'])) {
            $expansionText = (string) $retrieval['response_text'];
            $expectsAddress = str_contains($expansionText, '📍') || str_contains($expansionText, 'Indirizzo');
            $expectsPhone   = str_contains($expansionText, '📞') || str_contains($expansionText, 'Telefono');
            $expectsEmail   = str_contains($expansionText, '📧') || str_contains($expansionText, 'Email');
            $expectsHours   = str_contains($expansionText, '🕒') || str_contains($expansionText, 'Orari');
            $finalContent = (string) ($result['choices'][0]['message']['content'] ?? '');
            $hasAddress = str_contains($finalContent, '📍') || str_contains($finalContent, 'Indirizzo');
            $hasPhone   = str_contains($finalContent, '📞') || str_contains($finalContent, 'Telefono');
            $hasEmail   = str_contains($finalContent, '📧') || str_contains($finalContent, 'Email');
            $hasHours   = str_contains($finalContent, '🕒') || str_contains($finalContent, 'Orari');
            $isScheduleFocus = $expectsHours && !$expectsAddress && !$expectsPhone && !$expectsEmail;
            $missing = ($expectsAddress && !$hasAddress) || ($expectsPhone && !$hasPhone) || ($expectsEmail && !$hasEmail) || ($expectsHours && !$hasHours);
            if ($missing && !$isScheduleFocus) {
                $result['choices'][0]['message']['content'] = $expansionText;
            }
        }

        // Fallback controllato (stesse soglie del tester/per-tenant)
        $minCit = (int) config('rag.answer.min_citations', 1);
        $minConf = (float) config('rag.answer.min_confidence', 0.05);
        $forceIfHas = (bool) config('rag.answer.force_if_has_citations', true);
        if ((count($citations) < $minCit || $confidence < $minConf) && !($forceIfHas && count($citations) > 0)) {
            $fallback = (string) config('rag.answer.fallback_message');
            $result['choices'][0]['message']['content'] = $fallback;
        }

        // 🆕 Aggiungi source_url del documento con confidenza più alta se disponibile
        $bestSourceUrl = $this->getBestSourceUrl($citations);
        if (!empty(trim($bestSourceUrl)) && count($citations) > 0) {
            $currentContent = (string) ($result['choices'][0]['message']['content'] ?? '');
            // Aggiungi il link solo se la risposta non è un fallback
            if ($currentContent !== (string) config('rag.answer.fallback_message')) {
                $result['choices'][0]['message']['content'] = $currentContent . "\n\n🔗 **Fonte principale**: " . trim($bestSourceUrl);
            }
        }

        // DEBUG: Log risposta finale LLM 
        \Log::info("WIDGET RAG LLM RESPONSE", [
            'llm_response_length' => mb_strlen($result['choices'][0]['message']['content'] ?? ''),
            'llm_response_preview' => mb_substr($result['choices'][0]['message']['content'] ?? '', 0, 300),
            'fallback_applied' => ($result['choices'][0]['message']['content'] ?? '') === (string) config('rag.answer.fallback_message'),
            'final_citations_count' => count($citations),
            'final_confidence' => $confidence,
        ]);

        $result['citations'] = $citations;
        $result['retrieval'] = [ 'confidence' => $confidence ];
        if ($conversationContext && $conversationContext['context_used']) {
            $result['conversation_debug'] = [
                'original_query' => $conversationContext['original_query'],
                'enhanced_query' => $conversationContext['enhanced_query'],
                'conversation_summary' => $conversationContext['conversation_summary'],
                'processing_time_ms' => $conversationContext['processing_time_ms'],
                'context_used' => true,
            ];
        }

        // 📊 PROFILAZIONE COMPLETA WIDGET
        $totalRequestTime = microtime(true) - $requestStartTime;
        $profiling['total_request_time'] = $totalRequestTime;
        
        \Log::info("📊 [WIDGET PROFILING] Complete Request Breakdown", [
            'tenant_id' => $tenantId,
            'query' => $queryText,
            'total_request_time_ms' => round($totalRequestTime * 1000, 2),
            'profiling_steps' => array_map(function($time) {
                return round($time * 1000, 2) . 'ms';
            }, $profiling['steps'] ?? []),
            'response_length' => mb_strlen($result['choices'][0]['message']['content'] ?? ''),
            'citations_count' => count($citations)
        ]);
        
        \Log::info("WIDGET RAG DEBUG END");
        return response()->json($result);
    }

    private function extractUserQuery(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) === 'user') {
                return (string) ($messages[$i]['content'] ?? '');
            }
        }
        return '';
    }

    private function forceAdvancedRagConfiguration(): \App\Services\RAG\KbSearchService
    {
        // Allineato al RAG Tester: nessun override forzato; si usano i valori per-tenant
        return $this->kb;
    }

    private function buildRagTesterContextText(?Tenant $tenant, string $query, array $citations): string
    {
        // 🚀 CONFIGURAZIONE WIDGET: Usa configurazione per-tenant
        $widgetConfig = $this->tenantConfig->getWidgetConfig($tenant->id ?? 0);
        $maxCitationChars = (int) ($widgetConfig['max_citation_chars'] ?? 2000);
        $maxContextChars = (int) ($widgetConfig['max_context_chars'] ?? 15000);
        $enableTruncation = (bool) ($widgetConfig['enable_context_truncation'] ?? true);
        
        $contextText = '';
        if (!empty($citations)) {
            $contextParts = [];
            foreach ($citations as $c) {
                $title = $c['title'] ?? ('Doc '.$c['id']);
                // Usa snippet con limite configurabile per citation
                $content = trim((string) ($c['snippet'] ?? $c['chunk_text'] ?? ''));
                
                // 🔧 FIX UTF-8: Pulisci caratteri malformati per evitare json_encode errors
                $content = $this->cleanUtf8($content);
                $title = $this->cleanUtf8($title);
                
                if ($enableTruncation && strlen($content) > $maxCitationChars) {
                    $content = substr($content, 0, $maxCitationChars) . '...';
                }
                $extra = '';
                if (!empty($c['phone'])) {
                    $extra = "\nTelefono: " . $this->cleanUtf8($c['phone']);
                }
                if (!empty($c['email'])) {
                    $extra .= "\nEmail: " . $this->cleanUtf8($c['email']);
                }
                if (!empty($c['address'])) {
                    $extra .= "\nIndirizzo: " . $this->cleanUtf8($c['address']);
                }
                if (!empty($c['schedule'])) {
                    $extra .= "\nOrario: " . $this->cleanUtf8($c['schedule']);
                }
                if ($content !== '') {
                    $contextParts[] = "[".$title."]\n".$content.$extra;
                } elseif ($extra !== '') {
                    $contextParts[] = "[".$title."]\n".$extra;
                }
            }
            if ($contextParts !== []) {
                $rawContext = implode("\n\n---\n\n", $contextParts);
                
                // 🚀 CONFIGURAZIONE WIDGET: Limita contesto totale basato su config
                if ($enableTruncation && strlen($rawContext) > $maxContextChars) {
                    $rawContext = substr($rawContext, 0, $maxContextChars) . "\n\n[...contesto troncato per performance...]";
                }
                
                // Usa il template personalizzato del tenant se disponibile
                if ($tenant && !empty($tenant->custom_context_template)) {
                    $contextText = "\n\n" . str_replace('{context}', $rawContext, $tenant->custom_context_template);
                } else {
                    $contextText = "\n\nContesto (estratti rilevanti):\n".$rawContext;
                }
            }
        }
        return $contextText;
    }

    /**
     * Pulisce caratteri UTF-8 malformati per evitare errori json_encode
     */
    private function cleanUtf8(string $text): string
    {
        // Converte caratteri malformati UTF-8 in caratteri validi
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Rimuove caratteri di controllo non stampabili (tranne newline e tab)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Fix comuni per caratteri malformati dall'encoding
        $replacements = [
            'â€™' => "'",      // apostrofo
            'Ã ' => 'à',       // a con accento grave
            'Ã¨' => 'è',       // e con accento grave  
            'Ã©' => 'é',       // e con accento acuto
            'Ã¬' => 'ì',       // i con accento grave
            'Ã¯' => 'ï',       // i con dieresi
            'Ã²' => 'ò',       // o con accento grave
            'Ã¹' => 'ù',       // u con accento grave
            'â€œ' => '"',       // virgolette aperte
            'â€' => '"',        // virgolette chiuse
            'â€"' => '-',       // trattino lungo
            'â€¦' => '...',     // ellissi
            'lâ' => "l'",       // apostrofo specifico del log
            'sarÃ ' => 'sarà',  // caso specifico del log
        ];
        
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);
        
        // Assicurati che sia UTF-8 valido
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = utf8_encode($text);
        }
        
        return $text;
    }

    // RIMOSSO: Metodi di prioritizzazione personalizzati - usa logica RAG Tester

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






