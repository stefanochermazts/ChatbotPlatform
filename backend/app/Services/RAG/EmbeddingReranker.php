<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;

class EmbeddingReranker implements RerankerInterface
{
    public function __construct(private readonly OpenAIEmbeddingsService $embeddings) {}

    public function rerank(string $query, array $candidates, int $topN): array
    {
        if ($candidates === []) return [];
        
        // 1. Multi-perspective query enhancement
        $enhancedQuery = $this->enhanceQuery($query);
        
        // 2. Generate embeddings with batching optimization
        $qEmb = $this->embeddings->embedTexts([$enhancedQuery])[0] ?? null;
        if ($qEmb === null) return array_slice($candidates, 0, $topN);

        // 3. Optimize text extraction and preprocessing
        $texts = array_map(fn($c) => $this->preprocessText((string) $c['text']), $candidates);
        $embs = $this->embeddings->embedTexts($texts);

        // 4. Advanced scoring with multiple metrics
        $scores = [];
        $qN = $this->norm($qEmb);
        
        foreach ($embs as $i => $e) {
            if (empty($e)) {
                $scores[$i] = 0.0;
                continue;
            }
            
            $eN = $this->norm($e);
            
            // Base cosine similarity
            $cosScore = $this->cos($qEmb, $e, $qN, $eN);
            
            // Length penalty/bonus for very short/long texts
            $lengthFactor = $this->calculateLengthFactor($texts[$i]);
            
            // Query coverage score (how much of query intent is covered)
            $coverageScore = $this->calculateCoverageScore($query, $texts[$i]);
            
            // Combined score with weights
            $scores[$i] = (
                $cosScore * 0.7 +           // Primary semantic similarity
                $coverageScore * 0.2 +      // Query intent coverage
                $lengthFactor * 0.1         // Length optimization
            );
        }

        // 5. Apply scores and sort
        foreach ($candidates as $i => &$c) {
            $originalScore = (float) $c['score'];
            $newScore = (float) ($scores[$i] ?? 0.0);
            
            // Blend with original retrieval score for stability
            $c['score'] = ($newScore * 0.8 + $originalScore * 0.2);
        }
        unset($c);
        
        usort($candidates, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($candidates, 0, $topN);
    }

    /**
     * Enhanced query with context and intent clarification
     */
    private function enhanceQuery(string $query): string
    {
        $query = trim($query);
        
        // Add context keywords based on query patterns
        $enhancements = [];
        
        // Intent-specific enhancements
        if (preg_match('/\b(?:orario|orari|quando|apertura|chiusura)\b/iu', $query)) {
            $enhancements[] = 'orario di apertura servizio ufficio';
        }
        
        if (preg_match('/\b(?:telefono|numero|contatto|chiamare)\b/iu', $query)) {
            $enhancements[] = 'numero di telefono contatto ufficio';
        }
        
        if (preg_match('/\b(?:email|mail|posta|scrivere)\b/iu', $query)) {
            $enhancements[] = 'indirizzo email posta elettronica';
        }
        
        if (preg_match('/\b(?:indirizzo|dove|ubicazione|sede)\b/iu', $query)) {
            $enhancements[] = 'indirizzo sede ufficio dove si trova';
        }
        
        // Combine original query with enhancements
        $enhanced = $query;
        if (!empty($enhancements)) {
            $enhanced .= ' ' . implode(' ', $enhancements);
        }
        
        return $enhanced;
    }

    /**
     * Preprocess text for better embedding quality
     */
    private function preprocessText(string $text): string
    {
        // Remove excessive whitespace and normalize
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Truncate very long texts to focus on most relevant part
        if (mb_strlen($text) > 800) {
            // Try to keep sentences complete
            $truncated = mb_substr($text, 0, 750);
            $lastSentence = mb_strrpos($truncated, '.');
            if ($lastSentence && $lastSentence > 500) {
                $text = mb_substr($text, 0, $lastSentence + 1);
            } else {
                $text = $truncated . '...';
            }
        }
        
        return $text;
    }

    /**
     * Calculate length factor for scoring
     */
    private function calculateLengthFactor(string $text): float
    {
        $length = mb_strlen($text);
        
        // Optimal length range: 100-500 characters
        if ($length < 50) {
            return 0.7; // Penalty for too short
        } elseif ($length >= 50 && $length <= 100) {
            return 0.9; // Good for short answers
        } elseif ($length >= 100 && $length <= 500) {
            return 1.0; // Optimal range
        } elseif ($length >= 500 && $length <= 800) {
            return 0.95; // Still good
        } else {
            return 0.8; // Penalty for too long
        }
    }

    /**
     * Calculate how well the text covers the query intent
     */
    private function calculateCoverageScore(string $query, string $text): float
    {
        $queryWords = $this->extractKeywords($query);
        $textWords = $this->extractKeywords($text);
        
        if (empty($queryWords)) return 0.5;
        
        $matches = 0;
        $totalWeight = 0;
        
        foreach ($queryWords as $word => $weight) {
            $totalWeight += $weight;
            if (isset($textWords[$word])) {
                $matches += $weight;
            } else {
                // Check for partial matches or synonyms
                foreach ($textWords as $textWord => $textWeight) {
                    if ($this->wordsAreSimilar($word, $textWord)) {
                        $matches += $weight * 0.7; // Partial credit
                        break;
                    }
                }
            }
        }
        
        return $totalWeight > 0 ? $matches / $totalWeight : 0.5;
    }

    /**
     * Extract keywords with weights
     */
    private function extractKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        
        // Remove stopwords
        $stopwords = ['il', 'la', 'di', 'del', 'della', 'e', 'un', 'una', 'per', 'con', 'da', 'in', 'su', 'a', 'al', 'alla'];
        
        $words = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && !in_array($word, $stopwords)) {
                $weight = 1.0;
                
                // Higher weight for important words
                if (mb_strlen($word) >= 6) $weight += 0.3;
                if (preg_match('/\b(?:orario|telefono|email|indirizzo|ufficio|servizio|polizia|vigili|comune|municipio)\b/', $word)) {
                    $weight += 0.5;
                }
                
                $keywords[$word] = ($keywords[$word] ?? 0) + $weight;
            }
        }
        
        return $keywords;
    }

    /**
     * Check if two words are similar (basic)
     */
    private function wordsAreSimilar(string $word1, string $word2): bool
    {
        // Simple similarity check
        if (mb_strlen($word1) < 4 || mb_strlen($word2) < 4) return false;
        
        // Same prefix/suffix
        if (mb_substr($word1, 0, 4) === mb_substr($word2, 0, 4)) return true;
        if (mb_substr($word1, -4) === mb_substr($word2, -4)) return true;
        
        // Levenshtein distance for short words
        if (mb_strlen($word1) <= 8 && mb_strlen($word2) <= 8) {
            return levenshtein($word1, $word2) <= 2;
        }
        
        return false;
    }

    private function norm(array $v): float 
    { 
        $s = 0.0; 
        foreach ($v as $x) { 
            $s += $x * $x; 
        } 
        return (float) sqrt(max($s, 1e-12)); 
    }

    private function cos(array $a, array $b, ?float $na = null, ?float $nb = null): float 
    { 
        $na = $na ?? $this->norm($a); 
        $nb = $nb ?? $this->norm($b); 
        $dot = 0.0; 
        $n = min(count($a), count($b)); 
        for ($i = 0; $i < $n; $i++) { 
            $dot += ((float)$a[$i]) * ((float)$b[$i]); 
        } 
        return $dot / max($na * $nb, 1e-12); 
    }
}




