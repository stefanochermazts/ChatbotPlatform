<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo "=== DISABLE RERANKER FOR TENANT 5 ===" . PHP_EOL . PHP_EOL;

$tenant = Tenant::find(5);

if (!$tenant) {
    echo "❌ Tenant 5 not found!" . PHP_EOL;
    exit(1);
}

$ragSettings = is_string($tenant->rag_settings) 
    ? json_decode($tenant->rag_settings, true) 
    : $tenant->rag_settings;

echo "BEFORE:" . PHP_EOL;
$currentValue = $ragSettings['reranker']['enabled'] ?? null;
if ($currentValue === true) {
    echo "  reranker.enabled: true" . PHP_EOL;
} elseif ($currentValue === false) {
    echo "  reranker.enabled: false" . PHP_EOL;
} else {
    echo "  reranker.enabled: NOT SET" . PHP_EOL;
}
echo PHP_EOL;

// Disable reranker
if (!isset($ragSettings['reranker'])) {
    $ragSettings['reranker'] = [];
}
$ragSettings['reranker']['enabled'] = false;

$tenant->rag_settings = json_encode($ragSettings, JSON_PRETTY_PRINT);
$tenant->save();

// Clear cache
\Illuminate\Support\Facades\Cache::forget("rag_config_tenant_5");
\Illuminate\Support\Facades\Artisan::call('cache:clear');

echo "AFTER:" . PHP_EOL;
echo "  reranker.enabled: false" . PHP_EOL;
echo PHP_EOL;

echo "✅ Reranker DISABILITATO per tenant 5!" . PHP_EOL;
echo "   Doc 4350 dovrebbe ora arrivare alle citations finali." . PHP_EOL;
echo PHP_EOL;
echo "🧪 TEST ORA IL RAG TESTER:" . PHP_EOL;
echo "   Query: 'telefono comando polizia locale'" . PHP_EOL;
echo "   Expected: Telefono 06.95898223" . PHP_EOL;

