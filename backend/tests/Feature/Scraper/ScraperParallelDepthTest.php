<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Jobs\ScrapeUrlJob;
use App\Models\KnowledgeBase;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScraperParallelDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_scraper_respects_max_depth(): void
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
            'max_depth' => 2,
            'seed_urls' => ['https://example.com'],
            'render_js' => false,
            'target_knowledge_base_id' => $knowledgeBase->id,
        ]);

        Http::fake([
            'https://example.com' => Http::response(
                '<html><head><title>Root</title></head><body><a href="https://example.com/l1">Level1</a></body></html>',
                200
            ),
            'https://example.com/l1' => Http::response(
                '<html><head><title>L1</title></head><body><a href="https://example.com/l2">Level2</a></body></html>',
                200
            ),
            'https://example.com/l2' => Http::response(
                '<html><head><title>L2</title></head><body><a href="https://example.com/l3">Level3</a></body></html>',
                200
            ),
            'https://example.com/l3' => Http::response(
                '<html><head><title>L3</title></head><body><p>Leaf</p></body></html>',
                200
            ),
        ]);

        $service = new WebScraperService;
        $service->scrapeForTenantParallel($tenant->id, $config->id);

        Queue::assertPushed(ScrapeUrlJob::class, function (ScrapeUrlJob $job) {
            return $job->depth <= 2;
        });

        Queue::assertNotPushed(ScrapeUrlJob::class, function (ScrapeUrlJob $job) {
            return $job->depth > 2;
        });
    }
}
