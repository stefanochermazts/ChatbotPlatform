<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'seed_urls',
        'allowed_domains',
        'max_depth',
        'render_js',
        'auth_headers',
        'rate_limit_rps',
        'sitemap_urls',
        'include_patterns',
        'exclude_patterns',
        'respect_robots',
    ];

    protected $casts = [
        'seed_urls' => 'array',
        'allowed_domains' => 'array',
        'auth_headers' => 'array',
        'sitemap_urls' => 'array',
        'include_patterns' => 'array',
        'exclude_patterns' => 'array',
        'render_js' => 'boolean',
        'respect_robots' => 'boolean',
        'max_depth' => 'integer',
        'rate_limit_rps' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}



