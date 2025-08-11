<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KbSearchService
{
    public function __construct(
        private readonly OpenAIEmbeddingsService $embeddings,
        private readonly MilvusClient $milvusClient,
    ) {}

    /**
     * @param int   $tenantId
     * @param string $query
     * @param int   $limit   Numero massimo di documenti da citare
     * @param array $opts    ['top_k' => int, 'mmr_lambda' => float]
     */
    public function retrieveCitations(int $tenantId, string $query, int $limit = 3, array $opts = []): array
    {
        if ($query === '') {
            return [];
        }

        $queryEmbedding = $this->embeddings->embedTexts([$query])[0] ?? null;
        if ($queryEmbedding === null) {
            return [];
        }

        $driver = (string) config('rag.vector.driver', 'milvus');
        if ($driver !== 'milvus') {
            Log::warning('KbSearchService: driver vettoriale non supportato o disabilitato ('.$driver.'). Nessuna citazione restituita.');
            return [];
        }

        $topK = (int) ($opts['top_k'] ?? (int) config('rag.vector.top_k', 20));
        $mmrLambda = max(0.0, min(1.0, (float) ($opts['mmr_lambda'] ?? (float) config('rag.vector.mmr_lambda', 0.3))));

        // Recupero topK chunks da Milvus (chunk-level)
        $hits = $this->milvusClient->searchTopK($tenantId, $queryEmbedding, max($topK, $limit));
        if ($hits === []) {
            return [];
        }

        // Carico snippet per i chunk trovati
        $chunkSnippets = [];
        $chunkMeta = [];
        foreach ($hits as $hit) {
            $docId = (int) ($hit['document_id'] ?? 0);
            $chunkIdx = (int) ($hit['chunk_index'] ?? 0);
            if ($docId <= 0) {
                continue;
            }
            $snippetRow = DB::selectOne('SELECT content FROM document_chunks WHERE document_id = ? AND chunk_index = ?', [$docId, $chunkIdx]);
            $docRow = DB::selectOne('SELECT id, title, path FROM documents WHERE id = ? AND tenant_id = ?', [$docId, $tenantId]);
            if ($snippetRow === null || $docRow === null) {
                continue;
            }
            $chunkSnippets[] = (string) $snippetRow->content;
            $chunkMeta[] = [
                'document_id' => $docRow->id,
                'title' => $docRow->title,
                'url' => url('storage/'.$docRow->path),
                'chunk_index' => $chunkIdx,
            ];
        }
        if ($chunkSnippets === []) {
            return [];
        }

        // Embeddings degli snippet per MMR
        $docEmbeddings = $this->embeddings->embedTexts($chunkSnippets);
        $selectedIdx = $this->maximalMarginalRelevance($queryEmbedding, $docEmbeddings, $mmrLambda, $limit);

        $citations = [];
        $seenDocs = [];
        foreach ($selectedIdx as $idx) {
            $meta = $chunkMeta[$idx] ?? null;
            $snippet = $chunkSnippets[$idx] ?? '';
            if ($meta === null) {
                continue;
            }
            // Evita duplicati per documento: prendi il primo selezionato
            if (isset($seenDocs[$meta['document_id']])) {
                continue;
            }
            $seenDocs[$meta['document_id']] = true;
            $citations[] = [
                'id' => $meta['document_id'],
                'title' => $meta['title'],
                'url' => $meta['url'],
                'snippet' => $snippet,
            ];
            if (count($citations) >= $limit) {
                break;
            }
        }

        return $citations;
    }

    /**
     * Semplice MMR greedy su embeddings (cosine similarity)
     * @param array $queryEmbedding float[]
     * @param array $docEmbeddings array<float[]>
     * @return array indici selezionati
     */
    private function maximalMarginalRelevance(array $queryEmbedding, array $docEmbeddings, float $lambda, int $k): array
    {
        $selected = [];
        $candidates = range(0, count($docEmbeddings) - 1);
        $queryNorm = $this->l2norm($queryEmbedding);
        $docNorms = array_map(fn ($v) => $this->l2norm($v), $docEmbeddings);

        while (count($selected) < $k && $candidates !== []) {
            $bestIdx = null;
            $bestScore = -INF;
            foreach ($candidates as $i) {
                $simToQuery = $this->cosine($queryEmbedding, $docEmbeddings[$i], $queryNorm, $docNorms[$i]);
                $maxSimToSelected = 0.0;
                foreach ($selected as $j) {
                    $sim = $this->cosine($docEmbeddings[$i], $docEmbeddings[$j], $docNorms[$i], $docNorms[$j]);
                    if ($sim > $maxSimToSelected) {
                        $maxSimToSelected = $sim;
                    }
                }
                $score = $lambda * $simToQuery - (1.0 - $lambda) * $maxSimToSelected;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $i;
                }
            }
            if ($bestIdx === null) {
                break;
            }
            $selected[] = $bestIdx;
            $candidates = array_values(array_diff($candidates, [$bestIdx]));
        }
        return $selected;
    }

    private function l2norm(array $v): float
    {
        $s = 0.0;
        foreach ($v as $x) {
            $s += $x * $x;
        }
        return (float) sqrt(max($s, 1e-12));
    }

    private function cosine(array $a, array $b, ?float $nA = null, ?float $nB = null): float
    {
        $nA = $nA ?? $this->l2norm($a);
        $nB = $nB ?? $this->l2norm($b);
        $dot = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += ((float) $a[$i]) * ((float) $b[$i]);
        }
        return $dot / max($nA * $nB, 1e-12);
    }
}


