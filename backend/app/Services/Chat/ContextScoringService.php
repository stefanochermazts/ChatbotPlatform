<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\Chat\ContextScoringServiceInterface;
use App\Exceptions\ChatException;
use Illuminate\Support\Facades\Log;

/**
 * Service for scoring and ranking RAG citations
 * 
 * Calculates composite scores based on multiple dimensions:
 * - Source quality (file type, domain, freshness)
 * - Content quality (length, completeness, readability)
 * - Authority (official documents > user content)
 * - Intent match (keyword overlap with detected intent)
 * 
 * @package App\Services\Chat
 */
class ContextScoringService implements ContextScoringServiceInterface
{
    /**
     * Configurable score weights (sum should be 1.0)
     * 
     * @var array<string, float>
     */
    private array $weights;
    
    /**
     * Minimum confidence threshold for filtering
     */
    private float $minConfidence;
    
    /**
     * Authority keywords for boosting official documents
     * 
     * @var array<string>
     */
    private array $authorityKeywords = [
        'comune',
        'regione',
        'provincia',
        'ministero',
        'ufficio',
        'delibera',
        'ordinanza',
        'regolamento',
        'statuto',
        'pnrr',
        'pgtu',
        'piano',
        'ufficiale',
        'municipio'
    ];
    
    /**
     * High-quality file extensions (boost score)
     * 
     * @var array<string>
     */
    private array $highQualityExtensions = [
        'pdf',
        'doc',
        'docx'
    ];
    
