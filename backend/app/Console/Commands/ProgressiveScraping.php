<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Models\Document;
use App\Services\Scraper\WebScraperService;
use App\Jobs\IngestUploadedDocumentJob;
use Illuminate\Support\Facades\Http;

class ProgressiveScraping extends Command
{
    protected $signature = 'scraper:progressive {tenant_id} {--max-urls=50 : Maximum URLs to process} {--delay=2 : Delay between URLs in seconds} {--test-mode : Only show what would be done}';
    protected $description = 'Progressive scraping with real-time ingestion and monitoring';

    private $stats = [
        'processed' => 0,
        'new' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'ingested' => 0
    ];
    
    private $startTime;
    private $urlsToProcess = [];

    public function handle()
    {
        $tenantId = $this->argument('tenant_id');
        $maxUrls = (int) $this->option('max-urls');
        $delay = (int) $this->option('delay');
        $testMode = $this->option('test-mode');
        
        $this->startTime = microtime(true);
        
        $this->info('🚀 PROGRESSIVE SCRAPING');
        $this->line("Tenant ID: {$tenantId}");
        $this->line("Max URLs: {$maxUrls}");
        $this->line("Delay: {$delay}s");
        $this->line("Test mode: " . ($testMode ? 'YES' : 'NO'));
        $this->line("Started: " . now()->toISOString());
        $this->line("");
        
        try {
            $tenant = Tenant::findOrFail($tenantId);
            $config = ScraperConfig::where('tenant_id', $tenantId)->first();
            
            if (!$config) {
                $this->error("❌ No scraper configuration found");
                return 1;
            }
            
            // Collect URLs to process
            $this->line("🔍 Collecting URLs to process...");
            $this->collectUrls($config, $maxUrls);
            
            if (empty($this->urlsToProcess)) {
                $this->warn("⚠️ No URLs found to process");
                return 0;
            }
            
            $totalUrls = count($this->urlsToProcess);
            $this->info("📋 Found {$totalUrls} URLs to process");
            
            if ($testMode) {
                $this->line("");
                $this->line("🧪 TEST MODE - URLs that would be processed:");
                foreach ($this->urlsToProcess as $index => $url) {
                    $this->line("  " . ($index + 1) . ". {$url}");
                    if ($index >= 9) { // Show first 10
                        $remaining = $totalUrls - 10;
                        if ($remaining > 0) {
                            $this->line("  ... and {$remaining} more URLs");
                        }
                        break;
                    }
                }
                return 0;
            }
            
            // Confirm before proceeding
            if (!$this->confirm("Do you want to proceed with scraping {$totalUrls} URLs?")) {
                $this->line("Operation cancelled.");
                return 0;
            }
            
            $this->line("");
            $this->line("🎯 Starting progressive scraping...");
            $this->line("");
            
            // Initialize scraper service
            $scraperService = new WebScraperService();
            
            // Process URLs one by one
            foreach ($this->urlsToProcess as $index => $url) {
                $this->processUrl($scraperService, $url, $index + 1, $totalUrls, $tenantId, $delay);
                
                // Show progress every 5 URLs or on last URL
                if (($index + 1) % 5 === 0 || $index === $totalUrls - 1) {
                    $this->showProgress($totalUrls);
                }
            }
            
            $this->showFinalStats($totalUrls);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("💥 Fatal error: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
    
    private function collectUrls(ScraperConfig $config, int $maxUrls): void
    {
        $urls = [];
        
        // From seed URLs
        foreach ($config->seed_urls ?? [] as $seedUrl) {
            $urls[] = $seedUrl;
        }
        
        // From sitemaps
        foreach ($config->sitemap_urls ?? [] as $sitemapUrl) {
            try {
                $this->line("📥 Processing sitemap: {$sitemapUrl}");
                $response = Http::timeout(30)->get($sitemapUrl);
                
                if ($response->successful()) {
                    $xml = simplexml_load_string($response->body());
                    if ($xml) {
                        foreach ($xml->url as $urlElement) {
                            $pageUrl = (string) $urlElement->loc;
                            if ($this->isUrlAllowed($pageUrl, $config)) {
                                $urls[] = $pageUrl;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->warn("⚠️ Sitemap error: {$e->getMessage()}");
            }
        }
        
        // Remove duplicates and limit
        $urls = array_unique($urls);
        $this->urlsToProcess = array_slice($urls, 0, $maxUrls);
    }
    
    private function isUrlAllowed(string $url, ScraperConfig $config): bool
    {
        // Check include patterns
        if (!empty($config->include_patterns)) {
            $matched = false;
            foreach ($config->include_patterns as $pattern) {
                if (preg_match('/' . str_replace('/', '\/', $pattern) . '/', $url)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return false;
        }
        
        // Check exclude patterns
        if (!empty($config->exclude_patterns)) {
            foreach ($config->exclude_patterns as $pattern) {
                if (preg_match('/' . str_replace('/', '\/', $pattern) . '/', $url)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    private function processUrl(WebScraperService $scraperService, string $url, int $current, int $total, int $tenantId, int $delay): void
    {
        $urlStart = microtime(true);
        
        $this->line("🔄 [{$current}/{$total}] Processing: " . Str::limit($url, 80));
        
        try {
            // Check if URL already exists
            $existingDoc = Document::where('tenant_id', $tenantId)
                ->where('source_url', $url)
                ->first();
            
            if ($existingDoc) {
                $this->line("   ⏭️  Already exists (ID: {$existingDoc->id})");
                $this->stats['skipped']++;
                $this->stats['processed']++;
                return;
            }
            
            // Scrape URL
            $result = $scraperService->scrapeSingleUrl($tenantId, $url, false, null);
            
            if ($result && isset($result['success']) && $result['success']) {
                if (isset($result['document'])) {
                    $document = $result['document'];
                    $this->line("   ✅ Scraped successfully (ID: {$document->id})");
                    
                    // Trigger immediate ingestion
                    IngestUploadedDocumentJob::dispatch($document->id);
                    $this->line("   🔄 Ingestion job dispatched");
                    
                    $this->stats['new']++;
                    $this->stats['ingested']++;
                } else {
                    $this->line("   ✅ Processed but no new document created");
                    $this->stats['skipped']++;
                }
            } else {
                $errorMsg = $result['message'] ?? 'Unknown error';
                $this->line("   ❌ Failed: {$errorMsg}");
                $this->stats['errors']++;
            }
            
        } catch (\Exception $e) {
            $this->line("   💥 Exception: " . $e->getMessage());
            $this->stats['errors']++;
        }
        
        $this->stats['processed']++;
        
        $urlDuration = round((microtime(true) - $urlStart) * 1000, 0);
        $this->line("   ⏱️  Duration: {$urlDuration}ms");
        
        // Delay between URLs
        if ($delay > 0 && $current < $total) {
            $this->line("   ⏳ Waiting {$delay}s...");
            sleep($delay);
        }
        
        $this->line("");
    }
    
    private function showProgress(int $total): void
    {
        $elapsed = microtime(true) - $this->startTime;
        $avgTimePerUrl = $this->stats['processed'] > 0 ? $elapsed / $this->stats['processed'] : 0;
        $remaining = $total - $this->stats['processed'];
        $eta = $remaining * $avgTimePerUrl;
        
        $this->line("📊 PROGRESS UPDATE:");
        $this->line("- Processed: {$this->stats['processed']}/{$total} (" . round(($this->stats['processed'] / $total) * 100, 1) . "%)");
        $this->line("- New: {$this->stats['new']} | Skipped: {$this->stats['skipped']} | Errors: {$this->stats['errors']}");
        $this->line("- Ingested: {$this->stats['ingested']}");
        $this->line("- Elapsed: " . gmdate('H:i:s', $elapsed));
        $this->line("- ETA: " . gmdate('H:i:s', $eta));
        $this->line("- Avg per URL: " . round($avgTimePerUrl, 1) . "s");
        $this->line("");
    }
    
    private function showFinalStats(int $total): void
    {
        $totalTime = microtime(true) - $this->startTime;
        
        $this->info("🎉 SCRAPING COMPLETED!");
        $this->line("");
        $this->line("📈 FINAL STATISTICS:");
        $this->line("- Total URLs: {$total}");
        $this->line("- Processed: {$this->stats['processed']}");
        $this->line("- New documents: {$this->stats['new']}");
        $this->line("- Skipped (existing): {$this->stats['skipped']}");
        $this->line("- Errors: {$this->stats['errors']}");
        $this->line("- Ingestion jobs dispatched: {$this->stats['ingested']}");
        $this->line("");
        $this->line("⏱️ TIME STATISTICS:");
        $this->line("- Total time: " . gmdate('H:i:s', $totalTime));
        $this->line("- Average per URL: " . round($totalTime / max(1, $this->stats['processed']), 1) . "s");
        $this->line("");
        $this->line("✅ Remember to check the ingestion queue to monitor document processing!");
    }
}
