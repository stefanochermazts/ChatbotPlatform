<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\MilvusClient;

echo "=== STEP 1: VERIFY EMBEDDINGS FOR DOCUMENT 4350 ===" . PHP_EOL . PHP_EOL;

$tenantId = 5;
$documentId = 4350;

echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo "Document ID: {$documentId}" . PHP_EOL;
echo PHP_EOL;

try {
    // Inizializza MilvusClient
    $milvus = new MilvusClient();
    
    // Usa il nuovo metodo count_by_document
    echo "🔍 Querying Milvus for document embeddings..." . PHP_EOL;
    
    // Creo uno script temporaneo per chiamare Python
    $pythonScript = base_path('milvus_search.py');
    $collection = config('rag.vector.milvus.collection', 'kb_chunks_v1');
    
    // Prepara parametri
    $params = [
        'operation' => 'count_by_document',
        'collection' => $collection,
        'tenant_id' => $tenantId,
        'document_id' => $documentId
    ];
    
    // Usa file temporaneo per parametri (Windows compatibility)
    $tempFile = tempnam(sys_get_temp_dir(), 'milvus_verify_');
    file_put_contents($tempFile, json_encode($params));
    
    $pythonPath = config('rag.vector.milvus.python_path', 'python');
    $command = "\"{$pythonPath}\" \"{$pythonScript}\" \"@{$tempFile}\" 2>&1";
    
    echo "Executing: {$command}" . PHP_EOL;
    echo PHP_EOL;
    
    $output = shell_exec($command);
    
    // Cleanup
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    
    if (empty($output)) {
        echo "❌ ERROR: No output from Python script!" . PHP_EOL;
        exit(1);
    }
    
    $result = json_decode(trim($output), true);
    
    if (!$result) {
        echo "❌ ERROR: Invalid JSON response!" . PHP_EOL;
        echo "Raw output:" . PHP_EOL;
        echo $output . PHP_EOL;
        exit(1);
    }
    
    if (!$result['success']) {
        echo "❌ ERROR: Milvus query failed!" . PHP_EOL;
        echo "Error: " . ($result['error'] ?? 'Unknown error') . PHP_EOL;
        echo "Error type: " . ($result['error_type'] ?? 'Unknown') . PHP_EOL;
        exit(1);
    }
    
    // Success! Display results
    $count = $result['count'] ?? 0;
    $chunkIndices = $result['chunk_indices'] ?? [];
    
    echo "✅ SUCCESS: Query completed!" . PHP_EOL;
    echo PHP_EOL;
    echo "📊 RESULTS:" . PHP_EOL;
    echo "  - Vectors found: {$count}" . PHP_EOL;
    echo "  - Chunk indices: " . implode(', ', $chunkIndices) . PHP_EOL;
    echo PHP_EOL;
    
    // Evaluate result
    echo "🎯 EVALUATION:" . PHP_EOL;
    
    if ($count === 3) {
        echo "  ✅ PERFECT! Document 4350 has exactly 3 embeddings (as expected)" . PHP_EOL;
        echo "  ✅ All chunks are indexed in Milvus" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Embeddings exist → Proceed to Step 2 (Manual Similarity Search)" . PHP_EOL;
        echo "  - The problem is NOT missing embeddings" . PHP_EOL;
        echo "  - Investigate similarity threshold or KB selection logic" . PHP_EOL;
        exit(0);
    } elseif ($count === 0) {
        echo "  ❌ CRITICAL: NO embeddings found for document 4350!" . PHP_EOL;
        echo "  ❌ Document was chunked but NEVER indexed in Milvus" . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 ROOT CAUSE IDENTIFIED:" . PHP_EOL;
        echo "  - Chunks exist in PostgreSQL (verified earlier)" . PHP_EOL;
        echo "  - Embeddings DO NOT exist in Milvus" . PHP_EOL;
        echo "  - This is why retrieval fails!" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Skip Step 2 and 3" . PHP_EOL;
        echo "  - Proceed directly to Step 4 (Re-embed and Re-index Document 4350)" . PHP_EOL;
        echo "  - Command: php artisan rag:reindex-document 4350" . PHP_EOL;
        exit(1);
    } else {
        echo "  ⚠️  WARNING: Unexpected number of embeddings!" . PHP_EOL;
        echo "  ⚠️  Expected: 3 (from 3 chunks)" . PHP_EOL;
        echo "  ⚠️  Found: {$count}" . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 POSSIBLE ISSUES:" . PHP_EOL;
        echo "  - Partial indexing failure (some chunks missing)" . PHP_EOL;
        echo "  - Re-ingestion interrupted" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Proceed to Step 4 (Re-embed and Re-index) to fix incomplete indexing" . PHP_EOL;
        exit(1);
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

