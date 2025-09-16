<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scraper\WebScraperService;
use App\Models\Tenant;

class TestSingleUrlScraping extends Command
{
    protected $signature = 'scraper:test-single-url {tenant_id} {url}';
    protected $description = 'Test single URL scraping with JavaScript rendering';

    public function handle()
    {
        $tenantId = $this->argument('tenant_id');
        $url = $this->argument('url');
        
        $this->info("🧪 Testing single URL scraping...");
        $this->line("Tenant ID: {$tenantId}");
        $this->line("URL: {$url}");
        $this->line("");
        
        try {
            $tenant = Tenant::findOrFail($tenantId);
            $this->line("✓ Tenant found: {$tenant->name}");
            
            $scraperService = new WebScraperService();
            $this->line("✓ WebScraperService instantiated");
            
            $this->line("🚀 Starting scraping...");
            $result = $scraperService->scrapeSingleUrl($tenantId, $url, false, null);
            
            if ($result) {
                $this->info("✅ SUCCESS: Single URL scraping completed!");
                
                // Debug: show result structure
                $this->line("Result structure: " . json_encode(array_keys($result), JSON_PRETTY_PRINT));
                
                if (isset($result['document_id'])) {
                    $this->line("Document ID: {$result['document_id']}");
                }
                if (isset($result['title'])) {
                    $this->line("Title: {$result['title']}");
                }
                // Try different content locations
                $content = null;
                if (isset($result['content'])) {
                    $content = $result['content'];
                } elseif (isset($result['document']['content'])) {
                    $content = $result['document']['content'];
                } elseif (isset($result['document']) && is_string($result['document'])) {
                    $content = $result['document'];
                }
                
                if ($content) {
                    $this->line("Content length: " . strlen($content));
                    $this->line("Content preview:");
                    $this->line(str_repeat('-', 50));
                    $this->line(substr($content, 0, 300) . '...');
                    $this->line(str_repeat('-', 50));
                    
                    // Check for JavaScript warning
                    $hasJsWarning = strpos($content, 'Please enable JavaScript') !== false;
                    if ($hasJsWarning) {
                        $this->warn("⚠️  Content still contains JavaScript warning - JS rendering may not be working");
                    } else {
                        $this->info("✅ Content looks good - no JavaScript warnings detected");
                    }
                } else {
                    $this->warn("⚠️  No content found in result");
                }
                
                // Show additional info
                if (isset($result['saved_count'])) {
                    $this->line("Saved count: {$result['saved_count']}");
                }
                if (isset($result['stats'])) {
                    $this->line("Stats: " . json_encode($result['stats']));
                }
                
            } else {
                $this->error("❌ FAILED: No result returned");
            }
            
        } catch (\Exception $e) {
            $this->error("💥 ERROR: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
        }
        
        $this->line("");
        $this->line("📁 Check scraper logs: storage/logs/scraper-" . date('Y-m-d') . ".log");
        
        return 0;
    }
}
