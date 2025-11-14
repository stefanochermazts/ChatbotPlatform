<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SettingService
{
    private const CACHE_PREFIX = 'tenant_setting_';

    private const DEFAULT_MAX_CITATION_SOURCES = 5;

    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->cacheKey($tenantId, $key);

        return Cache::remember($cacheKey, 300, function () use ($tenantId, $key, $default) {
            $setting = TenantSetting::query()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->first();

            return $setting?->value ?? $default;
        });
    }

    public function getMaxCitationSources(int $tenantId): int
    {
        $default = $this->defaultMaxCitationSources();
        $value = $this->get($tenantId, 'widget.max_citation_sources');

        if ($value === null) {
            Log::debug('settings.citation_sources.default', [
                'tenant_id' => $tenantId,
                'reason' => 'missing_setting',
                'default' => $default,
            ]);

            return $default;
        }

        $intValue = (int) $value;

        if ($intValue <= 0) {
            Log::warning('settings.invalid_max_citation_sources', [
                'tenant_id' => $tenantId,
                'value' => $value,
                'default' => $default,
            ]);

            return $default;
        }

        return $intValue;
    }

    public function set(int $tenantId, string $key, string $value): void
    {
        TenantSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value]
        );

        Cache::forget($this->cacheKey($tenantId, $key));
    }

    public function forget(int $tenantId, string $key): void
    {
        TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->delete();

        Cache::forget($this->cacheKey($tenantId, $key));
    }

    private function cacheKey(int $tenantId, string $key): string
    {
        return self::CACHE_PREFIX.$tenantId.'_'.$key;
    }

    private function defaultMaxCitationSources(): int
    {
        return (int) config('chat.citation_default_limit', self::DEFAULT_MAX_CITATION_SOURCES);
    }
}
