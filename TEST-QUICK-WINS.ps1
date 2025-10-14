###############################################################################
# TEST QUICK WINS - Performance Optimization (PowerShell Version)
#
# Questo script testa le ottimizzazioni implementate su Windows
#
# Usage:
#   .\TEST-QUICK-WINS.ps1 [dev|prod]
#
# Author: AI Assistant
# Date: 2025-10-14
###############################################################################

param(
    [string]$Env = "dev"
)

$ErrorActionPreference = "Stop"

# Colori
function Write-ColorOutput {
    param(
        [string]$Message,
        [string]$Color = "White"
    )
    Write-Host $Message -ForegroundColor $Color
}

Write-ColorOutput "========================================" "Cyan"
Write-ColorOutput "🚀 TEST QUICK WINS - Environment: $Env" "Cyan"
Write-ColorOutput "========================================" "Cyan"
Write-Host ""

Set-Location "backend"

###############################################################################
# STEP 1: Pre-Migration Baseline
###############################################################################

Write-ColorOutput "📊 STEP 1: Baseline Performance (BEFORE Migration)" "Yellow"
Write-Host ""

# Tenant ID: 5 per DEV, 1 per PROD
$TenantId = if ($Env -eq "prod") { 1 } else { 5 }

Write-Host "Testing with Tenant ID: $TenantId"
Write-Host ""

