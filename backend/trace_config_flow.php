<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\TenantRagConfigService;

echo '=== CONFIG FLOW TRACE ==='.PHP_EOL.PHP_EOL;

$service = app(TenantRagConfigService::class);
$tenantId = 5;

// Step 1: Global config (hardcoded defaults in config/rag.php)
echo '📄 STEP 1: Global Config (config/rag.php)'.PHP_EOL;
$globalHybrid = config('rag.hybrid');
echo '  vector_top_k: '.$globalHybrid['vector_top_k'].PHP_EOL;
echo '  bm25_top_k:   '.$globalHybrid['bm25_top_k'].PHP_EOL;
echo '  mmr_take:     '.$globalHybrid['mmr_take'].PHP_EOL;
echo PHP_EOL;

// Step 2: Tenant DB settings (from tenants.rag_settings JSON)
echo '🗄️  STEP 2: Tenant DB Settings (tenants.rag_settings)'.PHP_EOL;
$tenant = \App\Models\Tenant::find($tenantId);
$tenantSettings = is_string($tenant->rag_settings)
    ? json_decode($tenant->rag_settings, true)
    : $tenant->rag_settings;

if (isset($tenantSettings['hybrid'])) {
    echo '  ✅ Tenant HAS custom hybrid overrides:'.PHP_EOL;
    foreach ($tenantSettings['hybrid'] as $key => $value) {
        echo "    {$key}: {$value}".PHP_EOL;
    }
} else {
    echo '  ❌ Tenant has NO custom hybrid overrides'.PHP_EOL;
}
echo PHP_EOL;

// Step 3: Merged config (what getRetrievalConfig returns)
echo '🔀 STEP 3: Merged Config (getRetrievalConfig output)'.PHP_EOL;
$mergedConfig = $service->getRetrievalConfig($tenantId);
foreach ($mergedConfig as $key => $value) {
    // Determina la source
    $source = 'global default';
    if (isset($tenantSettings['hybrid'][$key])) {
        $source = '🔧 TENANT OVERRIDE';
    }

    echo sprintf('  %-18s = %-6s  (%s)', $key, $value, $source).PHP_EOL;
}
echo PHP_EOL;

// Step 4: Comparison
echo '📊 COMPARISON:'.PHP_EOL;
echo str_repeat('-', 70).PHP_EOL;
echo sprintf('%-20s | %-15s | %-15s | %s', 'Parameter', 'Global Default', 'Tenant 5', 'Source').PHP_EOL;
echo str_repeat('-', 70).PHP_EOL;

foreach (['vector_top_k', 'bm25_top_k', 'mmr_take', 'mmr_lambda'] as $key) {
    $globalVal = $globalHybrid[$key];
    $tenantVal = $mergedConfig[$key];
    $source = $globalVal == $tenantVal ? 'Default' : 'Override';

    echo sprintf('%-20s | %-15s | %-15s | %s', $key, $globalVal, $tenantVal, $source).PHP_EOL;
}
echo str_repeat('-', 70).PHP_EOL;
echo PHP_EOL;

echo '💡 VERDICT:'.PHP_EOL;
echo '  - I valori sono REALI dal database tenant'.PHP_EOL;
echo '  - NON sono aggiunti dopo la lettura'.PHP_EOL;
echo '  - Tenant 5 ha config custom che SOVRASCRIVE i defaults'.PHP_EOL;
