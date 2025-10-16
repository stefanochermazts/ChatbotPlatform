<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Log;

echo "=== SIMULATE WIDGET/RAG TESTER FLOW ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

echo "Query: \"{$query}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo PHP_EOL;

try {
    // Clear Laravel cache first
    echo "🔄 Clearing Laravel cache..." . PHP_EOL;
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    echo "✅ Cache cleared" . PHP_EOL;
    echo PHP_EOL;
    
    // Initialize KbSearchService (same as widget/RAG tester)
    echo "🔍 Initializing KbSearchService..." . PHP_EOL;
    $kbSearch = app(KbSearchService::class);
    
    // Enable debug mode to see logs
    echo "📊 Executing retrieve() with debug=true..." . PHP_EOL;
    echo PHP_EOL;
    
    $startTime = microtime(true);
    $result = $kbSearch->retrieve($tenantId, $query, true); // debug=true
    $elapsed = microtime(true) - $startTime;
    
    echo "✅ Retrieve completed in " . round($elapsed * 1000, 2) . "ms" . PHP_EOL;
    echo PHP_EOL;
    
    // Analyze results
    $citations = $result['citations'] ?? [];
    $confidence = $result['confidence'] ?? 0;
    $debug = $result['debug'] ?? null;
    
    echo "📊 RESULTS:" . PHP_EOL;
    echo "  - Citations found: " . count($citations) . PHP_EOL;
    echo "  - Confidence: " . round($confidence, 4) . PHP_EOL;
    echo PHP_EOL;
    
    if (empty($citations)) {
        echo "❌ NO CITATIONS FOUND!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 DEBUG INFO:" . PHP_EOL;
        if ($debug) {
            echo "  - Profiling: " . json_encode($debug['breakdown'] ?? [], JSON_PRETTY_PRINT) . PHP_EOL;
        }
        echo PHP_EOL;
        echo "💡 POSSIBLE ISSUES:" . PHP_EOL;
        echo "  1. Synonym expansion not working (check getSynonymsMap())" . PHP_EOL;
        echo "  2. BM25 search still failing" . PHP_EOL;
        echo "  3. Vector search threshold too high" . PHP_EOL;
        echo "  4. KB selection filtering out results" . PHP_EOL;
        echo "  5. Cache not cleared properly" . PHP_EOL;
        exit(1);
    }
    
    // Check if doc 4350 is in results
    $doc4350Found = false;
    $doc4350Position = -1;
    
    echo "📋 TOP 10 CITATIONS:" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    
    foreach (array_slice($citations, 0, 10) as $i => $citation) {
        $position = $i + 1;
        $docId = $citation['document_id'] ?? 'unknown';
        $chunkIndex = $citation['chunk_index'] ?? 'unknown';
        $score = $citation['score'] ?? 0;
        $snippet = $citation['snippet'] ?? $citation['content'] ?? '';
        $snippetPreview = substr(str_replace(["\n", "\r"], ' ', $snippet), 0, 150);
        
        echo "#{$position}: Doc {$docId}, Chunk {$chunkIndex}, Score " . round($score, 4) . PHP_EOL;
        echo "    " . $snippetPreview . "..." . PHP_EOL;
        echo PHP_EOL;
        
        if ($docId == 4350) {
            $doc4350Found = true;
            $doc4350Position = $position;
        }
    }
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    // Final evaluation
    if ($doc4350Found) {
        echo "✅ DOCUMENT 4350 FOUND!" . PHP_EOL;
        echo "  - Position: #{$doc4350Position}" . PHP_EOL;
        echo PHP_EOL;
        echo "🎯 SUCCESS: Synonym expansion is working!" . PHP_EOL;
        echo "  - Query was expanded to include 'tel'" . PHP_EOL;
        echo "  - BM25 search found the chunk" . PHP_EOL;
        echo "  - Hybrid fusion ranked it high enough" . PHP_EOL;
        echo PHP_EOL;
        echo "💡 If RAG Tester/Widget still fail, check:" . PHP_EOL;
        echo "  1. Server restart required (Apache/PHP-FPM)" . PHP_EOL;
        echo "  2. Browser cache clearing" . PHP_EOL;
        echo "  3. Redis cache (if used)" . PHP_EOL;
        exit(0);
    } else {
        echo "❌ DOCUMENT 4350 NOT FOUND IN RESULTS!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 DIAGNOSIS:" . PHP_EOL;
        echo "  - Synonym expansion may not be working as expected" . PHP_EOL;
        echo "  - Need to investigate further" . PHP_EOL;
        echo PHP_EOL;
        
        // Check what query was actually used
        echo "🔎 Checking logs for query expansion..." . PHP_EOL;
        echo "  (Look for '[RAG] Query normalized and expanded' in Laravel logs)" . PHP_EOL;
        
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

