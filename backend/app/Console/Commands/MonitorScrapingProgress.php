<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorScrapingProgress extends Command
{
    protected $signature = 'scraper:monitor {tenant_id} {--refresh=5 : Refresh interval in seconds}';

    protected $description = 'Monitor scraping progress in real-time';

    public function handle()
    {
        $tenantId = $this->argument('tenant_id');
        $refreshInterval = (int) $this->option('refresh');

        $tenant = Tenant::findOrFail($tenantId);

        $this->info('📊 SCRAPING PROGRESS MONITOR');
        $this->line("Tenant: {$tenant->name} (ID: {$tenantId})");
        $this->line("Refresh: {$refreshInterval}s");
        $this->line('Press Ctrl+C to stop monitoring');
        $this->line('');

        $previousStats = null;

        while (true) {
            // Clear screen (works in most terminals)
            $this->line("\033[2J\033[H");

            $this->info('📊 SCRAPING PROGRESS MONITOR - '.now()->format('Y-m-d H:i:s'));
            $this->line("Tenant: {$tenant->name} (ID: {$tenantId})");
            $this->line('');

            try {
                // Get current statistics
                $stats = $this->getCurrentStats($tenantId);

                // Documents by status
                $this->line('📄 DOCUMENTS:');
                $this->line("- Total: {$stats['total_documents']}");
                $this->line("- Pending ingestion: {$stats['pending_ingestion']}");
                $this->line("- Processing: {$stats['processing']}");
                $this->line("- Ready: {$stats['ready']}");
                $this->line("- Failed: {$stats['failed']}");
                $this->line('');

                // Recent activity
                $this->line('📈 RECENT ACTIVITY (last 1 hour):');
                $this->line("- Documents added: {$stats['recent_added']}");
                $this->line("- Documents processed: {$stats['recent_processed']}");
                $this->line('');

                // Queue status
                $this->line('🔄 QUEUE STATUS:');
                $this->line("- Ingestion jobs: {$stats['ingestion_queue']}");
                $this->line("- Indexing jobs: {$stats['indexing_queue']}");
                $this->line('');

                // Progress since last check
                if ($previousStats) {
                    $newDocs = $stats['total_documents'] - $previousStats['total_documents'];
                    $newProcessed = $stats['ready'] - $previousStats['ready'];

                    if ($newDocs > 0 || $newProcessed > 0) {
                        $this->line('🆕 PROGRESS SINCE LAST CHECK:');
                        if ($newDocs > 0) {
                            $this->line("- New documents: +{$newDocs}");
                        }
                        if ($newProcessed > 0) {
                            $this->line("- Newly processed: +{$newProcessed}");
                        }
                        $this->line('');
                    }
                }

                // Latest documents
                $this->line('📝 LATEST DOCUMENTS:');
                $latestDocs = Document::where('tenant_id', $tenantId)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['id', 'title', 'ingestion_status', 'created_at', 'source_url']);

                foreach ($latestDocs as $doc) {
                    $status = $this->getStatusIcon($doc->ingestion_status);
                    $url = \Str::limit($doc->source_url ?? 'No URL', 50);
                    $time = $doc->created_at->format('H:i:s');
                    $this->line("  {$status} [{$time}] {$doc->title} - {$url}");
                }

                $this->line('');
                $this->line('Last updated: '.now()->format('H:i:s')." | Next refresh in {$refreshInterval}s");

                $previousStats = $stats;

            } catch (\Exception $e) {
                $this->error('Error: '.$e->getMessage());
            }

            sleep($refreshInterval);
        }
    }

    private function getCurrentStats(int $tenantId): array
    {
        $baseQuery = Document::where('tenant_id', $tenantId);

        return [
            'total_documents' => $baseQuery->count(),
            'pending_ingestion' => $baseQuery->where('ingestion_status', 'pending')->count(),
            'processing' => $baseQuery->where('ingestion_status', 'processing')->count(),
            'ready' => $baseQuery->where('ingestion_status', 'ready')->count(),
            'failed' => $baseQuery->where('ingestion_status', 'failed')->count(),
            'recent_added' => $baseQuery->where('created_at', '>=', now()->subHour())->count(),
            'recent_processed' => $baseQuery->where('ingestion_status', 'ready')
                ->where('updated_at', '>=', now()->subHour())->count(),
            'ingestion_queue' => $this->getQueueSize('ingestion'),
            'indexing_queue' => $this->getQueueSize('indexing'),
        ];
    }

    private function getQueueSize(string $queueName): int
    {
        try {
            // This depends on your queue driver
            // For database driver:
            if (config('queue.default') === 'database') {
                return DB::table('jobs')
                    ->where('queue', $queueName)
                    ->count();
            }

            // For Redis driver, you'd use different logic
            // For now, return 0 if we can't determine
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending' => '⏳',
            'processing' => '🔄',
            'ready' => '✅',
            'failed' => '❌',
            default => '❓'
        };
    }
}
