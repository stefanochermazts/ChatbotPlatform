<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\TenantRagConfigService;

echo "=== TEST getRetrievalConfig() ===" . PHP_EOL . PHP_EOL;

$service = app(TenantRagConfigService::class);
$tenantId = 5;

try {
    $config = $service->getRetrievalConfig($tenantId);
    
    echo "✅ SUCCESS! Retrieved config for tenant {$tenantId}:" . PHP_EOL . PHP_EOL;
    
    foreach ($config as $key => $value) {
        $type = is_int($value) ? 'int' : (is_float($value) ? 'float' : 'unknown');
        echo sprintf("  %-18s = %-6s (%s)", $key, $value, $type) . PHP_EOL;
    }
    
    echo PHP_EOL;
    echo str_repeat('=', 60) . PHP_EOL;
    echo PHP_EOL;
    
    // Verify critical value
    if ($config['vector_top_k'] === 100) {
        echo "🎯 PERFECT! vector_top_k = 100 (was 20 before fix)" . PHP_EOL;
    } else {
        echo "⚠️  vector_top_k = {$config['vector_top_k']} (expected 100)" . PHP_EOL;
    }
    
    echo PHP_EOL;
    
    // Test also global config direct read
    $globalHybrid = config('rag.hybrid');
    echo "Global config (rag.hybrid):" . PHP_EOL;
    echo "  vector_top_k = " . ($globalHybrid['vector_top_k'] ?? 'NOT SET') . PHP_EOL;
    echo "  bm25_top_k   = " . ($globalHybrid['bm25_top_k'] ?? 'NOT SET') . PHP_EOL;
    
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

