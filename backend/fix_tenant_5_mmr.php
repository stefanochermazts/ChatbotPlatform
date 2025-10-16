<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo "=== FIX TENANT 5 MMR LAMBDA ===" . PHP_EOL . PHP_EOL;

$tenant = Tenant::find(5);

if (!$tenant) {
    echo "Tenant 5 not found!" . PHP_EOL;
    exit(1);
}

$ragSettings = is_string($tenant->rag_settings) 
    ? json_decode($tenant->rag_settings, true) 
    : $tenant->rag_settings;

echo "BEFORE:" . PHP_EOL;
echo "  mmr_lambda: " . ($ragSettings['hybrid']['mmr_lambda'] ?? 'NOT SET') . PHP_EOL;
echo PHP_EOL;

// Update mmr_lambda to favor relevance over diversity
$ragSettings['hybrid']['mmr_lambda'] = 0.7;  // 70% relevance, 30% diversity

$tenant->rag_settings = json_encode($ragSettings, JSON_PRETTY_PRINT);
$tenant->save();

// Clear cache
\Illuminate\Support\Facades\Cache::forget("rag_config_tenant_5");

echo "AFTER:" . PHP_EOL;
echo "  mmr_lambda: 0.7 (favor relevance)" . PHP_EOL;
echo PHP_EOL;

echo "✅ Tenant 5 MMR lambda updated!" . PHP_EOL;
echo "   This will give 70% weight to relevance vs 30% diversity" . PHP_EOL;
echo "   Doc 4350 should now reach final citations!" . PHP_EOL;

