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
        'name',
        'seed_urls',
        'allowed_domains',
        'max_depth',
        'render_js',
        'auth_headers',
        'rate_limit_rps',
        'timeout',
        'sitemap_urls',
        'include_patterns',
        'exclude_patterns',
        'link_only_patterns',
        'target_knowledge_base_id',
        'skip_known_urls',
        'recrawl_days',
        'respect_robots',
        'enabled',
        'interval_minutes',
        'last_run_at',
        'download_linked_documents',
        'linked_extensions',
        'linked_max_size_mb',
        'linked_same_domain_only',
        'linked_target_kb_id',
    ];

    protected $casts = [
        'seed_urls' => 'array',
        'allowed_domains' => 'array',
        'auth_headers' => 'array',
        'sitemap_urls' => 'array',
        'include_patterns' => 'array',
        'exclude_patterns' => 'array',
        'link_only_patterns' => 'array',
        'render_js' => 'boolean',
        'respect_robots' => 'boolean',
        'max_depth' => 'integer',
        'rate_limit_rps' => 'integer',
        'timeout' => 'integer',
        'target_knowledge_base_id' => 'integer',
        'skip_known_urls' => 'boolean',
        'recrawl_days' => 'integer',
        'enabled' => 'boolean',
        'interval_minutes' => 'integer',
        'last_run_at' => 'datetime',
        'download_linked_documents' => 'boolean',
        'linked_extensions' => 'array',
        'linked_max_size_mb' => 'integer',
        'linked_same_domain_only' => 'boolean',
        'linked_target_kb_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}



