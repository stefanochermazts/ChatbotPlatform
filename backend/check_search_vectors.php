<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECK SEARCH_VECTOR INDEXING ===" . PHP_EOL . PHP_EOL;

$documentId = 4350;

echo "Document ID: {$documentId}" . PHP_EOL;
echo PHP_EOL;

try {
    // Check if chunks have search_vector populated
    $chunks = DB::select("
        SELECT 
            id,
            chunk_index,
            CASE 
                WHEN search_vector IS NOT NULL THEN 'YES'
                ELSE 'NO'
            END as has_search_vector,
            LENGTH(content) as content_length,
            LEFT(content, 100) as content_preview
        FROM document_chunks
        WHERE document_id = ?
        ORDER BY chunk_index
    ", [$documentId]);
    
    if (empty($chunks)) {
        echo "❌ NO CHUNKS FOUND for document {$documentId}!" . PHP_EOL;
        exit(1);
    }
    
    echo "Found " . count($chunks) . " chunks" . PHP_EOL;
    echo PHP_EOL;
    
    $withSearchVector = 0;
    $withoutSearchVector = 0;
    
    foreach ($chunks as $chunk) {
        echo "Chunk #{$chunk->chunk_index}:" . PHP_EOL;
        echo "  ID: {$chunk->id}" . PHP_EOL;
        echo "  Has search_vector: {$chunk->has_search_vector}" . PHP_EOL;
        echo "  Content length: {$chunk->content_length}" . PHP_EOL;
        echo "  Preview: {$chunk->content_preview}..." . PHP_EOL;
        echo PHP_EOL;
        
        if ($chunk->has_search_vector === 'YES') {
            $withSearchVector++;
        } else {
            $withoutSearchVector++;
        }
    }
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    echo "📊 SUMMARY:" . PHP_EOL;
    echo "  - Total chunks: " . count($chunks) . PHP_EOL;
    echo "  - With search_vector: {$withSearchVector}" . PHP_EOL;
    echo "  - Without search_vector: {$withoutSearchVector}" . PHP_EOL;
    echo PHP_EOL;
    
    if ($withoutSearchVector > 0) {
        echo "❌ PROBLEM IDENTIFIED: {$withoutSearchVector} chunks missing search_vector!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 ROOT CAUSE:" . PHP_EOL;
        echo "  - Chunks were created but NOT indexed for full-text search" . PHP_EOL;
        echo "  - BM25 search requires search_vector to be populated" . PHP_EOL;
        echo "  - This happens during ingestion in IngestUploadedDocumentJob" . PHP_EOL;
        echo PHP_EOL;
        echo "💡 SOLUTION:" . PHP_EOL;
        echo "  - search_vector is auto-populated by PostgreSQL trigger" . PHP_EOL;
        echo "  - OR manually by calling DB::statement(\"UPDATE document_chunks SET search_vector = to_tsvector('italian', content)...\")" . PHP_EOL;
        echo "  - Check if the trigger exists and is working" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  1. Verify PostgreSQL trigger for search_vector auto-population" . PHP_EOL;
        echo "  2. If trigger missing, create it or manually update search_vector" . PHP_EOL;
        echo "  3. Re-test BM25 search after fixing" . PHP_EOL;
        exit(1);
    } else {
        echo "✅ ALL CHUNKS HAVE search_vector!" . PHP_EOL;
        echo PHP_EOL;
        echo "🤔 MYSTERY:" . PHP_EOL;
        echo "  - search_vector is populated" . PHP_EOL;
        echo "  - BUT BM25 search returns no results" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 POSSIBLE ISSUES:" . PHP_EOL;
        echo "  1. search_vector uses wrong language config ('italian' vs 'english')" . PHP_EOL;
        echo "  2. Query normalization removes all keywords" . PHP_EOL;
        echo "  3. TextSearchService has a bug in the query logic" . PHP_EOL;
        echo "  4. KB filtering removes all results before return" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Manually test PostgreSQL tsvector query" . PHP_EOL;
        echo "  - Inspect TextSearchService::searchTopK() logic" . PHP_EOL;
        echo "  - Check if KB filtering is too aggressive" . PHP_EOL;
        exit(0);
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

