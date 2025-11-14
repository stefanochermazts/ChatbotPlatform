<?php

declare(strict_types=1);

namespace App\Contracts\Chat;

/**
 * Interface for context and citation scoring service
 *
 * Calculates composite scores for retrieved citations based on multiple
 * factors: source quality, content relevance, authority, and intent match.
 * Filters and ranks citations to provide the best context to the LLM.
 */
interface ContextScoringServiceInterface
{
    /**
     * Score and rank citations by composite metrics
     *
     * Calculates four main scores:
     * 1. Source Score - Quality and reliability of the source
     * 2. Content Quality Score - Relevance and completeness
     * 3. Authority Score - Domain expertise and trust
     * 4. Intent Match Score - Alignment with detected user intent
     *
     * Combines scores using configurable weights and filters citations
     * below the minimum confidence threshold.
     *
     * @param array<int, array{
     *     source: string,
     *     content: string,
     *     score: float,
     *     document_id?: int,
     *     chunk_id?: int,
     *     metadata?: array
     * }> $citations Raw citations from retrieval
     * @param array{
     *     query: string,
     *     intent?: string,
     *     tenant_id: int,
     *     min_confidence?: float
     * } $context Request context for scoring
     * @return array<int, array{
     *     source: string,
     *     content: string,
     *     score: float,
     *     composite_score: float,
     *     score_breakdown: array{
     *         source_score: float,
     *         quality_score: float,
     *         authority_score: float,
     *         intent_match_score: float
     *     },
     *     document_id?: int,
     *     chunk_id?: int,
     *     metadata?: array
     * }> Scored and ranked citations (descending by composite_score)
     *
     * @throws \App\Exceptions\ChatException When scoring fails or citation schema is invalid
     *
     * @example
     * ```php
     * $citations = [
     *     ['source' => 'doc1.pdf', 'content' => '...', 'score' => 0.85],
     *     ['source' => 'doc2.pdf', 'content' => '...', 'score' => 0.72],
     * ];
     * $context = ['query' => 'What are the opening hours?', 'intent' => 'hours', 'tenant_id' => 1];
     *
     * $scored = $scorer->scoreCitations($citations, $context);
     * // Returns citations sorted by composite_score with score_breakdown
     * ```
     */
    public function scoreCitations(array $citations, array $context): array;
}
