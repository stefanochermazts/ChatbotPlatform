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

        // Adatta il payload ai requisiti del modello (es. gpt-5-*)
        $payload = $this->adaptPayloadForModel($payload);

        $response = $this->http->post('/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return $this->normalizeResponse($data);
    }

    /**
     * Stream chat completions using Server-Sent Events (SSE)
     *
     * @param  array  $payload  OpenAI chat completion payload
     * @param  callable  $onChunk  Callback for each chunk: function(string $delta, array $chunkData)
     * @return array Final completion data with accumulated content
     */
    public function chatCompletionsStream(array $payload, callable $onChunk): array
    {
        $apiKey = (string) config('openai.api_key');

        // Mock mode: simulate streaming
        if ($apiKey === '') {
            $content = $this->buildEchoContent($payload);
            $words = explode(' ', $content);
            $accumulated = '';

            foreach ($words as $i => $word) {
                $delta = ($i > 0 ? ' ' : '').$word;
                $accumulated .= $delta;

                $chunkData = [
                    'id' => 'mock-chatcmpl-'.substr(md5(json_encode($payload)), 0, 12),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $payload['model'] ?? 'gpt-4o-mini',
                    'choices' => [[
                        'index' => 0,
                        'delta' => ['content' => $delta],
                        'finish_reason' => null,
                    ]],
                ];

                $onChunk($delta, $chunkData);
                usleep(50000); // 50ms delay for realistic simulation
            }

            // Final chunk
            $finalChunk = [
                'id' => 'mock-chatcmpl-'.substr(md5(json_encode($payload)), 0, 12),
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => $payload['model'] ?? 'gpt-4o-mini',
                'choices' => [[
                    'index' => 0,
                    'delta' => [],
                    'finish_reason' => 'stop',
                ]],
            ];
            $onChunk('', $finalChunk);

            return [
                'id' => 'mock-chatcmpl-'.substr(md5(json_encode($payload)), 0, 12),
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $payload['model'] ?? 'gpt-4o-mini',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $accumulated,
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => str_word_count($accumulated),
                    'total_tokens' => str_word_count($accumulated),
                ],
            ];
        }

        // Real streaming with OpenAI
        $payload = $this->adaptPayloadForModel($payload);
        $payload['stream'] = true;

        $response = $this->http->post('/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'stream' => true,
        ]);

        $body = $response->getBody();
        $accumulated = '';
        $lastChunkData = null;

        while (! $body->eof()) {
            $line = $this->readLine($body);

            if (trim($line) === '' || ! str_starts_with($line, 'data: ')) {
                continue;
            }

            $data = trim(substr($line, 6));

            if ($data === '[DONE]') {
                break;
            }

            $chunkData = json_decode($data, true);
            if (! $chunkData) {
                continue;
            }

            $lastChunkData = $chunkData;
            $delta = $chunkData['choices'][0]['delta']['content'] ?? '';

            if ($delta !== '') {
                $accumulated .= $delta;
                $onChunk($delta, $chunkData);
            }
        }

        // Build final response
        return [
            'id' => $lastChunkData['id'] ?? 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => $lastChunkData['created'] ?? time(),
            'model' => $lastChunkData['model'] ?? $payload['model'] ?? 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $accumulated,
                ],
                'finish_reason' => $lastChunkData['choices'][0]['finish_reason'] ?? 'stop',
            ]],
            'usage' => $lastChunkData['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
    }

    /**
     * Read a line from stream (handles SSE format)
     */
    private function readLine($stream): string
    {
        $line = '';
        while (! $stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }

        return $line;
    }

    /**
     * Alcuni modelli (es. gpt-5-mini) non accettano "max_tokens" e richiedono
     * "max_completion_tokens". Questa funzione normalizza il payload.
     */
    private function adaptPayloadForModel(array $payload): array
    {
        $model = (string) ($payload['model'] ?? '');
        $isGpt5 = stripos($model, 'gpt-5') === 0;

        if ($isGpt5) {
            if (array_key_exists('max_tokens', $payload)) {
                $payload['max_completion_tokens'] = (int) $payload['max_tokens'];
                unset($payload['max_tokens']);
            }
            // Alcune varianti gpt-5 preferiscono content come array di parti
            if (isset($payload['messages']) && is_array($payload['messages'])) {
                foreach ($payload['messages'] as $i => $msg) {
                    if (isset($msg['content']) && is_string($msg['content'])) {
                        $payload['messages'][$i]['content'] = [
                            ['type' => 'text', 'text' => $msg['content']],
                        ];
                    }
                }
            }
            // Forza output testuale esplicito (param consentito)
            $payload['response_format'] = $payload['response_format'] ?? ['type' => 'text'];
            // Rimuovi parametri non supportati dalla Chat Completions API
            unset($payload['modalities'], $payload['reasoning']);
        }

        return $payload;
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
        if (! empty($payload['__citations'])) {
            $answer .= "\n\nCitazioni:";
            foreach ($payload['__citations'] as $c) {
                $answer .= "\n- {$c['title']} ({$c['url']})";
            }
        }

        return $answer;
    }

    /**
     * Normalizza il formato della risposta per garantire che
     * choices[].message.content sia sempre una stringa.
     */
    private function normalizeResponse(array $data): array
    {
        // Responses API compatibility
        if (! isset($data['choices']) && isset($data['output_text'])) {
            $text = is_array($data['output_text']) ? implode("\n", $data['output_text']) : (string) $data['output_text'];
            $data['choices'] = [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => $data['finish_reason'] ?? 'stop',
            ]];
        }
        if (! isset($data['choices']) || ! is_array($data['choices'])) {
            return $data;
        }
        foreach ($data['choices'] as $i => $choice) {
            $message = $choice['message'] ?? null;
            if (! is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                // OpenAI a volte restituisce un array di parti; concatena i testi
                $text = '';
                foreach ($content as $part) {
                    if (is_array($part)) {
                        if (isset($part['text']) && is_string($part['text'])) {
                            $text .= $part['text'];
                        } elseif (isset($part['output_text']) && is_string($part['output_text'])) {
                            $text .= $part['output_text'];
                        } elseif (isset($part['content']) && is_string($part['content'])) {
                            $text .= $part['content'];
                        } elseif (($part['type'] ?? '') === 'text' && isset($part['data'])) {
                            $text .= (string) $part['data'];
                        }
                    } elseif (is_string($part)) {
                        $text .= $part;
                    }
                }
                $data['choices'][$i]['message']['content'] = $text;
            } elseif (! is_string($content)) {
                // Fallback robusto
                $data['choices'][$i]['message']['content'] = (string) json_encode($content);
            }
        }

        return $data;
    }
}
