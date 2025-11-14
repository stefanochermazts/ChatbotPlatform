<?php

namespace App\Console\Commands;

use App\Models\ScraperConfig;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PreviewScrapingPlan extends Command
{
    protected $signature = 'scraper:preview-plan {tenant_id}';

    protected $description = 'Preview how many URLs will be scraped and estimated time';

    public function handle()
    {
        $tenantId = $this->argument('tenant_id');

        $this->info('🔍 SCRAPING PREVIEW ANALYSIS');
        $this->line("Tenant ID: {$tenantId}");
        $this->line('Timestamp: '.now()->toISOString());
        $this->line('');

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $this->line("✅ Tenant: {$tenant->name}");

            $config = ScraperConfig::where('tenant_id', $tenantId)->first();
            if (! $config) {
                $this->error("❌ No scraper configuration found for tenant {$tenantId}");

                return 1;
            }

            $this->line("✅ Scraper config found: {$config->name}");
            $this->line('');

            // Configuration details
            $this->line('📋 Configuration Details:');
            $this->line("- Max depth: {$config->max_depth}");
            $this->line("- Rate limit: {$config->rate_limit_rps} RPS");
            $this->line("- Timeout: {$config->timeout}s");
            $this->line('- Render JS: '.($config->render_js ? 'YES' : 'NO'));
            $this->line('- Respect robots: '.($config->respect_robots ? 'YES' : 'NO'));
            $this->line('');

            // Seed URLs
            $seedUrls = $config->seed_urls ?? [];
            $this->line('🌱 Seed URLs ('.count($seedUrls).'):');
            foreach ($seedUrls as $index => $url) {
                $this->line('  '.($index + 1).". {$url}");
            }
            $this->line('');

            // Sitemap URLs
            $sitemapUrls = $config->sitemap_urls ?? [];
            if (! empty($sitemapUrls)) {
                $this->line('🗺️ Sitemap URLs ('.count($sitemapUrls).'):');
                foreach ($sitemapUrls as $index => $url) {
                    $this->line('  '.($index + 1).". {$url}");
                }
                $this->line('');
            }

            // Estimate URLs from sitemaps
            $sitemapUrlCount = 0;
            if (! empty($sitemapUrls)) {
                $this->line('🔍 Analyzing sitemaps...');
                foreach ($sitemapUrls as $sitemapUrl) {
                    try {
                        $response = Http::timeout(30)->get($sitemapUrl);
                        if ($response->successful()) {
                            $xml = simplexml_load_string($response->body());
                            if ($xml) {
                                $urlCount = count($xml->url ?? []);
                                $sitemapUrlCount += $urlCount;
                                $this->line("  - {$sitemapUrl}: {$urlCount} URLs");
                            }
                        }
                    } catch (\Exception $e) {
                        $this->warn("  - {$sitemapUrl}: Error ({$e->getMessage()})");
                    }
                }
                $this->line('');
            }

            // Include/Exclude patterns
            if (! empty($config->include_patterns)) {
                $this->line('✅ Include patterns:');
                foreach ($config->include_patterns as $pattern) {
                    $this->line("  - {$pattern}");
                }
                $this->line('');
            }

            if (! empty($config->exclude_patterns)) {
                $this->line('❌ Exclude patterns:');
                foreach ($config->exclude_patterns as $pattern) {
                    $this->line("  - {$pattern}");
                }
                $this->line('');
            }

            // Estimates
            $this->line('📊 ESTIMATES:');

            // Base calculation
            $baseUrls = count($seedUrls);
            $fromSitemap = $sitemapUrlCount;

            // Recursive estimate (very rough)
            $depthMultiplier = max(1, $config->max_depth);
            $avgLinksPerPage = 10; // Conservative estimate
            $recursiveUrls = $baseUrls * (($avgLinksPerPage ** $depthMultiplier - 1) / ($avgLinksPerPage - 1));

            // Apply filtering estimate (assume 30% pass filters)
            $filteringEfficiency = 0.3;
            $estimatedUrls = ($recursiveUrls + $fromSitemap) * $filteringEfficiency;

            $this->line("- Base seed URLs: {$baseUrls}");
            $this->line("- URLs from sitemaps: {$fromSitemap}");
            $this->line('- Estimated recursive URLs: '.number_format($recursiveUrls, 0));
            $this->line('- Estimated after filtering: '.number_format($estimatedUrls, 0));
            $this->line('');

            // Time estimates
            $avgTimePerUrl = $config->render_js ? 45 : 5; // seconds
            $rateLimit = $config->rate_limit_rps;
            $minTimePerUrl = $rateLimit > 0 ? (1 / $rateLimit) : 0;
            $effectiveTimePerUrl = max($avgTimePerUrl, $minTimePerUrl);

            $totalTimeSeconds = $estimatedUrls * $effectiveTimePerUrl;
            $totalTimeHours = $totalTimeSeconds / 3600;

            $this->line('⏱️ TIME ESTIMATES:');
            $this->line("- Average time per URL: {$effectiveTimePerUrl}s");
            $this->line('- Total estimated time: '.number_format($totalTimeSeconds, 0).'s ('.number_format($totalTimeHours, 1).' hours)');
            $this->line('');

            // Warnings
            if ($totalTimeHours > 24) {
                $this->warn('⚠️  WARNING: Estimated time > 24 hours');
            }
            if ($estimatedUrls > 1000) {
                $this->warn('⚠️  WARNING: Large number of URLs to process');
            }
            if ($config->render_js) {
                $this->warn('⚠️  JavaScript rendering enabled - this will be slow but thorough');
            }

            // Recommendations
            $this->line('💡 RECOMMENDATIONS:');
            if ($totalTimeHours > 12) {
                $this->line('- Consider running in smaller batches');
                $this->line('- Monitor progress regularly');
            }
            if ($config->render_js && $estimatedUrls > 100) {
                $this->line('- Consider disabling JS rendering for simple pages');
                $this->line('- Enable only for dynamic content pages');
            }
            $this->line('- Use progressive scraping for real-time monitoring');
            $this->line('- Test with a few URLs first');

            return 0;

        } catch (\Exception $e) {
            $this->error('💥 Error: '.$e->getMessage());

            return 1;
        }
    }
}
