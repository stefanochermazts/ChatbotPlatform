<?php

namespace App\Services\LLM;

use GuzzleHttp\Client;

class OpenAIChatService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('openai.base_url', 'https://api.openai.com'),
            'timeout' => 30,
        ]);
    }

    public function chatCompletions(array $payload): array
    {
        $apiKey = (string) config('openai.api_key');
        if ($apiKey === '') {
            // Fallback mock: restituisce risposta deterministica utile in dev
            $content = $this->buildEchoContent($payload);
            return [
                'id' => 'mock-chatcmpl-'.substr(md5(json_encode($payload)), 0, 12),
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $payload['model'] ?? 'gpt-4o-mini',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                ],
            ];
        }

        $response = $this->http->post('/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    private function buildEchoContent(array $payload): string
    {
        $messages = $payload['messages'] ?? [];
        $lastUser = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        $answer = 'Risposta di mock: '.$lastUser;
        if (!empty($payload['__citations'])) {
            $answer .= "\n\nCitazioni:";
            foreach ($payload['__citations'] as $c) {
                $answer .= "\n- {$c['title']} ({$c['url']})";
            }
        }
        return $answer;
    }
}





