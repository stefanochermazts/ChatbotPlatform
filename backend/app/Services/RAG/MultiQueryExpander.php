<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIChatService;

class MultiQueryExpander
{
    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly TenantRagConfigService $tenantConfig = new TenantRagConfigService,
    ) {}

    /**
     * Genera fino a N parafrasi brevi della query utente.
     * Ritorna l'elenco query: [originale, parafrasi...]
     */
    public function expand(int $tenantId, string $query): array
    {
        $cfg = (array) $this->tenantConfig->getMultiQueryConfig($tenantId);
        $enabled = (bool) ($cfg['enabled'] ?? true);
        $num = max(0, (int) ($cfg['num'] ?? 2));
        if (! $enabled || $num === 0 || trim($query) === '') {
            return [$query];
        }

        $model = (string) ($cfg['model'] ?? 'gpt-4o-mini');
        $temperature = (float) ($cfg['temperature'] ?? 0.3);

        $prompt = 'Riscrivi la seguente domanda in '.$num.' varianti brevi e diverse tra loro (senza numeri, una per riga), massimizzando la copertura semantica. Domanda: '.$query;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Sei un assistente che genera parafrasi di query.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
        ];

        $res = $this->chat->chatCompletions($payload);
        $text = (string) ($res['choices'][0]['message']['content'] ?? '');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text))));
        // Scegli al massimo $num parafrasi
        $paraphrases = array_slice($lines, 0, $num);

        // Deduplica e ritorna (originale + parafrasi)
        $all = array_values(array_unique(array_merge([$query], $paraphrases)));

        return $all;
    }
}
