<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScraperConfig;
use Illuminate\Http\Request;

class ScraperConfigController extends Controller
{
    public function show(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $cfg = ScraperConfig::query()->where('tenant_id', $tenantId)->first();

        return response()->json($cfg);
    }

    public function storeOrUpdate(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $validated = $request->validate([
            'seed_urls' => ['nullable', 'array'],
            'allowed_domains' => ['nullable', 'array'],
            'max_depth' => ['nullable', 'integer', 'min:0', 'max:10'],
            'render_js' => ['nullable', 'boolean'],
            'auth_headers' => ['nullable', 'array'],
            'rate_limit_rps' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sitemap_urls' => ['nullable', 'array'],
            'include_patterns' => ['nullable', 'array'],
            'exclude_patterns' => ['nullable', 'array'],
            'link_only_patterns' => ['nullable', 'array'],
            'respect_robots' => ['nullable', 'boolean'],
        ]);

        $cfg = ScraperConfig::updateOrCreate(
            ['tenant_id' => $tenantId],
            $validated
        );

        return response()->json($cfg);
    }
}
