<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\Scraper\TitleStrategy;
use App\Models\ScraperConfig;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ScraperConfigTest extends TestCase
{
    public function test_title_strategy_cast_returns_enum(): void
    {
        $config = ScraperConfig::factory()->make([
            'title_strategy' => TitleStrategy::H1->value,
        ]);

        $this->assertSame(TitleStrategy::H1, $config->titleStrategy());
    }

    public function test_invalid_title_strategy_falls_back_to_title_and_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'scraper_config.title_strategy.invalid'
                    && $context['provided'] === 'invalid';
            });

        $config = ScraperConfig::factory()->make([
            'title_strategy' => 'invalid',
        ]);

        $this->assertSame(TitleStrategy::TITLE, $config->titleStrategy());
    }
}
