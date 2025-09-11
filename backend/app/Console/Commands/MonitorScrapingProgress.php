<?php

namespace App\Console\Commands;

use App\Models\ScraperProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MonitorScrapingProgress extends Command
{
    protected $signature = 'scraper:monitor-progress 
                            {--tenant= : Monitor specific tenant}
                            {--session= : Monitor specific session}
                            {--follow : Keep monitoring (like tail -f)}';

    protected $description = 'Monitor scraping and ingestion progress in real-time';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $sessionId = $this->option('session');
        $follow = $this->option('follow');

        do {
            $this->clearTerminal();
            $this->displayHeader();
            
            if ($sessionId) {
                $this->displaySessionProgress($sessionId);
            } elseif ($tenantId) {
                $this->displayTenantProgress($tenantId);
            } else {
                $this->displayAllProgress();
            }

            if ($follow) {
                sleep(2); // Aggiorna ogni 2 secondi
            }
            
        } while ($follow && !$this->hasCtrlC());

        return 0;
    }

    private function clearTerminal(): void
    {
        if ($this->option('follow')) {
            $this->output->write("\033[2J\033[H"); // Clear screen + move cursor to top
        }
    }

    private function displayHeader(): void
    {
        $this->info("🔍 SCRAPING PROGRESS MONITOR");
        $this->info("📅 " . now()->format('Y-m-d H:i:s'));
        $this->newLine();
    }

    private function displaySessionProgress(string $sessionId): void
    {
        $progress = ScraperProgress::where('session_id', $sessionId)->first();
        
        if (!$progress) {
            $this->error("❌ Session {$sessionId} not found");
            return;
        }

        $summary = $progress->getSummary();
        
        $this->line("📋 <info>Session:</info> {$sessionId}");
        $this->line("🏢 <info>Tenant:</info> {$progress->tenant_id}");
        $this->line("📊 <info>Status:</info> " . $this->getStatusEmoji($summary['status']) . " {$summary['status']}");
        $this->newLine();

        // Progress bars
        $this->displayProgressBar("🌐 Scraping", $summary['progress_percentage'], 
            "({$summary['pages']['scraped']}/{$summary['pages']['found']} pages)");
        
        $this->displayProgressBar("📄 Ingestion", $summary['ingestion_percentage'],
            "({$summary['ingestion']['completed']}/{$summary['documents']['created']} docs)");
        
        $this->newLine();

        // Detailed stats
        $this->line("📈 <comment>Detailed Stats:</comment>");
        $this->line("   Pages: Found {$summary['pages']['found']}, Scraped {$summary['pages']['scraped']}, Skipped {$summary['pages']['skipped']}, Failed {$summary['pages']['failed']}");
        $this->line("   Docs:  Created {$summary['documents']['created']}, Updated {$summary['documents']['updated']}, Unchanged {$summary['documents']['unchanged']}");
        $this->line("   Queue: Pending {$summary['ingestion']['pending']}, Processing {$summary['ingestion']['processing']}, Completed {$summary['ingestion']['completed']}, Failed {$summary['ingestion']['failed']}");
        
        if ($summary['current']['url']) {
            $this->newLine();
            $this->line("🔄 <comment>Current:</comment> {$summary['current']['url']} (depth {$summary['current']['depth']})");
        }

        if ($summary['current']['error']) {
            $this->newLine();
            $this->error("⚠️  Last Error: {$summary['current']['error']}");
        }

        $this->displayTiming($summary['timing']);
    }

    private function displayTenantProgress(int $tenantId): void
    {
        $activeProgress = ScraperProgress::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->latest()
            ->get();

        $recentProgress = ScraperProgress::where('tenant_id', $tenantId)
            ->orderBy('started_at', 'desc')
            ->limit(5)
            ->get();

        $this->line("🏢 <info>Tenant {$tenantId} Progress</info>");
        $this->newLine();

        if ($activeProgress->isEmpty()) {
            $this->line("✅ No active scraping sessions");
        } else {
            $this->line("🔄 <comment>Active Sessions:</comment>");
            foreach ($activeProgress as $progress) {
                $summary = $progress->getSummary();
                $this->line("   • {$progress->session_id} - {$summary['progress_percentage']}% scraping, {$summary['ingestion_percentage']}% ingestion");
            }
        }

        $this->newLine();
        $this->line("📋 <comment>Recent Sessions:</comment>");
        foreach ($recentProgress as $progress) {
            $summary = $progress->getSummary();
            $status = $this->getStatusEmoji($summary['status']) . " {$summary['status']}";
            $duration = $summary['timing']['completed_at'] 
                ? round($summary['timing']['elapsed_seconds'] / 60, 1) . 'm'
                : 'running';
            
            $this->line("   • {$progress->session_id} - {$status} - {$summary['pages']['scraped']} pages - {$duration}");
        }
    }

    private function displayAllProgress(): void
    {
        $activeProgress = ScraperProgress::where('status', 'running')
            ->with('tenant')
            ->latest()
            ->get();

        $this->line("🌍 <info>All Active Scraping Sessions</info>");
        $this->newLine();

        if ($activeProgress->isEmpty()) {
            $this->line("✅ No active scraping sessions across all tenants");
            return;
        }

        foreach ($activeProgress as $progress) {
            $summary = $progress->getSummary();
            $tenantName = $progress->tenant->name ?? "Tenant {$progress->tenant_id}";
            
            $this->line("🏢 <comment>{$tenantName}</comment> ({$progress->session_id})");
            $this->line("   📊 {$summary['progress_percentage']}% scraping ({$summary['pages']['scraped']}/{$summary['pages']['found']} pages)");
            $this->line("   📄 {$summary['ingestion_percentage']}% ingestion ({$summary['ingestion']['completed']} completed)");
            
            if ($summary['current']['url']) {
                $this->line("   🔄 Current: " . Str::limit($summary['current']['url'], 60));
            }
            
            $this->newLine();
        }
    }

    private function displayProgressBar(string $label, float $percentage, string $details = ''): void
    {
        $width = 40;
        $filled = round(($percentage / 100) * $width);
        $empty = $width - $filled;
        
        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        $percentageStr = sprintf('%5.1f%%', $percentage);
        
        $this->line("{$label}: [{$bar}] {$percentageStr} {$details}");
    }

    private function displayTiming(array $timing): void
    {
        $this->newLine();
        $this->line("⏱️  <comment>Timing:</comment>");
        $this->line("   Started: {$timing['started_at']}");
        
        if ($timing['completed_at']) {
            $this->line("   Completed: {$timing['completed_at']}");
            $duration = round($timing['elapsed_seconds'] / 60, 1);
            $this->line("   Duration: {$duration} minutes");
        } else {
            $elapsed = round($timing['elapsed_seconds'] / 60, 1);
            $this->line("   Elapsed: {$elapsed} minutes");
            
            if ($timing['estimated_duration']) {
                $estimated = round($timing['estimated_duration'] / 60, 1);
                $this->line("   Estimated: {$estimated} minutes");
            }
        }
    }

    private function getStatusEmoji(string $status): string
    {
        return match($status) {
            'running' => '🔄',
            'completed' => '✅',
            'failed' => '❌',
            'cancelled' => '⏹️',
            default => '❓'
        };
    }

    private function hasCtrlC(): bool
    {
        // Simple check - in real implementation you might want to handle signals
        return false;
    }
}
