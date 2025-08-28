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

class RagTestController extends Controller
{
    public function index()
    {
        $tenants = Tenant::orderBy('name')->get();
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
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
            'mmr_lambda' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'max_output_tokens' => ['nullable', 'integer', 'min:32', 'max:8192'],
        ]);
        $tenantId = (int) $data['tenant_id'];
        $tenant = Tenant::find($tenantId);
        $health = $milvus->health();
        
        // Gestisci configurazioni temporanee per test
        $originalHydeConfig = config('rag.advanced.hyde.enabled');
        $originalRerankerDriver = config('rag.reranker.driver');
        $originalConversationConfig = config('rag.conversation.enabled');
        
        $hydeEnabled = (bool) ($data['enable_hyde'] ?? false);
        $conversationEnabled = (bool) ($data['enable_conversation'] ?? false);
        $rerankerDriver = $data['reranker_driver'] ?? 'embedding';
        
        // Parse conversation messages se forniti
        $conversationMessages = [];
        if ($conversationEnabled && !empty($data['conversation_messages'])) {
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
            // Applica configurazioni temporanee
            if ($hydeEnabled) {
                Config::set('rag.advanced.hyde.enabled', true);
            }
            
            if ($conversationEnabled) {
                Config::set('rag.conversation.enabled', true);
            }
            
            if ($rerankerDriver !== $originalRerankerDriver) {
                Config::set('rag.reranker.driver', $rerankerDriver);
            }
            
            // Crea KbSearchService con configurazioni aggiornate
            if ($hydeEnabled) {
                $hyde = app(HyDEExpander::class);
                $kb = app()->makeWith(KbSearchService::class, ['hyde' => $hyde]);
            }
            
            // Gestione query con contesto conversazionale
            $finalQuery = $data['query'];
            $conversationContext = null;
            
            if ($conversationEnabled && !empty($conversationMessages)) {
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
            
            // 🔍 LOG: Configurazioni RAG Tester
            \Log::info('RagTestController RAG Config', [
                'tenant_id' => $tenantId,
                'original_query' => $data['query'],
                'final_query' => $finalQuery,
                'conversation_enabled' => $conversationEnabled,
                'conversation_enhanced' => $conversationContext ? $conversationContext['context_used'] : false,
                'hyde_enabled' => Config::get('rag.advanced.hyde.enabled'),
                'reranker_driver' => Config::get('rag.reranker.driver'),
                'with_answer' => $data['with_answer'] ?? false,
                'caller' => 'RagTestController'
            ]);
            
            $retrieval = $kb->retrieve($tenantId, $finalQuery, true);
            
            // Aggiungi debug conversazione al trace
            if ($conversationContext) {
                $retrieval['debug']['conversation'] = $conversationContext;
            }
            
        } finally {
            // Ripristina configurazioni originali
            Config::set('rag.advanced.hyde.enabled', $originalHydeConfig);
            Config::set('rag.reranker.driver', $originalRerankerDriver);
            Config::set('rag.conversation.enabled', $originalConversationConfig);
        }
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);
        $trace = $retrieval['debug'] ?? null;
        $answer = null;
        if ((bool) ($data['with_answer'] ?? false)) {
            $contextText = '';
            if (!empty($citations)) {
                $contextParts = [];
                foreach ($citations as $c) {
                    $title = $c['title'] ?? ('Doc '.$c['id']);
                    $snippet = trim((string) ($c['snippet'] ?? ''));
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
                    if ($snippet !== '') {
                        $contextParts[] = "[".$title."]\n".$snippet.$extra;
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
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => (int) ($data['max_output_tokens'] ?? config('openai.max_output_tokens', 700)),
            ];
            $answer = $chat->chatCompletions($payload)['choices'][0]['message']['content'] ?? '';
            
            // 🆕 Aggiungi source_url del documento con confidenza più alta se disponibile
            $bestSourceUrl = $this->getBestSourceUrl($citations);
            if (!empty(trim($bestSourceUrl)) && count($citations) > 0 && $answer !== '') {
                $answer .= "\n\n🔗 **Fonte principale**: " . trim($bestSourceUrl);
            }
            
            if (is_array($trace)) {
                $trace['llm_context'] = $contextText;
                $trace['llm_messages'] = $payload['messages'];
                $trace['tenant_prompts'] = [
                    'custom_system_prompt' => $tenant->custom_system_prompt ?? null,
                    'custom_context_template' => $tenant->custom_context_template ?? null,
                    'using_custom_system' => !empty($tenant->custom_system_prompt),
                    'using_custom_context' => !empty($tenant->custom_context_template),
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

