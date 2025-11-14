<?php

/**
 * Test N+1 Query Elimination
 *
 * Verifica che la fix N+1 funzioni correttamente
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "========================================\n";
echo "🧪 N+1 QUERY ELIMINATION TEST\n";
echo "========================================\n";
echo "\n";

// Tenant ID: 5 per DEV, 1 per PROD
$tenantId = 5;
$testQuery = 'numeri telefono orari uffici';

echo "Environment: DEV (Tenant ID: $tenantId)\n";
echo "Test Query: '$testQuery'\n";
echo "\n";

// Test 1: Count queries BEFORE retrieve
echo "📊 TEST: RAG Retrieve with Query Counting\n";
echo str_repeat('-', 50)."\n";

DB::enableQueryLog();

$service = app(KbSearchService::class);

$start = microtime(true);
$result = $service->retrieve($tenantId, $testQuery, false);
$duration = (microtime(true) - $start) * 1000;

$queries = DB::getQueryLog();
DB::disableQueryLog();

echo '  Duration: '.round($duration, 2)." ms\n";
echo '  Citations: '.count($result['citations'] ?? [])."\n";
echo '  Total DB Queries: '.count($queries)."\n";
echo "\n";

// Analizza le query per trovare N+1
$documentQueries = 0;
$batchQueries = 0;

foreach ($queries as $query) {
    $sql = $query['query'];

    // Conta query singole su documents
    if (strpos($sql, 'documents WHERE id = ?') !== false && strpos($sql, 'tenant_id = ?') !== false) {
        $documentQueries++;
    }

    // Conta batch queries su documents
    if (strpos($sql, 'documents WHERE id IN') !== false && strpos($sql, 'tenant_id = ?') !== false) {
        $batchQueries++;
    }
}

echo "  Document Queries Analysis:\n";
echo '    - Single N+1 queries: '.$documentQueries."\n";
echo '    - Batch queries (optimized): '.$batchQueries."\n";
echo "\n";

// Verifica risultato
if ($documentQueries > 0) {
    echo "  ❌ FAIL: Still have N+1 queries!\n";
    echo "     Found $documentQueries single document queries\n";
    echo "\n";
    echo "  Example N+1 queries:\n";
    foreach ($queries as $i => $query) {
        if (strpos($query['query'], 'documents WHERE id = ?') !== false) {
            echo '    Query #'.($i + 1).': '.substr($query['query'], 0, 100)."...\n";
            if (--$documentQueries <= 0) {
                break;
            } // Show max 5 examples
        }
    }
} elseif ($batchQueries > 0) {
    echo "  ✅ SUCCESS: N+1 eliminated!\n";
    echo "     Using $batchQueries batch queries instead\n";
    echo "\n";
    echo "  Batch query example:\n";
    foreach ($queries as $query) {
        if (strpos($query['query'], 'documents WHERE id IN') !== false) {
            echo '    '.substr($query['query'], 0, 150)."...\n";
            break;
        }
    }
} else {
    echo "  ℹ️  INFO: No document queries found (empty results or cached)\n";
}

echo "\n";

echo "========================================\n";
echo "💡 EXPECTED RESULTS\n";
echo "========================================\n";
echo "\n";
echo "BEFORE Fix (N+1 problem):\n";
echo "  - 10 citations = 11 queries (1 + 10)\n";
echo "  - 20 citations = 21 queries (1 + 20)\n";
echo "  - Duration: ~700ms\n";
echo "\n";
echo "AFTER Fix (batch query):\n";
echo "  - 10 citations = 2 queries (1 + 1 batch)\n";
echo "  - 20 citations = 2 queries (1 + 1 batch)\n";
echo "  - Duration: ~260ms (2.7x faster)\n";
echo "\n";
echo "✅ N+1 eliminated if 'Single N+1 queries' = 0\n";
echo "\n";