    public function __construct()
    {
        // Load weights from config with defaults
        $this->weights = [
            'source' => (float) config('rag.scoring.weights.source', 0.20),
            'quality' => (float) config('rag.scoring.weights.quality', 0.30),
            'authority' => (float) config('rag.scoring.weights.authority', 0.25),
            'intent_match' => (float) config('rag.scoring.weights.intent_match', 0.25),
        ];
        
        $this->minConfidence = (float) config('rag.scoring.min_confidence', 0.30);
        
        // Validate weights sum to ~1.0 (allow 0.01 tolerance)
        $sum = array_sum($this->weights);
        if (abs($sum - 1.0) > 0.01) {
            Log::warning('scoring.weights_sum_invalid', [
                'weights' => $this->weights,
                'sum' => $sum,
                'expected' => 1.0
            ]);
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function scoreCitations(array $citations, array $context): array
    {
        if (empty($citations)) {
            return [];
        }
        
        // Validate context structure
        if (!isset($context['query']) || !isset($context['tenant_id'])) {
            throw ChatException::fromValidation(
                'context',
                'Missing required fields: query, tenant_id'
            );
        }
        
        $query = (string) $context['query'];
        $intent = (string) ($context['intent'] ?? '');
        $minConfidence = (float) ($context['min_confidence'] ?? $this->minConfidence);
        
        Log::debug('scoring.start', [
            'citations_count' => count($citations),
            'query' => substr($query, 0, 100),
            'intent' => $intent,
            'min_confidence' => $minConfidence
        ]);
        
        $scored = [];
        foreach ($citations as $citation) {
            $content = (string) ($citation['content'] ?? $citation['chunk_text'] ?? $citation['snippet'] ?? '');

            if (trim($content) === '') {
                Log::warning('scoring.invalid_citation_skipped', [
                    'citation_keys' => array_keys($citation)
                ]);
                continue;
            }

            $citation['content'] = $content;
            
            // Calculate individual dimension scores
            $sourceScore = $this->calculateSourceScore($citation);
            $qualityScore = $this->calculateQualityScore($citation);
            $authorityScore = $this->calculateAuthorityScore($citation);
            $intentMatchScore = $this->calculateIntentMatchScore($citation, $query, $intent);
            
            // Calculate weighted composite score
            $compositeScore = 
                ($sourceScore * $this->weights['source']) +
                ($qualityScore * $this->weights['quality']) +
                ($authorityScore * $this->weights['authority']) +
                ($intentMatchScore * $this->weights['intent_match']);
            
            // Apply original RAG score as a multiplier (if present)
            $originalScore = (float) ($citation['score'] ?? 1.0);
            $finalScore = $compositeScore * $originalScore;
            
            // Filter by confidence threshold
            if ($finalScore < $minConfidence) {
                Log::debug('scoring.citation_filtered', [
                    'document_id' => $citation['document_id'] ?? null,
                    'final_score' => $finalScore,
                    'min_confidence' => $minConfidence
                ]);
                continue;
            }
            
            // Add scores to citation
            $scored[] = array_merge($citation, [
                'composite_score' => round($finalScore, 4),
                'score_breakdown' => [
                    'source_score' => round($sourceScore, 4),
                    'quality_score' => round($qualityScore, 4),
                    'authority_score' => round($authorityScore, 4),
                    'intent_match_score' => round($intentMatchScore, 4),
                    'original_rag_score' => round($originalScore, 4),
                    'weighted_composite' => round($compositeScore, 4),
                ],
            ]);
        }
        
        // Sort by composite_score descending
        usort($scored, function($a, $b) {
            return $b['composite_score'] <=> $a['composite_score'];
        });
        
        Log::debug('scoring.complete', [
            'input_count' => count($citations),
            'output_count' => count($scored),
            'filtered_count' => count($citations) - count($scored),
            'top_score' => $scored[0]['composite_score'] ?? 0.0
        ]);
        
        return $scored;
    }
    
    /**
     * Calculate source quality score (0.0-1.0)
     * 
     * Factors:
     * - File extension (PDF/DOCX > TXT > web)
     * - Domain authority (official domains)
     * - Freshness (recent documents)
     * 
     * @param array<string, mixed> $citation
     * @return float Score between 0.0 and 1.0
     */
    private function calculateSourceScore(array $citation): float
    {
        $score = 0.5; // Base score
        
        $source = strtolower((string) ($citation['source'] ?? ''));
        $sourceUrl = strtolower((string) ($citation['document_source_url'] ?? ''));
        
        // Boost for high-quality file types
        foreach ($this->highQualityExtensions as $ext) {
            if (str_contains($source, ".{$ext}") || str_contains($sourceUrl, ".{$ext}")) {
                $score += 0.30;
                break;
            }
        }
        
        // Boost for official domains
        $officialDomains = ['.gov.', '.edu.', 'comune.', 'regione.', 'provincia.'];
        foreach ($officialDomains as $domain) {
            if (str_contains($sourceUrl, $domain)) {
                $score += 0.20;
                break;
            }
        }
        
        return min(1.0, max(0.0, $score));
    }
    
    /**
     * Calculate content quality score (0.0-1.0)
     * 
     * Factors:
     * - Content length (optimal 200-1000 chars)
     * - Presence of structured data (tables, lists)
     * - Readability
     * 
     * @param array<string, mixed> $citation
     * @return float Score between 0.0 and 1.0
     */
    private function calculateQualityScore(array $citation): float
    {
        $content = (string) ($citation['content'] ?? $citation['chunk_text'] ?? '');
        $length = mb_strlen($content);
        
        // Length-based score (bell curve)
        if ($length < 50) {
            $lengthScore = 0.2; // Too short
        } elseif ($length >= 50 && $length < 200) {
            $lengthScore = 0.5; // Short but acceptable
        } elseif ($length >= 200 && $length <= 1000) {
            $lengthScore = 1.0; // Optimal length
        } elseif ($length > 1000 && $length <= 2000) {
            $lengthScore = 0.8; // Long but still good
        } else {
            $lengthScore = 0.6; // Very long, may be noisy
        }
        
        // Structured content boost
        $hasTable = str_contains($content, '|') || str_contains($content, 'Nominativo');
        $hasList = preg_match('/^[\s]*[-*•]\s/m', $content);
        $structureBoost = ($hasTable || $hasList) ? 0.15 : 0.0;
        
        $score = min(1.0, $lengthScore + $structureBoost);
        
        return $score;
    }
    
    /**
     * Calculate authority score (0.0-1.0)
     * 
     * Factors:
     * - Official document keywords
     * - Document type (ordinanza, delibera, etc.)
     * - Metadata indicators
     * 
     * @param array<string, mixed> $citation
     * @return float Score between 0.0 and 1.0
     */
    private function calculateAuthorityScore(array $citation): float
    {
        $score = 0.3; // Base score
        
        $searchText = strtolower(
            ($citation['title'] ?? '') . ' ' .
            ($citation['source'] ?? '') . ' ' .
            ($citation['content'] ?? '') . ' ' .
            ($citation['document_source_url'] ?? '')
        );
        
        // Count authority keyword matches
        $matchCount = 0;
        foreach ($this->authorityKeywords as $keyword) {
            if (str_contains($searchText, $keyword)) {
                $matchCount++;
            }
        }
        
        // Each keyword match adds 0.15 (max 3 matches = 0.45 boost)
        $keywordBoost = min(0.45, $matchCount * 0.15);
        $score += $keywordBoost;
        
        // Boost for metadata presence (indicates structured document)
        $hasMetadata = !empty($citation['metadata'] ?? []);
        if ($hasMetadata) {
            $score += 0.10;
        }
        
        return min(1.0, max(0.0, $score));
    }
    
    /**
     * Calculate intent match score (0.0-1.0)
     * 
     * Factors:
     * - Query keyword overlap
     * - Intent-specific field presence (phone, email, address, hours)
     * - Semantic relevance
     * 
     * @param array<string, mixed> $citation
     * @param string $query
     * @param string $intent
     * @return float Score between 0.0 and 1.0
     */
    private function calculateIntentMatchScore(array $citation, string $query, string $intent): float
    {
        $content = strtolower((string) ($citation['content'] ?? $citation['chunk_text'] ?? ''));
        $query = strtolower($query);
        
        // Base score from query keyword overlap
        $queryWords = array_filter(
            preg_split('/\s+/', $query),
            fn($w) => mb_strlen($w) > 3 // Skip short words
        );
        
        $overlapCount = 0;
        foreach ($queryWords as $word) {
            if (str_contains($content, $word)) {
                $overlapCount++;
            }
        }
        
        $overlapScore = count($queryWords) > 0 
            ? min(1.0, $overlapCount / count($queryWords)) 
            : 0.5;
        
        // Intent-specific boosts
        $intentBoost = 0.0;
        switch ($intent) {
            case 'phone':
                $intentBoost = !empty($citation['phone']) || !empty($citation['phones']) ? 0.40 : 0.0;
                break;
            case 'email':
                $intentBoost = !empty($citation['email']) ? 0.40 : 0.0;
                break;
            case 'address':
                $hasAddress = str_contains($content, 'via ') || 
                             str_contains($content, 'piazza ') || 
                             str_contains($content, 'indirizzo');
                $intentBoost = $hasAddress ? 0.30 : 0.0;
                break;
            case 'hours':
            case 'schedule':
                $hasSchedule = str_contains($content, ':') && 
                              (str_contains($content, 'orari') || str_contains($content, 'apertura'));
                $intentBoost = $hasSchedule ? 0.30 : 0.0;
                break;
            default:
                $intentBoost = 0.0;
        }
        
        $finalScore = ($overlapScore * 0.6) + ($intentBoost);
        
        return min(1.0, max(0.0, $finalScore));
    }
}

