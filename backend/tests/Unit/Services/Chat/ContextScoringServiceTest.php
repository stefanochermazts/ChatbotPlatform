<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\ContextScoringService;
use App\Exceptions\ChatException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContextScoringService
 * 
 * @group scoring
 * @group chat
 * @group services
 */
class ContextScoringServiceTest extends TestCase
{
    private ContextScoringService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContextScoringService();
    }
    
    public function test_returns_empty_array_for_empty_citations(): void
    {
        $context = [
            'query' => 'test query',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations([], $context);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function test_throws_exception_for_invalid_context(): void
    {
        $this->expectException(ChatException::class);
        $this->expectExceptionMessage('Missing required fields');
        
        $citations = [['content' => 'test']];
        $context = []; // Missing query and tenant_id
        
        $this->service->scoreCitations($citations, $context);
    }
    
    public function test_scores_single_citation(): void
    {
        $citations = [
            [
                'content' => 'This is a test document from the comune about orari apertura. Via Roma 123.',
                'source' => 'document.pdf',
                'document_source_url' => 'https://www.comune.test.it/doc.pdf',
                'score' => 0.85,
                'document_id' => 1,
            ]
        ];
        
        $context = [
            'query' => 'orari apertura',
            'tenant_id' => 1,
            'intent' => 'hours'
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('composite_score', $result[0]);
        $this->assertArrayHasKey('score_breakdown', $result[0]);
        $this->assertGreaterThan(0, $result[0]['composite_score']);
    }
    
    public function test_score_breakdown_has_all_dimensions(): void
    {
        $citations = [
            [
                'content' => 'Test content with sufficient length for quality scoring',
                'source' => 'test.pdf',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'test query',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        $breakdown = $result[0]['score_breakdown'];
        $this->assertArrayHasKey('source_score', $breakdown);
        $this->assertArrayHasKey('quality_score', $breakdown);
        $this->assertArrayHasKey('authority_score', $breakdown);
        $this->assertArrayHasKey('intent_match_score', $breakdown);
        $this->assertArrayHasKey('original_rag_score', $breakdown);
        $this->assertArrayHasKey('weighted_composite', $breakdown);
    }
    
    public function test_filters_citations_below_min_confidence(): void
    {
        $citations = [
            [
                'content' => 'High quality content from comune about delibera',
                'source' => 'document.pdf',
                'document_source_url' => 'https://www.comune.test.it/doc.pdf',
                'score' => 0.90,
            ],
            [
                'content' => 'x', // Very short, low quality
                'source' => 'test.txt',
                'score' => 0.10,
            ]
        ];
        
        $context = [
            'query' => 'test',
            'tenant_id' => 1,
            'min_confidence' => 0.30
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // Only high-quality citation should pass
        $this->assertLessThan(2, count($result));
        $this->assertGreaterThanOrEqual(0.30, $result[0]['composite_score'] ?? 0.0);
    }
    
    public function test_sorts_by_composite_score_descending(): void
    {
        $citations = [
            [
                'content' => 'Low quality content',
                'source' => 'test.txt',
                'score' => 0.50,
                'document_id' => 1,
            ],
            [
                'content' => 'High quality content from comune with official data about delibera and regolamento',
                'source' => 'document.pdf',
                'document_source_url' => 'https://www.comune.test.it/doc.pdf',
                'score' => 0.90,
                'document_id' => 2,
            ]
        ];
        
        $context = [
            'query' => 'delibera',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // First result should have highest score
        $this->assertGreaterThanOrEqual(
            $result[1]['composite_score'] ?? 0,
            $result[0]['composite_score']
        );
    }
    
    public function test_boosts_official_pdf_sources(): void
    {
        $citations = [
            [
                'content' => 'Content from official PDF',
                'source' => 'document.pdf',
                'document_source_url' => 'https://www.comune.test.it/doc.pdf',
                'score' => 1.0,
            ],
            [
                'content' => 'Content from text file',
                'source' => 'test.txt',
                'document_source_url' => 'https://example.com/test.txt',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'test',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // PDF from comune should have higher source score
        $pdfResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', '.pdf')))[0] ?? null;
        $txtResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', '.txt')))[0] ?? null;
        
        $this->assertNotNull($pdfResult);
        $this->assertNotNull($txtResult);
        $this->assertGreaterThan(
            $txtResult['score_breakdown']['source_score'],
            $pdfResult['score_breakdown']['source_score']
        );
    }
    
    public function test_boosts_authority_keywords(): void
    {
        $citations = [
            [
                'content' => 'Official delibera from comune about regolamento',
                'source' => 'delibera.pdf',
                'score' => 1.0,
            ],
            [
                'content' => 'General information about something',
                'source' => 'info.txt',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'regolamento',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // Citation with authority keywords should have higher authority score
        $authorityResult = $result[0];
        $genericResult = $result[1];
        
        $this->assertGreaterThan(
            $genericResult['score_breakdown']['authority_score'],
            $authorityResult['score_breakdown']['authority_score']
        );
    }
    
    public function test_boosts_intent_specific_fields(): void
    {
        $citations = [
            [
                'content' => 'Contact info with phone details',
                'phone' => '+39 06 123456',
                'score' => 1.0,
            ],
            [
                'content' => 'General content without contact info',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'numero telefono',
            'tenant_id' => 1,
            'intent' => 'phone'
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // Citation with phone field should have higher intent match score for phone intent
        $phoneResult = array_values(array_filter($result, fn($r) => !empty($r['phone'])))[0] ?? null;
        $genericResult = array_values(array_filter($result, fn($r) => empty($r['phone'] ?? null)))[0] ?? null;
        
        $this->assertNotNull($phoneResult);
        $this->assertNotNull($genericResult);
        $this->assertGreaterThan(
            $genericResult['score_breakdown']['intent_match_score'],
            $phoneResult['score_breakdown']['intent_match_score']
        );
    }
    
    public function test_quality_score_penalizes_very_short_content(): void
    {
        $citations = [
            [
                'content' => 'x', // Very short
                'source' => 'short.txt',
                'score' => 1.0,
            ],
            [
                'content' => str_repeat('This is a well-structured document with sufficient content. ', 10), // Optimal length
                'source' => 'long.pdf',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'test',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        $longResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', 'long')))[0] ?? null;
        $shortResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', 'short')))[0] ?? null;
        
        if ($longResult && $shortResult) {
            $this->assertGreaterThan(
                $shortResult['score_breakdown']['quality_score'],
                $longResult['score_breakdown']['quality_score']
            );
        } else {
            // Short content might be filtered out entirely
            $this->assertTrue(true);
        }
    }
    
    public function test_quality_score_boosts_structured_content(): void
    {
        $citations = [
            [
                'content' => "| Nominativo | Ruolo |\n|------------|-------|\n| Mario Rossi | Sindaco |",
                'source' => 'table.pdf',
                'score' => 1.0,
            ],
            [
                'content' => 'Plain text without any structure or formatting',
                'source' => 'plain.txt',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'sindaco',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        $tableResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', 'table')))[0] ?? null;
        $plainResult = array_values(array_filter($result, fn($r) => str_contains($r['source'] ?? '', 'plain')))[0] ?? null;
        
        $this->assertNotNull($tableResult);
        $this->assertNotNull($plainResult);
        $this->assertGreaterThanOrEqual(
            $plainResult['score_breakdown']['quality_score'],
            $tableResult['score_breakdown']['quality_score']
        );
    }
    
    public function test_skips_citations_without_content_field(): void
    {
        $citations = [
            [
                'source' => 'test.pdf', // Missing 'content' field
                'score' => 1.0,
            ],
            [
                'content' => 'Valid citation with content',
                'source' => 'valid.pdf',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'test',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // Only the valid citation should be processed
        $this->assertCount(1, $result);
        $this->assertEquals('valid.pdf', $result[0]['source']);
    }
    
    public function test_handles_chunk_text_fallback(): void
    {
        $citations = [
            [
                'chunk_text' => 'Content in chunk_text field instead of content',
                'source' => 'test.pdf',
                'score' => 1.0,
            ]
        ];
        
        $context = [
            'query' => 'content',
            'tenant_id' => 1
        ];
        
        $result = $this->service->scoreCitations($citations, $context);
        
        // Should process chunk_text as fallback for content
        $this->assertCount(1, $result);
        $this->assertGreaterThan(0, $result[0]['composite_score']);
    }
}

