<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Cache;

class RagCache
{
    public function remember(string $key, callable $callback)
    {
        $enabled = (bool) config('rag.cache.enabled', true);
        $ttl = (int) config('rag.cache.ttl_seconds', 120);
        if (! $enabled) {
            return $callback();
        }

        return Cache::remember($key, $ttl, $callback);
    }
}
