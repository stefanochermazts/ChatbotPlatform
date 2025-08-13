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

        // Sanitize: assicurati che siano stringhe non vuote e UTF-8
        $clean = [];
        foreach ($texts as $t) {
            if (!is_string($t)) { $t = (string) $t; }
            $t = trim($t);
            if ($t === '') { continue; }
            // Converte in UTF-8 eliminando byte non validi
            $t = @mb_convert_encoding($t, 'UTF-8', 'UTF-8');
            if ($t === '' || $t === false) { continue; }
            $clean[] = $t;
        }
        if ($clean === []) {
            return [];
        }

        // Batch per sicurezza (evita input troppo grande in una singola richiesta)
        $all = [];
        $batches = array_chunk($clean, 128);
        foreach ($batches as $batch) {
            $response = $this->http->post('/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => array_values($batch),
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            $embeds = array_map(fn ($d) => $d['embedding'] ?? [], $data['data'] ?? []);
            $all = array_merge($all, $embeds);
        }
        return $all;
    }
}





