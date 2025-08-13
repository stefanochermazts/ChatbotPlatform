<?php

namespace App\Services\RAG;

use GuzzleHttp\Client;

class CohereReranker implements RerankerInterface
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
    }

    public function rerank(string $query, array $candidates, int $topN): array
    {
        $apiKey = (string) config('rag.reranker.cohere.api_key', '');
        $endpoint = (string) config('rag.reranker.cohere.endpoint');
        $model = (string) config('rag.reranker.cohere.model');
        if ($apiKey === '' || $endpoint === '') {
            return array_slice($candidates, 0, $topN);
        }

        $docs = array_map(fn($c) => ['text' => (string) $c['text']], $candidates);

        $resp = $this->http->post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'query' => $query,
                'documents' => $docs,
                'top_n' => min($topN, count($docs)),
            ],
        ]);

        $data = json_decode((string) $resp->getBody(), true);
        $reordered = [];
        foreach ($data['results'] ?? [] as $r) {
            $idx = (int) ($r['index'] ?? -1);
            if (!isset($candidates[$idx])) continue;
            $item = $candidates[$idx];
            $item['score'] = (float) ($r['relevance_score'] ?? $item['score']);
            $reordered[] = $item;
        }
        if ($reordered === []) return array_slice($candidates, 0, $topN);
        return $reordered;
    }
}




