<?php

namespace Tests\Feature;

use App\Models\ScraperProgress;
use App\Models\Tenant;
use App\Services\Scraper\ProgressStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrentScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function concurrent_dispatch_attempts_do_not_break_progress_status_constraints(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $progress = ScraperProgress::create([
            'tenant_id' => $tenant->id,
            'scraper_config_id' => null,
            'session_id' => (string) Str::uuid(),
            'status' => 'running',
            'started_at' => now(),
        ]);

        Queue::push(new TestProgressDispatchJob($tenant->id, $progress->id));
        Queue::push(new TestProgressDispatchJob($tenant->id, $progress->id));

        Queue::assertPushed(TestProgressDispatchJob::class, 2);

        $service = app(ProgressStateService::class);

        $firstAttempt = new TestProgressDispatchJob($tenant->id, $progress->id);
        $secondAttempt = new TestProgressDispatchJob($tenant->id, $progress->id);

        $firstResult = $firstAttempt->simulateHandle($service);

        // Simula fallback della pipeline reale che marca il progresso come completato
        $progress->refresh();
        $progress->update(['status' => ProgressStateService::STATUS_COMPLETED]);

        $secondResult = $secondAttempt->simulateHandle($service);

        $progress->refresh();

        $this->assertFalse($firstResult, 'La prima transizione deve fallire perché lo stato dispatched non è previsto dal vincolo DB.');
        $this->assertFalse($secondResult, 'La seconda transizione deve fallire e non deve sovrascrivere lo stato esistente.');
        $this->assertSame(
            ProgressStateService::STATUS_COMPLETED,
            $progress->status,
            'Lo stato finale deve restare in un valore accettato dal vincolo enum.'
        );
    }
}

class TestProgressDispatchJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $progressId
    ) {
        $this->onQueue('scraping');
    }

    public function handle(ProgressStateService $service): void
    {
        $service->transitionToDispatched($this->tenantId, $this->progressId);
    }

    public function simulateHandle(ProgressStateService $service): bool
    {
        return $service->transitionToDispatched($this->tenantId, $this->progressId);
    }
}

