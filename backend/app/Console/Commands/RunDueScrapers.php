<?php

namespace App\Console\Commands;

use App\Jobs\RunWebScrapingJob;
use App\Models\ScraperConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunDueScrapers extends Command
{
    protected $signature = 'scraper:run-due {--tenant=} {--id=} {--dry-run}';

    protected $description = 'Esegue in coda gli scraper abilitati che risultano dovuti in base a interval_minutes';

    public function handle(): int
    {
        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $onlyId = $this->option('id') ? (int) $this->option('id') : null;
        $dry = (bool) $this->option('dry-run');

        $q = ScraperConfig::query()->where('enabled', true)
            ->whereNotNull('interval_minutes')
            ->where('interval_minutes', '>', 0);
        if ($tenant) {
            $q->where('tenant_id', $tenant);
        }
        if ($onlyId) {
            $q->where('id', $onlyId);
        }

        $now = now();
        $due = $q->get();
        $count = 0;

        foreach ($due as $cfg) {
            $interval = (int) $cfg->interval_minutes;
            $threshold = $now->copy()->subMinutes($interval);

            // Verifica se dovuto (prima del claim):
            $isDue = $cfg->last_run_at === null || $cfg->last_run_at <= $threshold;
            if (! $isDue) {
                continue;
            }

            // Tenta claim atomico aggiornando last_run_at solo se ancora dovuto
            $updated = DB::table('scraper_configs')
                ->where('id', $cfg->id)
                ->where(function ($w) use ($threshold) {
                    $w->whereNull('last_run_at')->orWhere('last_run_at', '<=', $threshold);
                })
                ->update(['last_run_at' => $now, 'updated_at' => $now]);

            if ($updated === 0) {
                // Qualcun altro ha già preso in carico
                continue;
            }

            $this->info("Dispatch scraper #{$cfg->id} (tenant {$cfg->tenant_id}) - '{$cfg->name}'");

            if (! $dry) {
                RunWebScrapingJob::dispatch($cfg->tenant_id, $cfg->id);
            }
            $count++;
        }

        $this->info("Totale dispatch: {$count}");

        return self::SUCCESS;
    }
}
