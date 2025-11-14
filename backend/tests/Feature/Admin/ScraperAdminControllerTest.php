<?php

namespace Tests\Feature\Admin;

use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScraperAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_scraper_edit_form(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['role' => User::ROLE_ADMIN]);

        ScraperConfig::factory()->create([
            'tenant_id' => $tenant->id,
            'seed_urls' => ['https://example.com'],
        ]);

        $this->actingAs($user);

        $response = $this->get(route('admin.scraper.edit', $tenant));

        $response->assertOk();
        $response->assertSee('Seed URLs');
        $response->assertSee('Scraper');
    }
}

