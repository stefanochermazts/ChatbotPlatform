<?php

/**
 * Test Performance Database Indexes
 * 
 * Misura le performance BEFORE e AFTER la migration degli indici
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\RAG\TextSearchService;
use App\Models\Document;

echo "\n";
echo "========================================\n";
echo "🧪 DATABASE INDEXES PERFORMANCE TEST\n";
echo "========================================\n";
echo "\n";

// Tenant ID: 5 per DEV
$tenantId = 5;
$kbId = 1;

echo "Environment: DEV (Tenant ID: $tenantId)\n";
echo "\n";

//==============================================================================
// TEST 1: RAG Query (BM25 Full-Text Search)
//==============================================================================

echo "📊 TEST 1: RAG Query (BM25 Search)\n";
echo str_repeat("-", 50) . "\n";

DB::enableQueryLog();
$service = new TextSearchService();

$start = microtime(true);
$result = $service->searchTopK($tenantId, 'numeri telefono orari uffici', 50, $kbId);
$duration = (microtime(true) - $start) * 1000;

$queries = DB::getQueryLog();
DB::disableQueryLog();

echo "  Duration: " . round($duration, 2) . " ms\n";
echo "  Results: " . count($result) . "\n";
echo "  DB Queries: " . count($queries) . "\n";

if (count($queries) > 0) {
    echo "  Query Time: " . round($queries[0]['time'], 2) . " ms\n";
}

echo "\n";

//==============================================================================
// TEST 2: Admin Document Filtering
//==============================================================================

echo "📊 TEST 2: Admin Document Filtering\n";
echo str_repeat("-", 50) . "\n";

DB::enableQueryLog();

$start = microtime(true);
$docs = Document::where('tenant_id', $tenantId)
    ->where('knowledge_base_id', $kbId)
    ->limit(10)
    ->get();
$duration = (microtime(true) - $start) * 1000;

$queries = DB::getQueryLog();
DB::disableQueryLog();

echo "  Duration: " . round($duration, 2) . " ms\n";
echo "  Documents: " . $docs->count() . "\n";
echo "  DB Queries: " . count($queries) . "\n";

if (count($queries) > 0) {
    echo "  Query Time: " . round($queries[0]['time'], 2) . " ms\n";
}

echo "\n";

//==============================================================================
// TEST 3: Document Chunks Count (for cascade delete)
//==============================================================================

echo "📊 TEST 3: Document Chunks Count\n";
echo str_repeat("-", 50) . "\n";

DB::enableQueryLog();

$start = microtime(true);
$count = DB::table('document_chunks')
    ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
    ->where('document_chunks.tenant_id', $tenantId)
    ->where('documents.knowledge_base_id', $kbId)
    ->count();
$duration = (microtime(true) - $start) * 1000;

$queries = DB::getQueryLog();
DB::disableQueryLog();

echo "  Duration: " . round($duration, 2) . " ms\n";
echo "  Chunks: " . $count . "\n";
echo "  DB Queries: " . count($queries) . "\n";

if (count($queries) > 0) {
    echo "  Query Time: " . round($queries[0]['time'], 2) . " ms\n";
}

echo "\n";

//==============================================================================
// TEST 4: Source URL Deduplication Check
//==============================================================================

echo "📊 TEST 4: Source URL Deduplication\n";
echo str_repeat("-", 50) . "\n";

DB::enableQueryLog();

$start = microtime(true);
$doc = Document::where('tenant_id', $tenantId)
    ->where('source_url', 'https://test.example.com/page')
    ->first();
$duration = (microtime(true) - $start) * 1000;

$queries = DB::getQueryLog();
DB::disableQueryLog();

echo "  Duration: " . round($duration, 2) . " ms\n";
echo "  Found: " . ($doc ? 'Yes' : 'No') . "\n";
echo "  DB Queries: " . count($queries) . "\n";

if (count($queries) > 0) {
    echo "  Query Time: " . round($queries[0]['time'], 2) . " ms\n";
}

echo "\n";

//==============================================================================
// CHECK EXISTING INDEXES
//==============================================================================

echo "🔍 CURRENT DATABASE INDEXES\n";
echo str_repeat("-", 50) . "\n";

$indexes = DB::select("
    SELECT 
        tablename,
        indexname,
        indexdef
    FROM pg_indexes 
    WHERE schemaname = 'public'
      AND tablename IN ('document_chunks', 'documents', 'conversation_sessions')
      AND indexname LIKE 'idx_%'
    ORDER BY tablename, indexname
");

if (empty($indexes)) {
    echo "  ⚠️  No custom indexes found (idx_* pattern)\n";
} else {
    foreach ($indexes as $idx) {
        echo "  ✅ {$idx->tablename}.{$idx->indexname}\n";
    }
}

echo "\n";

//==============================================================================
// SUMMARY
//==============================================================================

echo "========================================\n";
echo "💡 NOTES\n";
echo "========================================\n";
echo "\n";
echo "1. Save these numbers for comparison\n";
echo "2. Run: php artisan migrate\n";
echo "3. Re-run this script to see improvements\n";
echo "\n";
echo "Expected improvements after migration:\n";
echo "  - RAG Query: ~5x faster\n";
echo "  - Admin Filtering: ~5x faster\n";
echo "  - Chunks Count: ~5x faster\n";
echo "\n";

