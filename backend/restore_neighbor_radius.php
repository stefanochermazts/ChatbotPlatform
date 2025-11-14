<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

$tenant = Tenant::find(5);
$ragSettings = is_string($tenant->rag_settings)
    ? json_decode($tenant->rag_settings, true)
    : $tenant->rag_settings;

$ragSettings['hybrid']['neighbor_radius'] = 1;  // Restore to default

$tenant->rag_settings = json_encode($ragSettings, JSON_PRETTY_PRINT);
$tenant->save();

\Illuminate\Support\Facades\Cache::forget('rag_config_tenant_5');
\Illuminate\Support\Facades\Artisan::call('cache:clear');

echo '✅ Restored neighbor_radius to 1'.PHP_EOL;
