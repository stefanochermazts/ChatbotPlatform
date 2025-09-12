<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MonitorQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:monitor 
                          {--refresh=5 : Refresh interval in seconds}
                          {--once : Run once and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Laravel queue status and job counts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        do {
            $this->displayQueueStatus();
            
            if ($this->option('once')) {
                break;
            }
            
            $refresh = (int) $this->option('refresh');
            sleep($refresh);
            
        } while (true);
    }

    private function displayQueueStatus()
    {
        // Clear screen
        if (!$this->option('once')) {
            system('clear');
        }
        
        $this->info('🚀 LARAVEL QUEUE MONITOR');
        $this->info('📅 ' . Carbon::now()->format('Y-m-d H:i:s'));
        $this->newLine();

        // 1. JOBS PENDING
        $this->info('📋 JOBS IN CODA (PENDING)');
        $pendingJobs = DB::table('jobs')
            ->select(
                'queue',
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(created_at) as oldest'),
                DB::raw('MAX(created_at) as newest')
            )
            ->groupBy('queue')
            ->orderBy('count', 'desc')
            ->get();

        if ($pendingJobs->isEmpty()) {
            $this->line('   ✅ Nessun job in coda');
        } else {
            $this->table(
                ['Coda', 'Jobs', 'Più Vecchio', 'Più Recente'],
                $pendingJobs->map(function ($job) {
                    return [
                        $job->queue,
                        $job->count,
                        Carbon::parse($job->oldest)->diffForHumans(),
                        Carbon::parse($job->newest)->diffForHumans()
                    ];
                })->toArray()
            );
        }

        $this->newLine();

        // 2. FAILED JOBS
        $this->info('❌ JOBS FALLITI');
        $failedJobs = DB::table('failed_jobs')
            ->select(
                'queue',
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(failed_at) as oldest_failure'),
                DB::raw('MAX(failed_at) as newest_failure')
            )
            ->groupBy('queue')
            ->orderBy('count', 'desc')
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->line('   ✅ Nessun job fallito');
        } else {
            $this->table(
                ['Coda', 'Jobs Falliti', 'Primo Fallimento', 'Ultimo Fallimento'],
                $failedJobs->map(function ($job) {
                    return [
                        $job->queue,
                        $job->count,
                        Carbon::parse($job->oldest_failure)->diffForHumans(),
                        Carbon::parse($job->newest_failure)->diffForHumans()
                    ];
                })->toArray()
            );
        }

        $this->newLine();

        // 3. JOBS BY CLASS
        $this->info('🔍 JOBS PER CLASSE (PENDING)');
        $jobsByClass = DB::table('jobs')
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(payload, "$.displayName")) as job_class'),
                'queue',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('job_class', 'queue')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        if ($jobsByClass->isEmpty()) {
            $this->line('   ✅ Nessun job in coda');
        } else {
            $this->table(
                ['Classe Job', 'Coda', 'Count'],
                $jobsByClass->map(function ($job) {
                    return [
                        $job->job_class ?: 'Unknown',
                        $job->queue,
                        $job->count
                    ];
                })->toArray()
            );
        }

        $this->newLine();

        // 4. TOTALI
        $totalPending = DB::table('jobs')->count();
        $totalFailed = DB::table('failed_jobs')->count();
        
        $this->info('📊 RIEPILOGO TOTALE');
        $this->table(
            ['Tipo', 'Count'],
            [
                ['Jobs Pending', $totalPending],
                ['Jobs Failed', $totalFailed],
                ['Total Jobs', $totalPending + $totalFailed]
            ]
        );

        if (!$this->option('once')) {
            $refresh = $this->option('refresh');
            $this->info("🔄 Aggiornamento ogni {$refresh} secondi... (Ctrl+C per uscire)");
        }
    }
}