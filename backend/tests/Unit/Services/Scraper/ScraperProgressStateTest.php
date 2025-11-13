<?php

namespace Tests\Unit\Services\Scraper;

use App\Models\ScraperProgress;
use App\Models\Tenant;
use App\Services\Scraper\ProgressStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScraperProgressStateTest extends TestCase
{
    use RefreshDatabase;

    private ProgressStateService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProgressStateService::class);
        $this->tenant = Tenant::factory()->create();
    }

    /** @test */
    public function it_allows_transition_from_running_to_completed(): void
    {
        $this->assertTrue($this->service->isValidTransition('running', 'completed'));
    }

    /** @test */
    public function it_blocks_transition_back_to_running_after_completion(): void
    {
        $this->assertFalse($this->service->isValidTransition('completed', 'running'));
    }

    /** @test */
    public function it_returns_false_when_dispatched_is_not_a_permitted_status(): void
    {
        $progress = ScraperProgress::create([
            'tenant_id' => $this->tenant->id,
            'scraper_config_id' => null,
            'session_id' => (string) Str::uuid(),
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = $this->service->transitionToDispatched($this->tenant->id, $progress->id);

        $this->assertFalse($result);
        $progress->refresh();

        $this->assertSame('running', $progress->status);
    }

    /** @test */
    public function it_returns_false_when_progress_record_does_not_exist(): void
    {
        $this->assertFalse($this->service->transitionToDispatched($this->tenant->id, 999999));
    }

}

