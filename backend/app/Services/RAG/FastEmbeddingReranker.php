<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;

/**
 * Fast Embedding Reranker che usa embeddings già esistenti
 * invece di fare nuove chiamate OpenAI
 */
class FastEmbeddingReranker implements RerankerInterface
{
    public function __construct(private readonly OpenAIEmbeddingsService $embeddings) {}

    public function rerank(string $query, array $candidates, int $topN): array
    {
        if ($candidates === []) return [];
        
        // 🚀 ZERO CHIAMATE OPENAI: Usa solo scoring lessicale/sintattico
        $scores = [];
        $queryWords = $this->extractKeywords($query);
        
        foreach ($candidates as $i => $candidate) {
            $text = (string) $candidate['text'];
            $candidateWords = $this->extractKeywords($text);
            
            // Scoring veloce basato su:
            // 1. Keyword overlap
            $keywordScore = $this->calculateKeywordOverlap($queryWords, $candidateWords);
            
            // 2. Query term frequency in text
            $tfScore = $this->calculateTermFrequency($query, $text);
            
            // 3. Text length factor (preferisci testi di lunghezza media)
            $lengthScore = $this->calculateLengthScore($text);
            
            // 4. Position bias (mantieni ordine originale come tie-breaker)
            $positionScore = 1.0 - ($i / count($candidates)) * 0.1;
            
            // Combina scores senza embeddings
            $scores[$i] = (
                $keywordScore * 0.4 +    // Keyword overlap
                $tfScore * 0.3 +         // Term frequency
                $lengthScore * 0.2 +     // Length factor
                $positionScore * 0.1     // Position bias
            );
        }

        // Ordina per score
        $indexed = array_map(fn($i) => ['index' => $i, 'score' => $scores[$i]], array_keys($scores));
        usort($indexed, fn($a, $b) => $b['score'] <=> $a['score']);
        
        // Restituisci top results con score aggiornati
        $result = [];
        foreach (array_slice($indexed, 0, $topN) as $item) {
            $candidate = $candidates[$item['index']];
            $candidate['score'] = $item['score'];
            $result[] = $candidate;
        }
        
        return $result;
    }
    
    private function extractKeywords(string $text): array
    {
        // Estrai parole chiave significative (no stopwords)
        $stopwords = ['il', 'la', 'di', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'a', 'e', 'o', 'ma', 'se', 'come', 'quando', 'dove', 'che', 'chi', 'cosa'];
        $words = preg_split('/\s+/', strtolower($text));
        return array_diff(array_filter($words, fn($w) => strlen($w) > 2), $stopwords);
    }
    
    private function calculateKeywordOverlap(array $queryWords, array $textWords): float
    {
        if (empty($queryWords)) return 0.0;
        $intersection = array_intersect($queryWords, $textWords);
        return count($intersection) / count($queryWords);
    }
    
    private function calculateTermFrequency(string $query, string $text): float
    {
        $query = strtolower($query);
        $text = strtolower($text);
        $queryTerms = preg_split('/\s+/', $query);
        $score = 0.0;
        
        foreach ($queryTerms as $term) {
            $count = substr_count($text, $term);
            $score += $count / max(1, strlen($text) / 100); // Normalizza per lunghezza
        }
        
        return min(1.0, $score);
    }
    
    private function calculateLengthScore(string $text): float
    {
        $length = strlen($text);
        
        // Preferisci testi di lunghezza media (300-800 caratteri)
        if ($length >= 300 && $length <= 800) {
            return 1.0;
        } elseif ($length < 300) {
            return $length / 300; // Penalizza testi troppo corti
        } else {
            return max(0.5, 800 / $length); // Penalizza testi troppo lunghi
        }
    }
}
