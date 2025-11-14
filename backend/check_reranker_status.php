<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Checking Reranker Status for Tenant 5\n";
echo str_repeat('=', 60)."\n\n";

// Get tenant
$tenant = \App\Models\Tenant::find(5);
if (! $tenant) {
    exit("❌ Tenant 5 not found\n");
}

// Get RAG settings
$ragSettings = $tenant->rag_settings ?? [];

echo "📋 Raw RAG Settings from DB:\n";
echo json_encode($ragSettings, JSON_PRETTY_PRINT)."\n\n";

// Get reranker config via service
try {
    $service = app(\App\Services\RAG\TenantRagConfigService::class);
    $rerankerConfig = $service->getRerankerConfig(5);

    echo "📊 Reranker Config (from TenantRagConfigService):\n";
    echo json_encode($rerankerConfig, JSON_PRETTY_PRINT)."\n\n";

    // Status
    $enabled = $rerankerConfig['enabled'] ?? false;
    $driver = $rerankerConfig['driver'] ?? 'unknown';
    $topK = $rerankerConfig['top_k'] ?? 10;

    echo "🎯 Summary:\n";
    echo '   Enabled: '.($enabled ? '✅ YES' : '❌ NO')."\n";
    echo "   Driver:  {$driver}\n";
    echo "   Top K:   {$topK}\n";

    if (! $enabled) {
        echo "\n⚠️  Reranker is currently DISABLED\n";
    } else {
        echo "\n✅ Reranker is currently ACTIVE\n";
    }

} catch (\Exception $e) {
    echo '❌ Error getting reranker config: '.$e->getMessage()."\n";
}
