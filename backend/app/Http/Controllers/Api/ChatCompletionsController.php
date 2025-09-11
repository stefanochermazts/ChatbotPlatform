<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\ContextBuilder;
use App\Services\RAG\ConversationContextEnhancer;
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
    ) {}

    public function create(Request $request): JsonResponse
    {
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

        // FORZA configurazioni veloci per widget PRIMA di qualsiasi operazione
        $this->forceAdvancedRagConfiguration();
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

        // Retrieval come nel RAG tester (usa la query originale per intent detection)
        $retrieval = $kb->retrieve($tenantId, $queryText, true);
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);

        // Debug esteso per tracciare configurazioni e comportamento
        $debug = $retrieval['debug'] ?? [];
        $debug['query_info'] = [
            'original_query' => $queryText,
            'final_query' => $finalQuery,
            'conversation_used' => $conversationContext ? $conversationContext['context_used'] : false,
        ];
        $debug['rag_config'] = [
            'hyde_enabled' => config('rag.advanced.hyde.enabled'),
            'reranker_driver' => config('rag.reranker.driver'),
            'vector_top_k' => config('rag.hybrid.vector_top_k'),
            'bm25_top_k' => config('rag.hybrid.bm25_top_k'),
            'mmr_take' => config('rag.hybrid.mmr_take'),
            'mmr_lambda' => config('rag.hybrid.mmr_lambda'),
            'neighbor_radius' => config('rag.hybrid.neighbor_radius'),
            'reranker_top_n' => config('rag.reranker.top_n'),
        ];
        $debug['kb_info'] = [
            'selected_kb' => $retrieval['debug']['selected_kb'] ?? null,
            'tenant_id' => $tenantId,
        ];
        $retrieval['debug'] = $debug;

        // Costruzione contextText come nel RAG tester
        $contextText = $this->buildRagTesterContextText($tenant, $queryText, $citations);

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

        // Modello da config (.env OPENAI_CHAT_MODEL)
        $payload['model'] = (string) config('openai.chat_model', 'gpt-4o-mini');

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

        // Non aggiungiamo ulteriori system messages legati a expansion
        $result = $this->chat->chatCompletions($payload);

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

        // Fallback controllato
        $minCit = (int) config('rag.answer.min_citations', 2);
        $minConf = (float) config('rag.answer.min_confidence', 0.15);
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
        // Configurazioni bilanciate per il widget (meno aggressive del tester)
        // Disabilita HyDE per evitare timeout con OpenAI
        Config::set('rag.advanced.hyde.enabled', false);
        
        // DISABILITA completamente il reranker per velocità massima nel widget
        Config::set('rag.features.reranker', false);
        
        // Usa configurazioni veloci per il widget (ridotte per performance)
        Config::set('rag.hybrid.vector_top_k', 20);  // Ridotto da 120 a 20
        Config::set('rag.hybrid.bm25_top_k', 30);    // Ridotto da 200 a 30
        Config::set('rag.hybrid.mmr_take', 5);       // Ridotto da 8 a 5
        Config::set('rag.hybrid.mmr_lambda', 0.3);
        Config::set('rag.hybrid.neighbor_radius', 1);
        // Config::set('rag.reranker.top_n', 15);    // Non necessario se reranker disabilitato
        
        // Parametri LLM e fallback più permissivi
        Config::set('rag.answer.min_citations', 1);
        Config::set('rag.answer.min_confidence', 0.1);
        Config::set('rag.answer.force_if_has_citations', true);
        Config::set('rag.context.max_chars', 8000);
        Config::set('rag.context.compress_if_over_chars', 1200);
        Config::set('rag.context.compress_target_chars', 600);
        
        // Usa il KbSearchService esistente (no HyDE per evitare timeout)
        return $this->kb;
    }

    private function buildRagTesterContextText(?Tenant $tenant, string $query, array $citations): string
    {
        $contextText = '';
        if (!empty($citations)) {
            $parts = [];
            foreach ($citations as $c) {
                $title = $c['title'] ?? ('Doc '.($c['id'] ?? ''));
                // Usa chunk_text (contenuto completo) se disponibile, altrimenti snippet
                $content = trim((string) ($c['chunk_text'] ?? $c['snippet'] ?? ''));
                $extra = '';
                if (!empty($c['phone'])) { $extra .= ($extra ? "\n" : '').'Telefono: '.$c['phone']; }
                if (!empty($c['email'])) { $extra .= ($extra ? "\n" : '').'Email: '.$c['email']; }
                if (!empty($c['address'])) { $extra .= ($extra ? "\n" : '').'Indirizzo: '.$c['address']; }
                if (!empty($c['schedule'])) { $extra .= ($extra ? "\n" : '').'Orario: '.$c['schedule']; }
                if ($content !== '') {
                    $parts[] = '['.$title."]\n".$content.($extra !== '' ? "\n".$extra : '');
                } elseif ($extra !== '') {
                    $parts[] = '['.$title."]\n".$extra;
                }
            }
            if ($parts !== []) {
                $raw = implode("\n\n---\n\n", $parts);
                $contextText = "Contesto (estratti rilevanti):\n".$raw;
            }
        }
        return $contextText;
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





