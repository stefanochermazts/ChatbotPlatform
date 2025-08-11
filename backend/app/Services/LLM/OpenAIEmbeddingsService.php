<?php

namespace App\Services\LLM;

use GuzzleHttp\Client;

class OpenAIEmbeddingsService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('openai.base_url', 'https://api.openai.com'),
            'timeout' => 30,
        ]);
    }

    public function embedTexts(array $texts, ?string $model = null): array
    {
        $apiKey = (string) config('openai.api_key');
        $model = $model ?: (string) config('rag.embedding_model');

        $response = $this->http->post('/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'input' => array_values($texts),
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        return array_map(fn ($d) => $d['embedding'] ?? [], $data['data'] ?? []);
    }
}