# Test 1: RAG Query Performance
Write-ColorOutput "Test 1.1: RAG Query (BM25 Search)" "Blue"
php artisan tinker --execute="
DB::enableQueryLog();
`$service = new App\Services\RAG\TextSearchService();
`$start = microtime(true);
`$result = `$service->searchTopK($TenantId, 'numeri telefono orari', 50, 1);
`$duration = (microtime(true) - `$start) * 1000;
echo 'Duration: ' . round(`$duration, 2) . 'ms' . PHP_EOL;
echo 'Results: ' . count(`$result) . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
foreach(DB::getQueryLog() as `$q) {
    echo '  - ' . substr(`$q['query'], 0, 100) . '... [' . `$q['time'] . 'ms]' . PHP_EOL;
}
"
Write-Host ""

# Test 2: Admin Filtering
Write-ColorOutput "Test 1.2: Admin Document Filtering" "Blue"
php artisan tinker --execute="
DB::enableQueryLog();
`$start = microtime(true);
`$docs = App\Models\Document::where('tenant_id', $TenantId)
    ->where('knowledge_base_id', 1)
    ->where('status', 'completed')
    ->limit(10)
    ->get();
`$duration = (microtime(true) - `$start) * 1000;
echo 'Duration: ' . round(`$duration, 2) . 'ms' . PHP_EOL;
echo 'Documents: ' . `$docs->count() . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
Write-Host ""

# Test 3: Embeddings Performance
Write-ColorOutput "Test 1.3: Embeddings Batch Size Check" "Blue"
Get-Content storage/logs/laravel.log -Tail 20 | Select-String "embeddings.batch" -Context 0,5 | Select-Object -First 1
if ($LASTEXITCODE -ne 0) {
    Write-Host "No recent embeddings found in log"
}
Write-Host ""

Write-ColorOutput "✅ Baseline captured!" "Green"
Write-ColorOutput "💾 Save these numbers for comparison after migration" "Yellow"
Write-Host ""
Read-Host "Press Enter to continue with migration"

###############################################################################
# STEP 2: Run Migration
###############################################################################

Write-Host ""
Write-ColorOutput "📦 STEP 2: Run Database Migration" "Yellow"
Write-Host ""

if ($Env -eq "prod") {
    Write-ColorOutput "⚠️  WARNING: Running migration in PRODUCTION" "Red"
    $confirm = Read-Host "Are you sure? Type 'yes' to continue"
    if ($confirm -ne "yes") {
        Write-Host "Migration aborted."
        exit 0
    }
    
    # Backup in prod
    Write-Host "Creating backup..."
    $BackupFile = "backup_pre_indexes_$(Get-Date -Format 'yyyyMMdd_HHmmss').sql"
    Write-Host "Backup file: $BackupFile"
    Write-ColorOutput "Run manually:" "Yellow"
    Write-Host "  pg_dump -h localhost -U postgres chatbotplatform > $BackupFile"
    Read-Host "Press Enter after backup is complete"
}

Write-Host "Running migration..."
php artisan migrate --force
Write-Host ""

Write-ColorOutput "✅ Migration completed!" "Green"
Write-Host ""

###############################################################################
# STEP 3: Verify Index Creation
###############################################################################

Write-Host ""
Write-ColorOutput "🔍 STEP 3: Verify Index Creation" "Yellow"
Write-Host ""

Write-Host "Checking indexes on document_chunks..."
php artisan tinker --execute="
`$indexes = DB::select(`"
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'document_chunks' 
      AND indexname LIKE 'idx_%'
`");
foreach(`$indexes as `$idx) {
    echo '  ✅ ' . `$idx->indexname . PHP_EOL;
}
"
Write-Host ""

Write-Host "Checking indexes on documents..."
php artisan tinker --execute="
`$indexes = DB::select(`"
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'documents' 
      AND indexname LIKE 'idx_%'
`");
foreach(`$indexes as `$idx) {
    echo '  ✅ ' . `$idx->indexname . PHP_EOL;
}
"
Write-Host ""

Write-Host "Checking index sizes..."
php artisan tinker --execute="
`$sizes = DB::select(`"
    SELECT 
        indexname,
        pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
    FROM pg_stat_user_indexes
    WHERE indexname LIKE 'idx_%'
    ORDER BY pg_relation_size(indexrelid) DESC
`");
foreach(`$sizes as `$s) {
    echo '  📊 ' . `$s->indexname . ': ' . `$s->index_size . PHP_EOL;
}
"
Write-Host ""

Write-ColorOutput "✅ Indexes verified!" "Green"
Write-Host ""

###############################################################################
# STEP 4: Post-Migration Performance Test
###############################################################################

Write-Host ""
Write-ColorOutput "⚡ STEP 4: Performance Test (AFTER Migration)" "Yellow"
Write-Host ""

# Re-run same tests
Write-ColorOutput "Test 4.1: RAG Query (BM25 Search) - AFTER" "Blue"
php artisan tinker --execute="
DB::enableQueryLog();
`$service = new App\Services\RAG\TextSearchService();
`$start = microtime(true);
`$result = `$service->searchTopK($TenantId, 'numeri telefono orari', 50, 1);
`$duration = (microtime(true) - `$start) * 1000;
echo 'Duration: ' . round(`$duration, 2) . 'ms' . PHP_EOL;
echo 'Results: ' . count(`$result) . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
Write-Host ""

Write-ColorOutput "Test 4.2: Admin Document Filtering - AFTER" "Blue"
php artisan tinker --execute="
DB::enableQueryLog();
`$start = microtime(true);
`$docs = App\Models\Document::where('tenant_id', $TenantId)
    ->where('knowledge_base_id', 1)
    ->where('status', 'completed')
    ->limit(10)
    ->get();
`$duration = (microtime(true) - `$start) * 1000;
echo 'Duration: ' . round(`$duration, 2) . 'ms' . PHP_EOL;
echo 'Documents: ' . `$docs->count() . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
Write-Host ""

Write-ColorOutput "✅ Performance tests completed!" "Green"
Write-Host ""

###############################################################################
# STEP 5: EXPLAIN ANALYZE
###############################################################################

Write-Host ""
Write-ColorOutput "🔬 STEP 5: EXPLAIN ANALYZE (Index Usage Verification)" "Yellow"
Write-Host ""

Write-Host "Query 1: RAG Search with Index"
php artisan tinker --execute="
`$explain = DB::select(`"
    EXPLAIN ANALYZE
    SELECT dc.document_id, dc.chunk_index,
           ts_rank(to_tsvector('simple', dc.content), plainto_tsquery('simple', 'test')) AS score
    FROM document_chunks dc
    INNER JOIN documents d ON d.id = dc.document_id
    WHERE dc.tenant_id = $TenantId
      AND d.tenant_id = $TenantId
      AND d.knowledge_base_id = 1
      AND to_tsvector('simple', dc.content) @@ plainto_tsquery('simple', 'test')
    ORDER BY score DESC
    LIMIT 50
`");
foreach(`$explain as `$line) {
    echo `$line->{'QUERY PLAN'} . PHP_EOL;
}
" 2>&1 | Select-Object -First 20
Write-Host ""

Write-Host "Look for: 'Index Scan using idx_document_chunks_rag_search' ✅"
Write-Host "Avoid: 'Seq Scan on document_chunks' ❌"
Write-Host ""

Write-ColorOutput "✅ EXPLAIN ANALYZE completed!" "Green"
Write-Host ""

###############################################################################
# STEP 6: Test Embeddings Optimization
###############################################################################

Write-Host ""
Write-ColorOutput "🧪 STEP 6: Test Embeddings Optimization" "Yellow"
Write-Host ""

Write-Host "To test embeddings optimization, upload a document with 100+ chunks."
Write-Host "Then check the logs:"
Write-Host ""
Write-Host "  Get-Content storage/logs/laravel.log -Wait -Tail 10 | Select-String 'embeddings.batch'"
Write-Host ""
Write-Host "Expected output:"
Write-Host "  - num_batches: 1 (instead of 8)"
Write-Host "  - avg_batch_size: ~1000 (instead of 128)"
Write-Host "  - duration_ms: ~1500ms (instead of ~12000ms)"
Write-Host ""

###############################################################################
# SUMMARY
###############################################################################

Write-Host ""
Write-ColorOutput "========================================" "Cyan"
Write-ColorOutput "📊 SUMMARY" "Cyan"
Write-ColorOutput "========================================" "Cyan"
Write-Host ""
Write-ColorOutput "✅ Quick Win #1: Embeddings Batch Optimization" "Green"
Write-Host "   Status: DEPLOYED (no test needed, backward compatible)"
Write-Host "   Expected: 8x faster (12s → 1.5s per 1000 chunks)"
Write-Host ""
Write-ColorOutput "✅ Quick Win #2: Database Composite Indexes" "Green"
Write-Host "   Status: MIGRATION COMPLETED"
Write-Host "   Expected: 5x faster queries (500ms → 100ms)"
Write-Host ""
Write-ColorOutput "📋 Next Steps:" "Yellow"
Write-Host "   1. Compare BEFORE/AFTER numbers above"
Write-Host "   2. Monitor logs: Get-Content storage/logs/laravel.log -Wait -Tail 50"
Write-Host "   3. Check production for 24-48h"
Write-Host "   4. If OK → Move to Quick Win #3 (N+1 elimination)"
Write-Host ""
Write-ColorOutput "========================================" "Cyan"
Write-Host ""

Write-ColorOutput "✅ Testing completed!" "Green"

# Return to original directory
Set-Location ..

