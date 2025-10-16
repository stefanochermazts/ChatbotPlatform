<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\TextSearchService;

echo "=== BM25 TEXT SEARCH TEST ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;
$topK = 10;

echo "Query: \"{$query}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo "Top K: {$topK}" . PHP_EOL;
echo PHP_EOL;

try {
    echo "🔍 Executing BM25 text search..." . PHP_EOL;
    
    $textSearch = app(TextSearchService::class);
    $results = $textSearch->searchTopK($tenantId, $query, $topK, null);
    
    if (empty($results)) {
        echo "⚠️  WARNING: No results returned from BM25 search!" . PHP_EOL;
        exit(1);
    }
    
    echo "✅ Search completed!" . PHP_EOL;
    echo "  - Results found: " . count($results) . PHP_EOL;
    echo PHP_EOL;
    
    echo "📊 RESULTS:" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    $doc4350Found = false;
    $doc4350Position = -1;
    $doc4350Score = 0;
    
    foreach ($results as $i => $result) {
        $position = $i + 1;
        $docId = $result['document_id'] ?? 'unknown';
        $chunkIndex = $result['chunk_index'] ?? 'unknown';
        $score = $result['score'] ?? 0;
        $contentPreview = isset($result['content']) ? substr($result['content'], 0, 200) : 'N/A';
        
        echo "Result #{$position}:" . PHP_EOL;
        echo "  Document ID: {$docId}" . PHP_EOL;
        echo "  Chunk Index: {$chunkIndex}" . PHP_EOL;
        echo "  BM25 Score: " . round($score, 4) . PHP_EOL;
        echo "  Content: " . str_replace(["\n", "\r"], ' ', $contentPreview) . "..." . PHP_EOL;
        echo PHP_EOL;
        
        if ($docId == 4350) {
            $doc4350Found = true;
            $doc4350Position = $position;
            $doc4350Score = $score;
        }
    }
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    echo "🎯 EVALUATION:" . PHP_EOL;
    echo PHP_EOL;
    
    if ($doc4350Found) {
        echo "✅ DOCUMENT 4350 FOUND VIA BM25!" . PHP_EOL;
        echo "  - Position: #{$doc4350Position}" . PHP_EOL;
        echo "  - BM25 Score: " . round($doc4350Score, 4) . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 DIAGNOSIS:" . PHP_EOL;
        echo "  - Vector Search: ❌ FAILED (doc 4350 not in top-10)" . PHP_EOL;
        echo "  - BM25 Text Search: ✅ SUCCESS (doc 4350 found!)" . PHP_EOL;
        echo PHP_EOL;
        echo "💡 ROOT CAUSE:" . PHP_EOL;
        echo "  The system uses HYBRID search (Vector + BM25 + RRF Fusion)" . PHP_EOL;
        echo "  BUT something is filtering out BM25 results before reaching the LLM!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 POSSIBLE ISSUES:" . PHP_EOL;
        echo "  1. KB Selection: Query routed to wrong KB (doesn't contain doc 4350)" . PHP_EOL;
        echo "  2. RRF Fusion: BM25 weight too low, vector search dominates" . PHP_EOL;
        echo "  3. Context Filtering: Doc 4350 filtered out after fusion" . PHP_EOL;
        echo "  4. Minimum confidence threshold: Final score too low" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - CRITICAL: Proceed to Step 3 - Inspect KB Selection Logic" . PHP_EOL;
        echo "  - Verify which KB is selected for this query" . PHP_EOL;
        echo "  - Check if doc 4350 belongs to the selected KB" . PHP_EOL;
    } else {
        echo "❌ DOCUMENT 4350 NOT FOUND EVEN WITH BM25!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 CRITICAL ISSUE:" . PHP_EOL;
        echo "  - Neither Vector Search nor BM25 Text Search find doc 4350" . PHP_EOL;
        echo "  - This indicates a FUNDAMENTAL problem with the content" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 POSSIBLE ROOT CAUSES:" . PHP_EOL;
        echo "  1. Chunk content doesn't contain keywords 'telefono', 'comando', 'polizia', 'locale'" . PHP_EOL;
        echo "  2. Text normalization removed important keywords" . PHP_EOL;
        echo "  3. Document was ingested incorrectly" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Inspect chunk 1 of doc 4350 to verify content" . PHP_EOL;
        echo "  - Verify text normalization didn't corrupt the content" . PHP_EOL;
        echo "  - Consider re-scraping and re-ingesting the document" . PHP_EOL;
    }
    
    exit($doc4350Found ? 0 : 1);
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

