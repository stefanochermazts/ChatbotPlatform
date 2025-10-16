<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\MilvusClient;

echo "=== DELETE DOC 4350 VECTORS FROM MILVUS ===" . PHP_EOL . PHP_EOL;

$docId = 4350;
$tenantId = 5;

try {
    $milvusClient = app(MilvusClient::class);
    
    echo "Deleting vectors for doc ID {$docId} (tenant {$tenantId})..." . PHP_EOL;
    
    $milvusClient->deleteByDocument($tenantId, $docId);
    
    echo "✅ Deleted vectors from Milvus!" . PHP_EOL;
    echo "   Document ID: {$docId}" . PHP_EOL . PHP_EOL;
    
    echo "Now re-ingest the document:" . PHP_EOL;
    echo "  php backend/reingest_doc_4285.php" . PHP_EOL . PHP_EOL;
    echo "Then process with queue worker:" . PHP_EOL;
    echo "  php artisan queue:work --queue=ingestion,embeddings,indexing --stop-when-empty" . PHP_EOL;
    
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

