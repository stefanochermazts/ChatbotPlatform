<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scraper\JavaScriptRenderer;
use App\Services\Scraper\WebScraperService;

class DebugMarkdownConversion extends Command
{
    protected $signature = 'scraper:debug-markdown {tenant_id} {url}';
    protected $description = 'Debug Markdown conversion process step by step with tenant configuration';

    public function handle()
    {
        $tenantId = $this->argument('tenant_id');
        $url = $this->argument('url');
        
        $this->info('🔍 DEBUG: Markdown Conversion Analysis');
        $this->line("Tenant ID: {$tenantId}");
        $this->line("URL: {$url}");
        $this->line("Timestamp: " . now()->toISOString());
        $this->line("");
        
        try {
            // STEP 1: JavaScript Rendering
            $this->line("🚀 STEP 1: JavaScript Rendering...");
            $renderer = new JavaScriptRenderer();
            $rawHtml = $renderer->renderUrl($url, 90);
            
            if (!$rawHtml) {
                $this->error("❌ JavaScript rendering failed");
                return 1;
            }
            
            $this->info("✅ JavaScript rendering successful");
            $this->line("Raw HTML length: " . strlen($rawHtml));
            $this->line("");
            
            // STEP 2: WebScraperService Content Extraction with Tenant Configuration
            $this->line("📝 STEP 2: Content Extraction with Tenant Config...");
            
            // ✅ Use tenant configuration for extraction patterns
            $webScraperInstance = new WebScraperService();
            
            // Store tenant context for pattern access
            $tenant = \App\Models\Tenant::findOrFail($tenantId);
            $config = \App\Models\ScraperConfig::where('tenant_id', $tenantId)->first();
            
            if ($config) {
                $this->line("✅ Using tenant scraper config: {$config->name}");
                if (!empty($config->extraction_patterns)) {
                    $this->line("🎯 Custom extraction patterns found: " . count($config->extraction_patterns));
                }
            } else {
                $this->warn("⚠️  No tenant scraper config found - using global patterns only");
            }
            
            // Use reflection to access private method but with tenant context
            $scraperService = new \ReflectionClass(WebScraperService::class);
            $extractContentMethod = $scraperService->getMethod('extractContent');
            $extractContentMethod->setAccessible(true);
            
            // Set current config for tenant patterns
            if ($config) {
                $currentConfigProperty = $scraperService->getProperty('currentConfig');
                $currentConfigProperty->setAccessible(true);
                $currentConfigProperty->setValue($webScraperInstance, $config);
            }
            
            $extractedContent = $extractContentMethod->invokeArgs($webScraperInstance, [$rawHtml, $url]);
            
            if (!$extractedContent) {
                $this->error("❌ Content extraction failed");
                return 1;
            }
            
            $this->info("✅ Content extraction successful");
            $this->line("Title: " . $extractedContent['title']);
            $this->line("Content length: " . strlen($extractedContent['content']));
            $this->line("");
            
            // STEP 3: Content Analysis
            $this->line("📊 STEP 3: Content Analysis...");
            $text = strip_tags($extractedContent['content']);
            $hasPedibus = stripos($text, 'pedibus') !== false;
            $hasAttivazione = stripos($text, 'attivazione') !== false;
            $hasServizio = stripos($text, 'servizio') !== false;
            
            $this->line("- Contains 'pedibus': " . ($hasPedibus ? '✅ YES' : '❌ NO'));
            $this->line("- Contains 'attivazione': " . ($hasAttivazione ? '✅ YES' : '❌ NO'));
            $this->line("- Contains 'servizio': " . ($hasServizio ? '✅ YES' : '❌ NO'));
            $this->line("");
            
            // STEP 4: Show content preview
            $this->line("📄 Markdown Content Preview (first 1000 chars):");
            $this->line(str_repeat('-', 60));
            $cleanContent = preg_replace('/\s+/', ' ', trim($extractedContent['content']));
            $this->line(substr($cleanContent, 0, 1000) . '...');
            $this->line(str_repeat('-', 60));
            $this->line("");
            
            // STEP 5: Save debug files
            $debugDir = storage_path('app/debug');
            if (!file_exists($debugDir)) {
                mkdir($debugDir, 0755, true);
            }
            
            $timestamp = now()->format('Y-m-d_H-i-s');
            $rawHtmlFile = $debugDir . "/markdown_debug_raw_{$timestamp}.html";
            $markdownFile = $debugDir . "/markdown_debug_final_{$timestamp}.md";
            
            file_put_contents($rawHtmlFile, $rawHtml);
            file_put_contents($markdownFile, $extractedContent['content']);
            
            $this->line("💾 Debug files saved:");
            $this->line("- Raw HTML: {$rawHtmlFile}");
            $this->line("- Final Markdown: {$markdownFile}");
            
            // STEP 6: Quality analysis if available
            if (isset($extractedContent['quality_analysis'])) {
                $this->line("");
                $this->line("🎯 Quality Analysis:");
                $qa = $extractedContent['quality_analysis'];
                $this->line("- Content Type: " . $qa['content_type']);
                $this->line("- Quality Score: " . $qa['quality_score']);
                $this->line("- Extraction Method: " . $qa['extraction_method']);
                $this->line("- Business Relevance: " . $qa['business_relevance']);
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("💥 Error during conversion debug: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
