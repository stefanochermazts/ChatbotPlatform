<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scraper\ScraperLogger;

class TestScraperLogging extends Command
{
    protected $signature = 'scraper:test-logging';
    protected $description = 'Test the dedicated scraper logging system';

    public function handle()
    {
        $this->info('🧪 Testing ScraperLogger...');
        
        $sessionId = 'test_' . uniqid();
        
        $this->line("Session ID: {$sessionId}");
        
        // Test different log levels
        ScraperLogger::sessionStarted($sessionId, 9, 'Test Config');
        $this->line('✓ Session started log');
        
        ScraperLogger::urlProcessing($sessionId, 'https://example.com/page1', 0);
        $this->line('✓ URL processing log');
        
        ScraperLogger::urlSuccess($sessionId, 'https://example.com/page1', 'new', 1024);
        $this->line('✓ URL success log');
        
        ScraperLogger::jsRenderStart($sessionId, 'https://spa-site.com');
        $this->line('✓ JS render start log');
        
        ScraperLogger::jsRenderSuccess($sessionId, 'https://spa-site.com', 2048, 3500.5);
        $this->line('✓ JS render success log');
        
        ScraperLogger::urlError($sessionId, 'https://broken-site.com', 'Connection timeout');
        $this->line('✓ URL error log');
        
        ScraperLogger::warning($sessionId, 'Rate limit approaching', ['current_rps' => 2.5]);
        $this->line('✓ Warning log');
        
        ScraperLogger::sessionCompleted($sessionId, [
            'new' => 5,
            'updated' => 2,
            'skipped' => 3,
            'urls_visited' => 10,
            'documents_saved' => 7
        ], 15000.0);
        $this->line('✓ Session completed log');
        
        $logFile = storage_path('logs/scraper-' . date('Y-m-d') . '.log');
        $this->info("✅ All logs generated successfully!");
        $this->line("📁 Check log file: {$logFile}");
        
        if (file_exists($logFile)) {
            $size = filesize($logFile);
            $this->line("📊 Log file size: {$size} bytes");
            
            // Show last few lines
            $this->line("");
            $this->line("📄 Last 5 log entries:");
            $content = file_get_contents($logFile);
            $lines = explode("\n", trim($content));
            $lastLines = array_slice($lines, -5);
            
            foreach ($lastLines as $line) {
                if (!empty($line)) {
                    $this->line("   " . $line);
                }
            }
        } else {
            $this->error("❌ Log file not created!");
        }
        
        return 0;
    }
}


