<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\ContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatCompletionsController extends Controller
{
    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly KbSearchService $kb,
        private readonly ContextBuilder $ctx,
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

        // Carica il tenant per accedere ai prompt personalizzati
        $tenant = Tenant::query()->find($tenantId);

        $queryText = $this->extractUserQuery($validated['messages']);
        $retrieval = $this->kb->retrieve($tenantId, $queryText);
        $citations = $retrieval['citations'] ?? [];
        $confidence = (float) ($retrieval['confidence'] ?? 0.0);

        // Costruisci il contesto compresso/deduplicato
        $built = $this->ctx->build($citations);
        $context = (string) ($built['context'] ?? '');
        $sources = (array) ($built['sources'] ?? []);

        $payload = $validated;
        $payload['__citations'] = $citations;
        
        // Usa template personalizzato per il contesto se configurato
        if ($context !== '') {
            $contextMessage = $this->buildContextMessage($tenant, $context);
            $payload['messages'] = array_merge([$contextMessage], $payload['messages']);
        }

        // Aggiungi prompt di sistema personalizzato se configurato
        if ($tenant && !empty($tenant->custom_system_prompt)) {
            $payload['messages'] = array_merge([
                ['role' => 'system', 'content' => $tenant->custom_system_prompt],
            ], $payload['messages']);
        }

        $result = $this->chat->chatCompletions($payload);

        // Fallback “Non lo so” se confidenza/citazioni insufficienti
        $minCit = (int) config('rag.answer.min_citations', 2);
        $minConf = (float) config('rag.answer.min_confidence', 0.15);
        $forceIfHas = (bool) config('rag.answer.force_if_has_citations', true);
        if ((count($citations) < $minCit || $confidence < $minConf) && !($forceIfHas && count($citations) > 0)) {
            $fallback = (string) config('rag.answer.fallback_message');
            $result['choices'][0]['message']['content'] = $fallback;
        }

        $result['citations'] = $citations;
        $result['retrieval'] = [ 'confidence' => $confidence, 'sources' => $sources ];

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

    /**
     * Costruisce il messaggio di contesto utilizzando il template personalizzato del tenant
     */
    private function buildContextMessage(?Tenant $tenant, string $context): array
    {
        if ($tenant && !empty($tenant->custom_context_template)) {
            // Sostituisci il placeholder {context} nel template personalizzato
            $content = str_replace('{context}', $context, $tenant->custom_context_template);
        } else {
            // Usa il template di default
            $content = 'Contesto della knowledge base (compresso):\n'.$context;
        }

        return ['role' => 'system', 'content' => $content];
    }
}





