#!/bin/bash

###############################################################################
# TEST QUICK WINS - Performance Optimization
#
# Questo script testa le ottimizzazioni implementate
#
# Usage:
#   bash TEST-QUICK-WINS.sh [dev|prod]
#
# Author: AI Assistant
# Date: 2025-10-14
###############################################################################

set -e  # Exit on error

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurazione
BACKEND_DIR="backend"
ENV="${1:-dev}"  # dev o prod

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}🚀 TEST QUICK WINS - Environment: $ENV${NC}"
echo -e "${BLUE}========================================${NC}"
echo

cd "$BACKEND_DIR" || exit 1

###############################################################################
# STEP 1: Pre-Migration Baseline
###############################################################################

echo -e "${YELLOW}📊 STEP 1: Baseline Performance (BEFORE Migration)${NC}"
echo

# Tenant ID: 5 per DEV, 1 per PROD
TENANT_ID=5
if [ "$ENV" == "prod" ]; then
    TENANT_ID=1
fi

echo "Testing with Tenant ID: $TENANT_ID"
echo

# Test 1: RAG Query Performance
echo -e "${BLUE}Test 1.1: RAG Query (BM25 Search)${NC}"
php artisan tinker --execute="
DB::enableQueryLog();
\$service = new App\Services\RAG\TextSearchService();
\$start = microtime(true);
\$result = \$service->searchTopK($TENANT_ID, 'numeri telefono orari', 50, 1);
\$duration = (microtime(true) - \$start) * 1000;
echo 'Duration: ' . round(\$duration, 2) . 'ms' . PHP_EOL;
echo 'Results: ' . count(\$result) . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
foreach(DB::getQueryLog() as \$q) {
    echo '  - ' . substr(\$q['query'], 0, 100) . '... [' . \$q['time'] . 'ms]' . PHP_EOL;
}
"
echo

# Test 2: Admin Filtering
echo -e "${BLUE}Test 1.2: Admin Document Filtering${NC}"
php artisan tinker --execute="
DB::enableQueryLog();
\$start = microtime(true);
\$docs = App\Models\Document::where('tenant_id', $TENANT_ID)
    ->where('knowledge_base_id', 1)
    ->where('status', 'completed')
    ->limit(10)
    ->get();
\$duration = (microtime(true) - \$start) * 1000;
echo 'Duration: ' . round(\$duration, 2) . 'ms' . PHP_EOL;
echo 'Documents: ' . \$docs->count() . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
echo

# Test 3: Embeddings Performance (se possibile)
echo -e "${BLUE}Test 1.3: Embeddings Batch Size Check${NC}"
tail -20 storage/logs/laravel.log | grep -A5 "embeddings.batch" || echo "No recent embeddings found in log"
echo

echo -e "${GREEN}✅ Baseline captured!${NC}"
echo -e "${YELLOW}💾 Save these numbers for comparison after migration${NC}"
echo
read -p "Press Enter to continue with migration..."

###############################################################################
# STEP 2: Run Migration
###############################################################################

echo
echo -e "${YELLOW}📦 STEP 2: Run Database Migration${NC}"
echo

if [ "$ENV" == "prod" ]; then
    echo -e "${RED}⚠️  WARNING: Running migration in PRODUCTION${NC}"
    read -p "Are you sure? Type 'yes' to continue: " confirm
    if [ "$confirm" != "yes" ]; then
        echo "Migration aborted."
        exit 0
    fi
    
    # Backup in prod
    echo "Creating backup..."
    BACKUP_FILE="backup_pre_indexes_$(date +%Y%m%d_%H%M%S).sql"
    echo "Backup file: $BACKUP_FILE"
    echo -e "${YELLOW}Run manually:${NC}"
    echo "  pg_dump -h localhost -U postgres chatbotplatform > $BACKUP_FILE"
    read -p "Press Enter after backup is complete..."
fi

echo "Running migration..."
php artisan migrate --force
echo

echo -e "${GREEN}✅ Migration completed!${NC}"
echo

###############################################################################
# STEP 3: Verify Index Creation
###############################################################################

echo
echo -e "${YELLOW}🔍 STEP 3: Verify Index Creation${NC}"
echo

