<?php

namespace App\Console;

use App\Console\Commands\RunDueScrapers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Esegui ogni 5 minuti per controllare scraper dovuti
        $schedule->command('scraper:run-due')->everyFiveMinutes()->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}


