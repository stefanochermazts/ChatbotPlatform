<?php

namespace Database\Factories;

use App\Enums\Scraper\TitleStrategy;
use App\Models\ScraperConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScraperConfig>
 */
class ScraperConfigFactory extends Factory
{
    protected $model = ScraperConfig::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'name' => $this->faker->words(3, true),
            'seed_urls' => [$this->faker->url()],
            'allowed_domains' => null,
            'max_depth' => 2,
            'render_js' => false,
            'auth_headers' => null,
            'rate_limit_rps' => 1,
            'timeout' => 30,
            'sitemap_urls' => null,
            'include_patterns' => null,
            'exclude_patterns' => null,
            'link_only_patterns' => null,
            'target_knowledge_base_id' => null,
            'skip_known_urls' => false,
            'recrawl_days' => null,
            'respect_robots' => true,
            'enabled' => true,
            'interval_minutes' => null,
            'last_run_at' => null,
            'download_linked_documents' => false,
            'linked_extensions' => null,
            'linked_max_size_mb' => 10,
            'linked_same_domain_only' => true,
            'linked_target_kb_id' => null,
            'js_timeout' => null,
            'js_navigation_timeout' => null,
            'js_content_wait' => null,
            'js_scroll_delay' => null,
            'js_final_wait' => null,
            'title_strategy' => TitleStrategy::default()->value,
        ];
    }
}
