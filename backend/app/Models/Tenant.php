<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'metadata',
        'languages',
        'default_language',
        'custom_system_prompt',
        'custom_context_template',
        'intents_enabled',
        'extra_intent_keywords',
        'kb_scope_mode',
        'intent_min_score',
        'custom_synonyms',
    ];

    protected $casts = [
        'metadata' => 'array',
        'languages' => 'array',
        'intents_enabled' => 'array',
        'extra_intent_keywords' => 'array',
        'custom_synonyms' => 'array',
    ];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function widgetConfig(): HasOne
    {
        return $this->hasOne(WidgetConfig::class);
    }

    /**
     * Get the API key for widget integration
     */
    public function getWidgetApiKey(): ?string
    {
        // Prefer the stored (encrypted) key if available; fallback to null
        $apiKey = $this->apiKeys()->whereNull('revoked_at')->latest()->first();
        if (!$apiKey) {
            return null;
        }
        return $apiKey->key ?? null;
    }

    /**
     * Get tenant forms for this tenant
     */
    public function forms(): HasMany
    {
        return $this->hasMany(TenantForm::class);
    }

    /**
     * Get form submissions for this tenant
     */
    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}



