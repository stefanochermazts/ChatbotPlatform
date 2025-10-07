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
            
            // 5. Exact phrase match bonus
            $exactMatchScore = $this->calculateExactMatchScore($query, $text);
            
            // Combina scores senza embeddings
            $scores[$i] = (
                $keywordScore * 0.3 +      // Keyword overlap (ridotto da 0.4)
                $tfScore * 0.25 +          // Term frequency (ridotto da 0.3)
                $lengthScore * 0.15 +      // Length factor (ridotto da 0.2)
                $exactMatchScore * 0.25 +  // Exact phrase match (nuovo!)
                $positionScore * 0.05      // Position bias (ridotto da 0.1)
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
        
        // 🔧 FIX: Bonus se TUTTE le parole chiave sono presenti
        $baseScore = count($intersection) / count($queryWords);
        if ($baseScore >= 0.99) {
            return 1.0; // Tutte le parole presenti → score massimo
        }
        
        return $baseScore;
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
        
        // 🔧 FIX: Non penalizzare chunk corti (da tabelle esplose)
        // Accetta qualsiasi lunghezza tra 100 e 1000 caratteri come ottimale
        if ($length >= 100 && $length <= 1000) {
            return 1.0;
        } elseif ($length < 100) {
            return max(0.7, $length / 100); // Penalizza solo chunk molto corti (< 100 caratteri)
        } else {
            return max(0.5, 1000 / $length); // Penalizza testi troppo lunghi
        }
    }
    
    private function calculateExactMatchScore(string $query, string $text): float
    {
        $query = strtolower(trim($query));
        $text = strtolower($text);
        
        // 🔧 FIX: Bonus per match esatti della query nel testo
        // Questo aiuta a trovare chunk come "Sabelli Alessandra - Sindaco" per query "sindaco"
        
        // 1. Exact phrase match (query completa presente nel testo)
        if (str_contains($text, $query)) {
            return 1.0;
        }
        
        // 2. Partial match: tutte le parole della query sono vicine nel testo
        $queryWords = preg_split('/\s+/', $query);
        if (count($queryWords) === 1) {
            // Single word query: check if it's a standalone word (not part of another word)
            if (preg_match('/\b' . preg_quote($query, '/') . '\b/', $text)) {
                return 0.9;
            }
        } else {
            // Multi-word query: check if words appear close together (within 50 chars)
            $positions = [];
            foreach ($queryWords as $word) {
                $pos = strpos($text, $word);
                if ($pos !== false) {
                    $positions[] = $pos;
                }
            }
            
            if (count($positions) === count($queryWords)) {
                $span = max($positions) - min($positions);
                if ($span < 50) {
                    return 0.8; // Words are close together
                }
            }
        }
        
        return 0.0;
    }
}
