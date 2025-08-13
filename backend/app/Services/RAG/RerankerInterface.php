<?php

namespace App\Services\RAG;

interface RerankerInterface
{
    /**
     * @param string $query
     * @param array<int, array{document_id:int, chunk_index:int, text:string, score:float}> $candidates
     * @param int $topN
     * @return array<int, array{document_id:int, chunk_index:int, text:string, score:float}>
     */
    public function rerank(string $query, array $candidates, int $topN): array;
}