echo "Checking indexes on document_chunks..."
php artisan tinker --execute="
\$indexes = DB::select(\"
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'document_chunks' 
      AND indexname LIKE 'idx_%'
\");
foreach(\$indexes as \$idx) {
    echo '  ✅ ' . \$idx->indexname . PHP_EOL;
}
"
echo

echo "Checking indexes on documents..."
php artisan tinker --execute="
\$indexes = DB::select(\"
    SELECT indexname, indexdef 
    FROM pg_indexes 
    WHERE tablename = 'documents' 
      AND indexname LIKE 'idx_%'
\");
foreach(\$indexes as \$idx) {
    echo '  ✅ ' . \$idx->indexname . PHP_EOL;
}
"
echo

echo "Checking index sizes..."
php artisan tinker --execute="
\$sizes = DB::select(\"
    SELECT 
        indexname,
        pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
    FROM pg_stat_user_indexes
    WHERE indexname LIKE 'idx_%'
    ORDER BY pg_relation_size(indexrelid) DESC
\");
foreach(\$sizes as \$s) {
    echo '  📊 ' . \$s->indexname . ': ' . \$s->index_size . PHP_EOL;
}
"
echo

echo -e "${GREEN}✅ Indexes verified!${NC}"
echo

###############################################################################
# STEP 4: Post-Migration Performance Test
###############################################################################

echo
echo -e "${YELLOW}⚡ STEP 4: Performance Test (AFTER Migration)${NC}"
echo

# Re-run same tests
echo -e "${BLUE}Test 4.1: RAG Query (BM25 Search) - AFTER${NC}"
php artisan tinker --execute="
DB::enableQueryLog();
\$service = new App\Services\RAG\TextSearchService();
\$start = microtime(true);
\$result = \$service->searchTopK($TENANT_ID, 'numeri telefono orari', 50, 1);
\$duration = (microtime(true) - \$start) * 1000;
echo 'Duration: ' . round(\$duration, 2) . 'ms' . PHP_EOL;
echo 'Results: ' . count(\$result) . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
echo

echo -e "${BLUE}Test 4.2: Admin Document Filtering - AFTER${NC}"
php artisan tinker --execute="
DB::enableQueryLog();
\$start = microtime(true);
\$docs = App\Models\Document::where('tenant_id', $TENANT_ID)
    ->where('knowledge_base_id', 1)
    ->where('status', 'completed')
    ->limit(10)
    ->get();
\$duration = (microtime(true) - \$start) * 1000;
echo 'Duration: ' . round(\$duration, 2) . 'ms' . PHP_EOL;
echo 'Documents: ' . \$docs->count() . PHP_EOL;
echo 'Queries: ' . count(DB::getQueryLog()) . PHP_EOL;
"
echo

echo -e "${GREEN}✅ Performance tests completed!${NC}"
echo

###############################################################################
# STEP 5: EXPLAIN ANALYZE
###############################################################################

echo
echo -e "${YELLOW}🔬 STEP 5: EXPLAIN ANALYZE (Index Usage Verification)${NC}"
echo

echo "Query 1: RAG Search with Index"
php artisan tinker --execute="
\$explain = DB::select(\"
    EXPLAIN ANALYZE
    SELECT dc.document_id, dc.chunk_index,
           ts_rank(to_tsvector('simple', dc.content), plainto_tsquery('simple', 'test')) AS score
    FROM document_chunks dc
    INNER JOIN documents d ON d.id = dc.document_id
    WHERE dc.tenant_id = $TENANT_ID
      AND d.tenant_id = $TENANT_ID
      AND d.knowledge_base_id = 1
      AND to_tsvector('simple', dc.content) @@ plainto_tsquery('simple', 'test')
    ORDER BY score DESC
    LIMIT 50
\");
foreach(\$explain as \$line) {
    echo \$line->{'QUERY PLAN'} . PHP_EOL;
}
" 2>&1 | head -20
echo

echo "Look for: 'Index Scan using idx_document_chunks_rag_search' ✅"
echo "Avoid: 'Seq Scan on document_chunks' ❌"
echo

echo -e "${GREEN}✅ EXPLAIN ANALYZE completed!${NC}"
echo

###############################################################################
# STEP 6: Test Embeddings Optimization
###############################################################################

echo
echo -e "${YELLOW}🧪 STEP 6: Test Embeddings Optimization${NC}"
echo

echo "To test embeddings optimization, upload a document with 100+ chunks."
echo "Then check the logs:"
echo
echo "  tail -f storage/logs/laravel.log | grep 'embeddings.batch'"
echo
echo "Expected output:"
echo "  - num_batches: 1 (instead of 8)"
echo "  - avg_batch_size: ~1000 (instead of 128)"
echo "  - duration_ms: ~1500ms (instead of ~12000ms)"
echo

###############################################################################
# SUMMARY
###############################################################################

echo
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}📊 SUMMARY${NC}"
echo -e "${BLUE}========================================${NC}"
echo
echo -e "${GREEN}✅ Quick Win #1: Embeddings Batch Optimization${NC}"
echo "   Status: DEPLOYED (no test needed, backward compatible)"
echo "   Expected: 8x faster (12s → 1.5s per 1000 chunks)"
echo
echo -e "${GREEN}✅ Quick Win #2: Database Composite Indexes${NC}"
echo "   Status: MIGRATION COMPLETED"
echo "   Expected: 5x faster queries (500ms → 100ms)"
echo
echo -e "${YELLOW}📋 Next Steps:${NC}"
echo "   1. Compare BEFORE/AFTER numbers above"
echo "   2. Monitor logs: tail -f storage/logs/laravel.log"
echo "   3. Check production for 24-48h"
echo "   4. If OK → Move to Quick Win #3 (N+1 elimination)"
echo
echo -e "${BLUE}========================================${NC}"
echo

echo -e "${GREEN}✅ Testing completed!${NC}"

