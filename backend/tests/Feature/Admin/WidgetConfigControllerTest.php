<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WidgetConfig;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_max_citation_sources(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => User::ROLE_ADMIN]);

        WidgetConfig::createDefaultForTenant($tenant);

        $this->actingAs($user);

        $payload = [
            'widget_name' => 'Widget Test',
            'position' => 'bottom-right',
            'theme' => 'default',
            'api_model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 1200,
            'max_citation_sources' => 7,
        ];

        $response = $this->put(
            route('admin.widget-config.update', $tenant),
            $payload
        );

        $response->assertRedirect(route('admin.widget-config.show', $tenant));

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'key' => 'widget.max_citation_sources',
            'value' => '7',
        ]);

        $settings = app(SettingService::class);
        $this->assertSame(7, $settings->getMaxCitationSources($tenant->id));
    }
}


