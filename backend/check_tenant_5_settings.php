<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

$tenant = Tenant::find(5);

echo '=== TENANT 5 RAG SETTINGS ==='.PHP_EOL.PHP_EOL;

if (! $tenant) {
    echo 'Tenant 5 not found!'.PHP_EOL;
    exit(1);
}

echo "Tenant Name: {$tenant->name}".PHP_EOL;
echo PHP_EOL;

$ragSettings = $tenant->rag_settings;

if (! $ragSettings || (is_string($ragSettings) && trim($ragSettings) === '')) {
    echo '❌ NO CUSTOM RAG SETTINGS - using defaults'.PHP_EOL;
    exit(0);
}

if (is_string($ragSettings)) {
    $ragSettings = json_decode($ragSettings, true);
}

echo '✅ CUSTOM RAG SETTINGS FOUND:'.PHP_EOL.PHP_EOL;
echo json_encode($ragSettings, JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL;

if (isset($ragSettings['hybrid'])) {
    echo '🔧 HYBRID SECTION (retrieval params):'.PHP_EOL;
    foreach ($ragSettings['hybrid'] as $key => $value) {
        echo "  {$key} = {$value}".PHP_EOL;
    }
} else {
    echo 'ℹ️  No hybrid overrides in tenant settings'.PHP_EOL;
}
