<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;

echo "=== COMPLETE RETRIEVAL FLOW DEBUG ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

echo "Query: \"{$query}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo PHP_EOL;

try {
    // Clear all caches
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    
    $kbSearch = app(KbSearchService::class);
    
    // Call retrieve with debug
    $result = $kbSearch->retrieve($tenantId, $query, true);
    
    $citations = $result['citations'] ?? [];
    $confidence = $result['confidence'] ?? 0;
    $debug = $result['debug'] ?? null;
    
    echo "📊 FINAL RESULT:" . PHP_EOL;
    echo "  - Citations count: " . count($citations) . PHP_EOL;
    echo "  - Confidence: " . round($confidence, 4) . PHP_EOL;
    echo PHP_EOL;
    
    if (empty($citations)) {
        echo "❌ NO CITATIONS!" . PHP_EOL;
        exit(1);
    }
    
    // Analyze citations
    echo "📋 FINAL CITATIONS:" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    
    $doc4350Found = false;
    
    foreach ($citations as $i => $citation) {
        $position = $i + 1;
        $docId = $citation['document_id'] ?? 'unknown';
        $chunkIndex = $citation['chunk_index'] ?? 'unknown';
        $title = $citation['title'] ?? 'N/A';
        $score = $citation['score'] ?? 0;
        $snippet = $citation['snippet'] ?? $citation['content'] ?? '';
        $snippetPreview = substr(str_replace(["\n", "\r"], ' ', $snippet), 0, 200);
        
        echo "#{$position}:" . PHP_EOL;
        echo "  Doc ID: {$docId}" . PHP_EOL;
        echo "  Title: {$title}" . PHP_EOL;
        echo "  Chunk: {$chunkIndex}" . PHP_EOL;
        echo "  Score: " . round($score, 4) . PHP_EOL;
        echo "  Snippet: {$snippetPreview}..." . PHP_EOL;
        echo PHP_EOL;
        
        if ($docId == 4350) {
            $doc4350Found = true;
            
            // Check content
            if (stripos($snippet, '06.95898223') !== false) {
                echo "  ✅ CONTAINS CORRECT PHONE: 06.95898223" . PHP_EOL;
            } else {
                echo "  ❌ DOES NOT CONTAIN CORRECT PHONE" . PHP_EOL;
            }
            echo PHP_EOL;
        }
    }
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    // Final verdict
    if ($doc4350Found) {
        echo "✅ SUCCESS: Document 4350 IS in final citations!" . PHP_EOL;
        echo "   The LLM should receive the correct context." . PHP_EOL;
        exit(0);
    } else {
        echo "❌ FAILURE: Document 4350 NOT in final citations!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 DEBUGGING WHY:" . PHP_EOL;
        
        // Check what documents are in final citations
        $finalDocIds = array_map(fn($c) => $c['document_id'] ?? 'unknown', $citations);
        echo "  - Final doc IDs: " . implode(', ', $finalDocIds) . PHP_EOL;
        
        // Suggest checking MMR or confidence filtering
        echo PHP_EOL;
        echo "💡 POSSIBLE CAUSES:" . PHP_EOL;
        echo "  1. MMR diversity filtering excluded doc 4350" . PHP_EOL;
        echo "  2. Score/confidence threshold too high" . PHP_EOL;
        echo "  3. Context building limited to top N citations" . PHP_EOL;
        echo "  4. Quality filtering removed doc 4350" . PHP_EOL;
        echo PHP_EOL;
        echo "📝 NEXT STEPS:" . PHP_EOL;
        echo "  - Increase mmr_take parameter" . PHP_EOL;
        echo "  - Lower confidence threshold" . PHP_EOL;
        echo "  - Check tenant RAG config" . PHP_EOL;
        
        exit(1);
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

