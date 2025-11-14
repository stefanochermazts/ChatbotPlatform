<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scraper;

use App\Enums\Scraper\TitleStrategy;
use App\Services\Scraper\WebScraperService;
use Tests\TestCase;

class WebScraperServiceTitleStrategyTest extends TestCase
{
    private function invokePrepareSourcePageTitle(string $html, TitleStrategy $strategy): ?string
    {
        $service = new WebScraperService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('prepareSourcePageTitle');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke($service, $html, $strategy);

        return $result;
    }

    public function test_uses_h1_when_configured(): void
    {
        $html = '<html><head><title>Fallback Title</title></head><body><h1>Main Heading</h1></body></html>';

        $title = $this->invokePrepareSourcePageTitle($html, TitleStrategy::H1);

        $this->assertSame('Main Heading', $title);
    }

    public function test_concatenates_h1_and_h2_when_configured(): void
    {
        $html = '<html><head><title>Fallback Title</title></head><body><h1>Main Heading</h1><h2>Section Heading</h2></body></html>';

        $title = $this->invokePrepareSourcePageTitle($html, TitleStrategy::H1_H2);

        $this->assertSame('Main Heading Section Heading', $title);
    }

    public function test_falls_back_to_title_when_configured_element_missing(): void
    {
        $html = '<html><head><title>Fallback Title</title></head><body><p>No heading here</p></body></html>';

        $title = $this->invokePrepareSourcePageTitle($html, TitleStrategy::H1);

        $this->assertSame('Fallback Title', $title);
    }
}
