<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo '=== TENANT 5 RAG CONFIG ==='.PHP_EOL.PHP_EOL;

$tenant = Tenant::find(5);

if (! $tenant) {
    echo 'Tenant 5 not found!'.PHP_EOL;
    exit(1);
}

echo "Tenant ID: {$tenant->id}".PHP_EOL;
echo "Name: {$tenant->name}".PHP_EOL;
echo PHP_EOL;

$ragConfig = $tenant->rag_config;

if (! $ragConfig || empty($ragConfig)) {
    echo 'NO CUSTOM RAG CONFIG - using global defaults'.PHP_EOL.PHP_EOL;

    echo 'Global defaults:'.PHP_EOL;
    echo '  - vector_top_k: '.config('rag.hybrid.vector_top_k').PHP_EOL;
    echo '  - bm25_top_k: '.config('rag.hybrid.bm25_top_k').PHP_EOL;
    echo '  - mmr_take: '.config('rag.hybrid.mmr_take').PHP_EOL;
    echo '  - mmr_lambda: '.config('rag.hybrid.mmr_lambda').PHP_EOL;
} else {
    echo '✅ CUSTOM RAG CONFIG FOUND!'.PHP_EOL.PHP_EOL;

    echo json_encode($ragConfig, JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL;

    if (isset($ragConfig['hybrid'])) {
        echo '⚠️  CUSTOM HYBRID CONFIG:'.PHP_EOL;
        echo '  - vector_top_k: '.($ragConfig['hybrid']['vector_top_k'] ?? 'NOT SET').PHP_EOL;
        echo '  - bm25_top_k: '.($ragConfig['hybrid']['bm25_top_k'] ?? 'NOT SET').PHP_EOL;
        echo '  - mmr_take: '.($ragConfig['hybrid']['mmr_take'] ?? 'NOT SET').PHP_EOL;
        echo '  - mmr_lambda: '.($ragConfig['hybrid']['mmr_lambda'] ?? 'NOT SET').PHP_EOL.PHP_EOL;

        echo '💡 This tenant-specific config overrides global config!'.PHP_EOL;
    }
}
