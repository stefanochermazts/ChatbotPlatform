<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

class RagTelemetry
{
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('rag.telemetry.enabled', true);
    }

    public function event(string $name, array $data = []): void
    {
        if (! $this->enabled) {
            return;
        }
        Log::info('rag.'.$name, $data);
    }
}
