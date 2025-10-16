<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;

echo "=== FUSION RANKING DEBUG ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

try {
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    
    $kbSearch = app(KbSearchService::class);
    
    // Enable DEBUG mode to get full fusion ranking
    $result = $kbSearch->retrieve($tenantId, $query, true);
    
    $debug = $result['debug'] ?? null;
    
    if (!$debug) {
        echo "❌ No debug info available" . PHP_EOL;
        exit(1);
    }
    
    echo "🔍 HYBRID CONFIG USED:" . PHP_EOL;
    $hybridConfig = $debug['hybrid_config'] ?? [];
    foreach ($hybridConfig as $key => $value) {
        echo "  {$key}: {$value}" . PHP_EOL;
    }
    echo PHP_EOL;
    
    // Check fused results
    $fusedTop = $debug['fused_top'] ?? [];
    
    if (empty($fusedTop)) {
        echo "❌ No fused results!" . PHP_EOL;
        exit(1);
    }
    
    echo "📊 FUSION TOP-20 RESULTS:" . PHP_EOL;
    echo str_repeat('=', 90) . PHP_EOL;
    echo sprintf("%-5s | %-10s | %-10s | %-15s | %s", "#", "Doc ID", "Chunk", "Score", "Title") . PHP_EOL;
    echo str_repeat('=', 90) . PHP_EOL;
    
    $doc4350Found = false;
    $doc4350Position = null;
    
    foreach ($fusedTop as $i => $hit) {
        $position = $i + 1;
        $docId = $hit['document_id'] ?? 'unknown';
        $chunkIndex = $hit['chunk_index'] ?? 'unknown';
        $score = $hit['final_score'] ?? $hit['score'] ?? 0;
        
        // Get document title
        $doc = \App\Models\Document::find($docId);
        $title = $doc ? substr($doc->title, 0, 40) : 'N/A';
        
        echo sprintf("%-5s | %-10s | %-10s | %-15s | %s", 
            $position, 
            $docId, 
            $chunkIndex, 
            round($score, 6),
            $title
        ) . PHP_EOL;
        
        if ($docId == 4350) {
            $doc4350Found = true;
            $doc4350Position = $position;
        }
    }
    
    echo str_repeat('=', 90) . PHP_EOL . PHP_EOL;
    
    // Final verdict
    if ($doc4350Found) {
        echo "✅ Document 4350 IS in fusion top-20 at position #{$doc4350Position}" . PHP_EOL;
        echo "   BUT it's being filtered out by MMR or context building!" . PHP_EOL;
    } else {
        echo "❌ Document 4350 NOT EVEN in fusion top-20!" . PHP_EOL;
        echo "   The problem is BEFORE MMR - in vector/BM25 search or fusion!" . PHP_EOL;
    }
    
    echo PHP_EOL;
    
    // Final citations
    $citations = $result['citations'] ?? [];
    echo "📋 FINAL CITATIONS (passed to LLM): " . count($citations) . " citations" . PHP_EOL;
    foreach ($citations as $i => $cit) {
        $docId = $cit['document_id'] ?? 'unknown';
        $pos = $i + 1;
        echo "  #{$pos}: Doc {$docId}" . PHP_EOL;
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

