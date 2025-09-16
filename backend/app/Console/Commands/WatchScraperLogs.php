<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WatchScraperLogs extends Command
{
    protected $signature = 'scraper:watch-logs {--lines=20 : Number of lines to show initially}';
    protected $description = 'Watch scraper logs in real-time (like tail -f)';

    public function handle()
    {
        $lines = $this->option('lines');
        $logFile = storage_path('logs/scraper-' . date('Y-m-d') . '.log');
        
        $this->info("📊 Watching scraper logs: {$logFile}");
        $this->line("Press Ctrl+C to stop");
        $this->line("");
        
        if (!file_exists($logFile)) {
            $this->warn("⚠️  Log file doesn't exist yet. Waiting for scraping activity...");
            // Wait for file to be created
            while (!file_exists($logFile)) {
                sleep(1);
            }
            $this->info("✅ Log file created! Starting to watch...");
        }
        
        // Show initial content
        if (filesize($logFile) > 0) {
            $this->line("📄 Last {$lines} entries:");
            $content = file_get_contents($logFile);
            $allLines = explode("\n", trim($content));
            $lastLines = array_slice($allLines, -$lines);
            
            foreach ($lastLines as $line) {
                if (!empty($line)) {
                    $this->formatLogLine($line);
                }
            }
            
            $this->line(str_repeat('-', 80));
        }
        
        // Watch for new content
        $lastSize = filesize($logFile);
        
        while (true) {
            clearstatcache();
            $currentSize = filesize($logFile);
            
            if ($currentSize > $lastSize) {
                // File has grown, read new content
                $handle = fopen($logFile, 'r');
                fseek($handle, $lastSize);
                
                while (($line = fgets($handle)) !== false) {
                    $this->formatLogLine(trim($line));
                }
                
                fclose($handle);
                $lastSize = $currentSize;
            }
            
            usleep(500000); // Sleep 0.5 seconds
        }
    }
    
    private function formatLogLine(string $line): void
    {
        if (empty($line)) return;
        
        // Color code based on log level
        if (strpos($line, '.ERROR:') !== false) {
            $this->line("<fg=red>{$line}</>");
        } elseif (strpos($line, '.WARNING:') !== false) {
            $this->line("<fg=yellow>{$line}</>");
        } elseif (strpos($line, '.INFO:') !== false) {
            // Highlight specific scraper events
            if (strpos($line, 'SCRAPING-START') !== false) {
                $this->line("<fg=green;options=bold>{$line}</>");
            } elseif (strpos($line, 'SCRAPING-COMPLETED') !== false) {
                $this->line("<fg=green;options=bold>{$line}</>");
            } elseif (strpos($line, 'JS-RENDER') !== false) {
                $this->line("<fg=cyan>{$line}</>");
            } elseif (strpos($line, 'URL-') !== false) {
                $this->line("<fg=blue>{$line}</>");
            } else {
                $this->line("<fg=white>{$line}</>");
            }
        } else {
            $this->line($line);
        }
    }
}
