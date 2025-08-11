<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\KbSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatCompletionsController extends Controller
{
    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly KbSearchService $kb,
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

        $queryText = $this->extractUserQuery($validated['messages']);
        $citations = $this->kb->retrieveCitations($tenantId, $queryText, 3);

        $payload = $validated;
        $payload['__citations'] = $citations;

        $result = $this->chat->chatCompletions($payload);

        // Mappa citazioni nella risposta (estensione non-breaking)
        $result['citations'] = $citations;

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
}





