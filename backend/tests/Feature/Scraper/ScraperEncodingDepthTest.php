<?php

namespace Tests\Feature\Scraper;

use App\Jobs\ScrapeUrlJob;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Services\Scraper\WebScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScraperEncodingDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_scraper_handles_windows_1252_control_bytes_and_traverses_depth(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();

        $urls = [];
        for ($i = 0; $i < 12; $i++) {
            $urls[] = "https://example.test/page{$i}";
        }

        Http::fake(function (Request $request) use ($urls) {
            $url = $request->url();
            $index = array_search($url, $urls, true);

            if ($index === false) {
                return Http::response('', 404);
            }

            $next = $urls[$index + 1] ?? null;
            $controlChar = chr(0x8D); // Windows-1252 control byte che provocava errori DOMDocument

            $html = "<html><body>{$controlChar}<h1>Pagina {$index}</h1>";
            if ($next) {
                $html .= "{$controlChar}<a href=\"{$next}\">Prossima {$index}</a>";
            }
            $html .= '</body></html>';

            return Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=ISO-8859-1',
            ]);
        });

        $config = ScraperConfig::factory()->create([
            'tenant_id' => $tenant->id,
            'seed_urls' => [$urls[0]],
            'max_depth' => 11,
            'include_patterns' => null,
            'exclude_patterns' => null,
            'link_only_patterns' => null,
            'skip_known_urls' => false,
            'respect_robots' => false,
            'render_js' => false,
            'rate_limit_rps' => 0,
        ]);

        $service = app(WebScraperService::class);

        $service->scrapeRecursiveParallel($urls[0], $config->fresh(), $tenant, 0);

        Bus::assertDispatchedTimes(ScrapeUrlJob::class, count($urls));
        Http::assertSentCount(count($urls) - 1);
    }
}

