<?php

namespace App\Services\RAG;

interface RerankerInterface
{
    /**
     * @param  array<int, array{document_id:int, chunk_index:int, text:string, score:float}>  $candidates
     * @return array<int, array{document_id:int, chunk_index:int, text:string, score:float}>
     */
    public function rerank(string $query, array $candidates, int $topN): array;
}
