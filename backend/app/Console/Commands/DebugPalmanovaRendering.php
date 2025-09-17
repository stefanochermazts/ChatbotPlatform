<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scraper\JavaScriptRenderer;

class DebugPalmanovaRendering extends Command
{
    protected $signature = 'scraper:debug-palmanova {url} {--tenant= : Optional tenant ID for context}';
    protected $description = 'Debug rendering issues with detailed output (optional tenant context)';

    public function handle()
    {
        $url = $this->argument('url');
        $tenantId = $this->option('tenant');
        
        $this->info('🔍 DEBUG: Rendering Analysis');
        $this->line("URL: {$url}");
        if ($tenantId) {
            $this->line("Tenant ID: {$tenantId}");
        }
        $this->line("Timestamp: " . now()->toISOString());
        $this->line("");
        
        // Environment info
        $this->line("🖥️ Environment Information:");
        $this->line("- PHP Version: " . phpversion());
        $this->line("- Laravel Version: " . app()->version());
        $this->line("- Environment: " . app()->environment());
        $this->line("- Storage Path: " . storage_path());
        $this->line("- Base Path: " . base_path());
        $this->line("");
        
        // Node.js and Puppeteer check
        $this->line("🔧 Node.js & Puppeteer Check:");
        
        $nodeVersion = null;
        $puppeteerCheck = false;
        
        try {
            $nodeOutput = [];
            exec('node --version 2>&1', $nodeOutput, $nodeExitCode);
            if ($nodeExitCode === 0) {
                $nodeVersion = trim(implode('', $nodeOutput));
                $this->line("- Node.js: ✅ {$nodeVersion}");
            } else {
                $this->error("- Node.js: ❌ Not found or error");
                return 1;
            }
            
            // Check Puppeteer
            $puppeteerOutput = [];
            $backendDir = base_path();
            exec("cd \"$backendDir\" && node -e \"console.log(require('puppeteer').version || 'found')\" 2>&1", $puppeteerOutput, $puppeteerExitCode);
            if ($puppeteerExitCode === 0) {
                $puppeteerVersion = trim(implode('', $puppeteerOutput));
                $this->line("- Puppeteer: ✅ {$puppeteerVersion}");
                $puppeteerCheck = true;
            } else {
                $this->error("- Puppeteer: ❌ " . implode(' ', $puppeteerOutput));
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("Error checking Node.js/Puppeteer: " . $e->getMessage());
            return 1;
        }
        
        if (!$puppeteerCheck) {
            $this->error("❌ Puppeteer not available - cannot continue");
            return 1;
        }
        
        $this->line("");
        $this->line("🚀 Starting detailed render test...");
        
        try {
            $startTime = microtime(true);
            $renderer = new JavaScriptRenderer();
            
            $this->line("⏱️ Timeout: 90 seconds");
            $content = $renderer->renderUrl($url, 90);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($content) {
                $this->info("✅ Content extracted successfully!");
                $this->line("Duration: {$duration}ms");
                $this->line("Raw HTML length: " . strlen($content));
                
                // Analyze content
                $text = strip_tags($content);
                $textLength = strlen($text);
                $this->line("Text content length: {$textLength}");
                
                // Content analysis
                $hasJsWarning = strpos($content, 'Please enable JavaScript') !== false;
                $hasPedibus = stripos($text, 'pedibus') !== false;
                $hasAttivazione = stripos($text, 'attivazione') !== false;
                $hasServizio = stripos($text, 'servizio') !== false;
                $hasAngular = strpos($content, 'ng-') !== false || stripos($content, 'angular') !== false;
                
                $this->line("");
                $this->line("📊 Content Analysis:");
                $this->line("- Has JS warning: " . ($hasJsWarning ? '❌ YES' : '✅ NO'));
                $this->line("- Contains 'pedibus': " . ($hasPedibus ? '✅ YES' : '❌ NO'));
                $this->line("- Contains 'attivazione': " . ($hasAttivazione ? '✅ YES' : '❌ NO'));
                $this->line("- Contains 'servizio': " . ($hasServizio ? '✅ YES' : '❌ NO'));
                $this->line("- Has Angular elements: " . ($hasAngular ? 'YES' : 'NO'));
                
                // Show content preview
                $this->line("");
                $this->line("📄 Text Content Preview (first 800 chars):");
                $this->line(str_repeat('-', 60));
                $cleanText = preg_replace('/\s+/', ' ', trim($text));
                $this->line(substr($cleanText, 0, 800) . '...');
                $this->line(str_repeat('-', 60));
                
                // Look for main content indicators
                if ($hasPedibus) {
                    $pedibusPos = stripos($text, 'pedibus');
                    $contextStart = max(0, $pedibusPos - 200);
                    $contextEnd = min(strlen($text), $pedibusPos + 600);
                    
                    $this->line("");
                    $this->line("🎯 Pedibus Context Found:");
                    $this->line(str_repeat('=', 60));
                    $this->line(substr($text, $contextStart, $contextEnd - $contextStart));
                    $this->line(str_repeat('=', 60));
                }
                
                // Save debug files
                $debugDir = storage_path('app/debug');
                if (!file_exists($debugDir)) {
                    mkdir($debugDir, 0755, true);
                }
                
                $timestamp = now()->format('Y-m-d_H-i-s');
                $htmlFile = $debugDir . "/palmanova_debug_{$timestamp}.html";
                $textFile = $debugDir . "/palmanova_debug_{$timestamp}.txt";
                
                file_put_contents($htmlFile, $content);
                file_put_contents($textFile, $text);
                
                $this->line("");
                $this->line("💾 Debug files saved:");
                $this->line("- HTML: {$htmlFile}");
                $this->line("- Text: {$textFile}");
                
                return 0;
                
            } else {
                $this->error("❌ No content returned from renderer");
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("💥 Error during rendering: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}


