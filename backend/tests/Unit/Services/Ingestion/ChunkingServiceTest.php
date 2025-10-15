<?php

namespace Tests\Unit\Services\Ingestion;

use App\Services\Ingestion\ChunkingService;
use Tests\TestCase;

/**
 * Unit tests for ChunkingService
 * 
 * Tests the most critical Service in the ingestion pipeline.
 * ChunkingService is responsible for semantic chunking with multiple strategies.
 */
class ChunkingServiceTest extends TestCase
{
    private ChunkingService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChunkingService();
    }
    
    /** @test */
    public function it_chunks_short_text_without_splitting()
    {
        $text = "This is a short text that should not be split.";
        
        $result = $this->service->chunk($text, [
            'max_chars' => 2200,
            'overlap_chars' => 250
        ]);
        
        $this->assertCount(1, $result);
        $this->assertEquals($text, $result[0]['text']);
        $this->assertEquals('standard', $result[0]['type']);
    }
    
    /** @test */
    public function it_chunks_long_text_by_paragraphs()
    {
        $paragraph1 = str_repeat("First paragraph. ", 50); // ~850 chars
        $paragraph2 = str_repeat("Second paragraph. ", 50); // ~900 chars
        $paragraph3 = str_repeat("Third paragraph. ", 50); // ~850 chars
        
        $text = $paragraph1 . "\n\n" . $paragraph2 . "\n\n" . $paragraph3;
        
        $result = $this->service->chunk($text, [
            'max_chars' => 1500,
            'overlap_chars' => 200
        ]);
        
        $this->assertGreaterThan(1, count($result));
        
        // Each chunk should not exceed max_chars significantly
        foreach ($result as $chunk) {
            $this->assertLessThanOrEqual(1700, strlen($chunk['text']), 
                "Chunk exceeded max_chars by too much");
        }
    }
    
    /** @test */
    public function it_respects_max_chars_limit()
    {
        $text = str_repeat("Lorem ipsum dolor sit amet. ", 500); // ~14,000 chars
        
        $result = $this->service->chunk($text, [
            'max_chars' => 2200,
            'overlap_chars' => 250
        ]);
        
        foreach ($result as $chunk) {
            // Allow 10% tolerance for overlap and sentence boundaries
            $this->assertLessThanOrEqual(2420, strlen($chunk['text']),
                "Chunk size {$chunk['position']} exceeded tolerance");
        }
    }
    
    /** @test */
    public function it_chunks_markdown_tables_into_rows()
    {
        $table = [
            'content' => "| Nome | Telefono | Email |\n" .
                        "|------|----------|-------|\n" .
                        "| Mario Rossi | 123-456-7890 | mario@example.com |\n" .
                        "| Luigi Verdi | 098-765-4321 | luigi@example.com |",
            'context_before' => 'Elenco contatti:',
            'context_after' => 'Fine elenco.',
            'rows' => 3,
            'cols' => 3
        ];
        
        $result = $this->service->chunkTables([$table]);
        
        $this->assertGreaterThanOrEqual(2, count($result), 
            "Should create at least 2 chunks for 2 data rows");
        
        // Each chunk should be a table row
        foreach ($result as $chunk) {
            $this->assertEquals('table_row', $chunk['type']);
            $this->assertStringContainsString(':', $chunk['text'], 
                "Chunk should contain key:value pairs");
        }
    }
    
    /** @test */
    public function it_extracts_directory_entries_with_phone_numbers()
    {
        $text = "Ufficio Anagrafe\n" .
                "Telefono: 06-12345678\n" .
                "Via Roma 123\n\n" .
                "Ufficio Tributi\n" .
                "Telefono: 06-87654321\n" .
                "Piazza Centrale 1";
        
        $result = $this->service->extractDirectoryEntries($text);
        
        $this->assertGreaterThanOrEqual(2, count($result), 
            "Should extract at least 2 directory entries");
        
        foreach ($result as $chunk) {
            $this->assertEquals('directory_entry', $chunk['type']);
            $this->assertStringContainsString('Telefono:', $chunk['text']);
        }
    }
    
    /** @test */
    public function it_returns_empty_for_directory_with_too_few_entries()
    {
        $text = "Ufficio Unico\n" .
                "Telefono: 06-12345678";
        
        $result = $this->service->extractDirectoryEntries($text);
        
        // Should return empty because only 1 entry (minimum is 2)
        $this->assertEmpty($result);
    }
    
    /** @test */
    public function it_calculates_optimal_chunk_size()
    {
        // Short text
        $shortText = str_repeat("Short. ", 50);
        $optimalShort = $this->service->calculateOptimalChunkSize($shortText);
        $this->assertLessThan(1000, $optimalShort);
        
        // Medium text
        $mediumText = str_repeat("Medium text. ", 500);
        $optimalMedium = $this->service->calculateOptimalChunkSize($mediumText);
        $this->assertEquals(2200, $optimalMedium); // Default
        
        // Long text
        $longText = str_repeat("Very long text. ", 2000);
        $optimalLong = $this->service->calculateOptimalChunkSize($longText);
        $this->assertGreaterThan(2200, $optimalLong);
        $this->assertLessThanOrEqual(3000, $optimalLong);
    }
    
    /** @test */
    public function it_handles_empty_text_gracefully()
    {
        $result = $this->service->chunk('', [
            'max_chars' => 2200,
            'overlap_chars' => 250
        ]);
        
        $this->assertEmpty($result);
    }
    
    /** @test */
    public function it_preserves_paragraph_boundaries()
    {
        $text = "First paragraph with important content.\n\n" .
                "Second paragraph with different topic.\n\n" .
                "Third paragraph continues the discussion.";
        
        $result = $this->service->chunk($text, [
            'max_chars' => 100, // Force splitting
            'overlap_chars' => 20
        ]);
        
        // Should create separate chunks for paragraphs
        $this->assertGreaterThan(1, count($result));
        
        // Chunks should not break mid-paragraph if possible
        foreach ($result as $chunk) {
            $trimmed = trim($chunk['text']);
            $this->assertNotEmpty($trimmed);
        }
    }
}

