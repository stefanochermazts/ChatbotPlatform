<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;

class MonitorQueueLag extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'metrics:queue-lag
                            {--json : Output as JSON}
                            {--store : Store metrics in Redis}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor queue lag from Horizon and emit metrics';

    /**
     * Execute the console command.
     */
    public function handle(JobRepository $jobs, MetricsRepository $metrics): int
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            $this->error('Laravel Horizon is not installed');

            return self::FAILURE;
        }

        $queueNames = ['default', 'scraping', 'parsing', 'embedding', 'indexing', 'ingestion'];
        $lagMetrics = [];

        foreach ($queueNames as $queueName) {
            $lag = $this->calculateQueueLag($queueName, $jobs);
            $lagMetrics[$queueName] = $lag;

            if ($this->option('store')) {
                $this->storeMetric($queueName, $lag);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($lagMetrics, JSON_PRETTY_PRINT));
        } else {
            $this->displayTable($lagMetrics);
        }

        // Alert if any queue has high lag
        foreach ($lagMetrics as $queue => $lag) {
            if ($lag['lag_seconds'] > 30) {
                $this->warn("⚠️  Queue '{$queue}' has high lag: {$lag['lag_seconds']}s");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Calculate queue lag
     */
    private function calculateQueueLag(string $queueName, JobRepository $jobs): array
    {
        try {
            // Get pending jobs for this queue
            $pending = $jobs->getPending();
            $queueJobs = collect($pending)->filter(function ($job) use ($queueName) {
                return $job->queue === $queueName;
            });

            if ($queueJobs->isEmpty()) {
                return [
                    'pending_count' => 0,
                    'oldest_job_age' => 0,
                    'lag_seconds' => 0,
                ];
            }

            // Find oldest job
            $oldestJob = $queueJobs->sortBy('created_at')->first();
            $ageSeconds = now()->diffInSeconds($oldestJob->created_at);

            return [
                'pending_count' => $queueJobs->count(),
                'oldest_job_age' => $ageSeconds,
                'lag_seconds' => $ageSeconds,
            ];

        } catch (\Exception $e) {
            $this->error("Failed to calculate lag for queue '{$queueName}': {$e->getMessage()}");

            return [
                'pending_count' => 0,
                'oldest_job_age' => 0,
                'lag_seconds' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Store metric in Redis
     */
    private function storeMetric(string $queueName, array $lag): void
    {
        try {
            $key = "queue:lag:{$queueName}";
            Redis::setex($key, 120, json_encode([
                'timestamp' => now()->toIso8601String(),
                'queue' => $queueName,
                'pending_count' => $lag['pending_count'],
                'lag_seconds' => $lag['lag_seconds'],
            ]));
        } catch (\Exception $e) {
            $this->warn("Failed to store metric for '{$queueName}': {$e->getMessage()}");
        }
    }

    /**
     * Display metrics table
     */
    private function displayTable(array $lagMetrics): void
    {
        $rows = [];
        foreach ($lagMetrics as $queue => $metrics) {
            $rows[] = [
                $queue,
                $metrics['pending_count'],
                $this->formatDuration($metrics['lag_seconds']),
                $metrics['lag_seconds'] > 30 ? '⚠️' : '✅',
            ];
        }

        $this->table(
            ['Queue', 'Pending', 'Lag', 'Status'],
            $rows
        );
    }

    /**
     * Format duration in human-readable format
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes}m {$remainingSeconds}s";
    }
}
