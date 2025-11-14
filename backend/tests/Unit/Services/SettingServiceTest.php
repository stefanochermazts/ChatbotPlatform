<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SettingService::class);
    }

    public function test_get_returns_value_from_database(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => 'widget.max_citation_sources',
            'value' => '7',
        ]);

        $result = $this->service->get($tenant->id, 'widget.max_citation_sources');

        $this->assertSame('7', $result);
    }

    public function test_get_returns_default_when_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $result = $this->service->get($tenant->id, 'widget.max_citation_sources', '9');

        $this->assertSame('9', $result);
    }

    public function test_get_max_citation_sources_uses_setting(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => 'widget.max_citation_sources',
            'value' => '4',
        ]);

        $result = $this->service->getMaxCitationSources($tenant->id);

        $this->assertSame(4, $result);
    }

    public function test_get_max_citation_sources_fallback(): void
    {
        $tenant = Tenant::factory()->create();

        config()->set('chat.citation_default_limit', 7);

        $result = $this->service->getMaxCitationSources($tenant->id);

        $this->assertSame(7, $result);
    }

    public function test_get_max_citation_sources_invalid_setting_falls_back_to_default(): void
    {
        $tenant = Tenant::factory()->create();

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => 'widget.max_citation_sources',
            'value' => '0',
        ]);

        config()->set('chat.citation_default_limit', 6);

        $result = $this->service->getMaxCitationSources($tenant->id);

        $this->assertSame(6, $result);
    }
}
