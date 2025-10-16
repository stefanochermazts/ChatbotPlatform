<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LLM\OpenAIEmbeddingsService;
use App\Services\RAG\MilvusClient;

echo "=== STEP 2: MANUAL SIMILARITY SEARCH ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;
$topK = 10;

echo "Query: \"{$query}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo "Top K: {$topK}" . PHP_EOL;
echo PHP_EOL;

try {
    // Step 2.1: Generate query embedding
    echo "📊 STEP 2.1: Generating query embedding with OpenAI..." . PHP_EOL;
    
    $embeddingsService = app(OpenAIEmbeddingsService::class);
    $embeddings = $embeddingsService->embedTexts([$query]);
    
    if (empty($embeddings) || empty($embeddings[0])) {
        echo "❌ ERROR: Failed to generate embedding!" . PHP_EOL;
        exit(1);
    }
    
    $queryEmbedding = $embeddings[0];
    $embeddingDim = count($queryEmbedding);
    
    echo "✅ Embedding generated successfully!" . PHP_EOL;
    echo "  - Embedding dimensions: {$embeddingDim}" . PHP_EOL;
    echo "  - First 5 values: " . implode(', ', array_map(fn($v) => round($v, 4), array_slice($queryEmbedding, 0, 5))) . PHP_EOL;
    echo PHP_EOL;
    
    // Step 2.2: Search Milvus with embedding
    echo "🔍 STEP 2.2: Searching Milvus with query embedding..." . PHP_EOL;
    
    $milvus = app(MilvusClient::class);
    $results = $milvus->searchTopKWithEmbedding($tenantId, $queryEmbedding, $topK);
    
    if (empty($results)) {
        echo "⚠️  WARNING: No results returned from Milvus!" . PHP_EOL;
        echo "  This could mean:" . PHP_EOL;
        echo "  - No vectors for tenant {$tenantId}" . PHP_EOL;
        echo "  - All similarity scores below threshold" . PHP_EOL;
        echo "  - Milvus connection issue" . PHP_EOL;
        exit(1);
    }
    
    echo "✅ Search completed!" . PHP_EOL;
    echo "  - Results found: " . count($results) . PHP_EOL;
    echo PHP_EOL;
    
    // Step 2.3: Analyze results
    echo "📊 STEP 2.3: RESULTS ANALYSIS" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    $doc4350Found = false;
    $doc4350Position = -1;
    $doc4350Score = 0;
    $doc4350ChunkIndex = -1;
    
    foreach ($results as $i => $result) {
        $position = $i + 1;
        $docId = $result['document_id'] ?? 'unknown';
        $chunkIndex = $result['chunk_index'] ?? 'unknown';
        $score = $result['score'] ?? 0;
        $contentPreview = isset($result['content']) ? substr($result['content'], 0, 150) : 'N/A';
        
        echo "Result #{$position}:" . PHP_EOL;
        echo "  Document ID: {$docId}" . PHP_EOL;
        echo "  Chunk Index: {$chunkIndex}" . PHP_EOL;
        echo "  Score: " . round($score, 4) . PHP_EOL;
        echo "  Content Preview: " . str_replace(["\n", "\r"], ' ', $contentPreview) . "..." . PHP_EOL;
        echo PHP_EOL;
        
        // Check if doc 4350 found
        if ($docId == 4350) {
            $doc4350Found = true;
            $doc4350Position = $position;
            $doc4350Score = $score;
            $doc4350ChunkIndex = $chunkIndex;
        }
    }
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    // Step 2.4: Evaluation
    echo "🎯 STEP 2.4: EVALUATION" . PHP_EOL;
    echo PHP_EOL;
    
    if ($doc4350Found) {
        echo "✅ DOCUMENT 4350 FOUND!" . PHP_EOL;
        echo "  - Position: #{$doc4350Position} (out of {$topK})" . PHP_EOL;
        echo "  - Chunk Index: {$doc4350ChunkIndex}" . PHP_EOL;
        echo "  - Similarity Score: " . round($doc4350Score, 4) . PHP_EOL;
        echo PHP_EOL;
        
        // Evaluate score
        if ($doc4350Score >= 0.8) {
            echo "  🟢 EXCELLENT MATCH (score >= 0.8)" . PHP_EOL;
            echo "     The semantic match is very strong!" . PHP_EOL;
            echo PHP_EOL;
            echo "🔧 ROOT CAUSE IDENTIFIED:" . PHP_EOL;
            echo "  - Embeddings: ✅ Present" . PHP_EOL;
            echo "  - Similarity: ✅ High score ({$doc4350Score})" . PHP_EOL;
            echo "  - Problem: ❌ KB Selection or Context Filtering" . PHP_EOL;
            echo PHP_EOL;
            echo "📝 NEXT STEPS:" . PHP_EOL;
            echo "  - Proceed to Step 3: Inspect KB Selection Logic" . PHP_EOL;
            echo "  - Verify which KB is selected for this query" . PHP_EOL;
            echo "  - Check if doc 4350 belongs to the selected KB" . PHP_EOL;
        } elseif ($doc4350Score >= 0.6) {
            echo "  🟡 GOOD MATCH (0.6 <= score < 0.8)" . PHP_EOL;
            echo "     The semantic match is decent but could be improved" . PHP_EOL;
            echo PHP_EOL;
            echo "🔧 POSSIBLE ROOT CAUSES:" . PHP_EOL;
            echo "  1. Similarity threshold too high (check config/rag.php)" . PHP_EOL;
            echo "  2. KB Selection filtering out this document" . PHP_EOL;
            echo "  3. Context scoring reducing the final score" . PHP_EOL;
            echo PHP_EOL;
            echo "📝 NEXT STEPS:" . PHP_EOL;
            echo "  - Step 3: Check KB Selection" . PHP_EOL;
            echo "  - Step 5: Consider lowering similarity threshold to ~0.55" . PHP_EOL;
        } else {
            echo "  🔴 LOW MATCH (score < 0.6)" . PHP_EOL;
            echo "     The semantic match is weak - query doesn't match content well" . PHP_EOL;
            echo PHP_EOL;
            echo "🔧 ROOT CAUSE IDENTIFIED:" . PHP_EOL;
            echo "  - Semantic mismatch between query and content" . PHP_EOL;
            echo "  - Query: \"{$query}\"" . PHP_EOL;
            echo "  - Content probably uses different vocabulary" . PHP_EOL;
            echo PHP_EOL;
            echo "💡 POSSIBLE SOLUTIONS:" . PHP_EOL;
            echo "  1. Use HyDE (Hypothetical Document Embeddings) for query expansion" . PHP_EOL;
            echo "  2. Add BM25 text search to complement vector search" . PHP_EOL;
            echo "  3. Extract structured fields (phone) during ingestion for exact matching" . PHP_EOL;
            echo "  4. Lower similarity threshold significantly (to ~0.4-0.5)" . PHP_EOL;
        }
    } else {
        echo "❌ DOCUMENT 4350 NOT FOUND IN TOP-{$topK} RESULTS!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔧 ROOT CAUSE IDENTIFIED:" . PHP_EOL;
        echo "  - Severe semantic mismatch" . PHP_EOL;
        echo "  - Query embedding doesn't match content embedding at all" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 TOP RESULT ANALYSIS:" . PHP_EOL;
        if (!empty($results)) {
            $topResult = $results[0];
            $topDocId = $topResult['document_id'] ?? 'unknown';
            $topScore = $topResult['score'] ?? 0;
            echo "  - Top result: Document {$topDocId} with score " . round($topScore, 4) . PHP_EOL;
            echo "  - This document is being prioritized over doc 4350" . PHP_EOL;
        }
        echo PHP_EOL;
        echo "💡 RECOMMENDED SOLUTIONS:" . PHP_EOL;
        echo "  1. Enable HyDE (Hypothetical Document Embeddings)" . PHP_EOL;
        echo "  2. Increase BM25 weight in RRF fusion (text search helps here)" . PHP_EOL;
        echo "  3. Add intent detection: 'phone' queries should check structured fields first" . PHP_EOL;
        echo "  4. Re-chunk document with more context around phone numbers" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Examine top results to understand why they rank higher" . PHP_EOL;
        echo "  - Check if BM25 text search finds doc 4350 (it should!)" . PHP_EOL;
        echo "  - Consider implementing intent-based field extraction" . PHP_EOL;
    }
    
    exit($doc4350Found ? 0 : 1);
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

