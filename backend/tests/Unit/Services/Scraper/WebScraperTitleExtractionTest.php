<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scraper;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebScraperTitleExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_source_page_title_when_scraping_in_parallel(): void
    {
        Storage::fake('public');
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'tenant_id' => $tenant->id,
            'is_default' => true,
        ]);

        $config = ScraperConfig::factory()->create([
            'tenant_id' => $tenant->id,
            'target_knowledge_base_id' => $knowledgeBase->id,
        ]);

        $service = new WebScraperService;
        $service->setSessionId('test-session');

        $result = [
            'url' => 'https://example.com',
            'title' => 'Example',
            'source_page_title' => 'Example Page',
            'content' => 'Sample content for document',
            'depth' => 0,
            'quality_analysis' => null,
        ];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('saveAndIngestSingleResult');
        $method->setAccessible(true);
        $method->invoke($service, $result, $tenant, $config);

        $document = Document::where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($document, 'Document should be created');
        $this->assertSame('Example Page', $document->source_page_title);
    }
}
