<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;

echo "=== FULL DEBUG TRACE ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

try {
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    
    $kbSearch = app(KbSearchService::class);
    
    // Call with debug=true
    $result = $kbSearch->retrieve($tenantId, $query, true);
    
    $citations = $result['citations'] ?? [];
    $confidence = $result['confidence'] ?? 0;
    $debug = $result['debug'] ?? null;
    
    echo "📊 RESULT SUMMARY:" . PHP_EOL;
    echo "  Citations: " . count($citations) . PHP_EOL;
    echo "  Confidence: " . round($confidence, 4) . PHP_EOL;
    echo PHP_EOL;
    
    // Final citations
    echo "📋 FINAL CITATIONS:" . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;
    foreach ($citations as $i => $cit) {
        $docId = $cit['document_id'] ?? $cit['id'] ?? 'unknown';
        $chunkIdx = $cit['chunk_index'] ?? 'unknown';
        $title = substr($cit['title'] ?? 'N/A', 0, 50);
        $score = $cit['score'] ?? 0;
        
        echo sprintf("#%-2s | Doc %-6s | Chunk %-3s | Score: %.4f | %s", 
            $i+1, 
            $docId, 
            $chunkIdx, 
            $score,
            $title
        ) . PHP_EOL;
        
        // Show snippet
        $snippet = $cit['snippet'] ?? $cit['chunk_text'] ?? '';
        if ($snippet) {
            echo "     Snippet: " . substr($snippet, 0, 150) . "..." . PHP_EOL;
        }
    }
    echo str_repeat('=', 100) . PHP_EOL . PHP_EOL;
    
    // Check if doc 4350 is in citations
    $doc4350Found = false;
    foreach ($citations as $cit) {
        $docId = $cit['document_id'] ?? $cit['id'] ?? null;
        if ($docId == 4350) {
            $doc4350Found = true;
            break;
        }
    }
    
    if ($doc4350Found) {
        echo "✅ Document 4350 IS in final citations!" . PHP_EOL;
    } else {
        echo "❌ Document 4350 NOT in final citations!" . PHP_EOL;
    }
    
    echo PHP_EOL;
    
    // Debug info
    if ($debug) {
        echo "🔍 DEBUG INFO:" . PHP_EOL;
        echo str_repeat('=', 100) . PHP_EOL;
        
        // Hybrid config
        if (isset($debug['hybrid_config'])) {
            echo "HYBRID CONFIG:" . PHP_EOL;
            foreach ($debug['hybrid_config'] as $k => $v) {
                echo "  {$k}: {$v}" . PHP_EOL;
            }
            echo PHP_EOL;
        }
        
        // Fused top
        if (isset($debug['fused_top'])) {
            echo "FUSED TOP-10 (after RRF fusion):" . PHP_EOL;
            foreach (array_slice($debug['fused_top'], 0, 10) as $i => $hit) {
                $docId = $hit['document_id'] ?? 'unknown';
                $chunkIdx = $hit['chunk_index'] ?? 'unknown';
                $score = $hit['final_score'] ?? $hit['score'] ?? 0;
                echo sprintf("  #%-2s | Doc %-6s | Chunk %-3s | Score: %.6f", 
                    $i+1, $docId, $chunkIdx, $score
                ) . PHP_EOL;
            }
            echo PHP_EOL;
        }
        
        // MMR selected
        if (isset($debug['mmr_selected_idx'])) {
            echo "MMR SELECTED INDICES: " . implode(', ', $debug['mmr_selected_idx']) . PHP_EOL;
            echo "MMR selected count: " . count($debug['mmr_selected_idx']) . PHP_EOL;
            echo PHP_EOL;
        }
        
        // Reranked top
        if (isset($debug['reranked_top'])) {
            echo "RERANKED TOP-10 (after reranker):" . PHP_EOL;
            foreach (array_slice($debug['reranked_top'], 0, 10) as $i => $hit) {
                $docId = $hit['document_id'] ?? 'unknown';
                $chunkIdx = $hit['chunk_index'] ?? 'unknown';
                $score = $hit['final_score'] ?? $hit['score'] ?? 0;
                echo sprintf("  #%-2s | Doc %-6s | Chunk %-3s | Score: %.6f", 
                    $i+1, $docId, $chunkIdx, $score
                ) . PHP_EOL;
            }
            echo PHP_EOL;
        }
        
        // Full debug JSON (truncated)
        echo "FULL DEBUG JSON (first 2000 chars):" . PHP_EOL;
        echo substr(json_encode($debug, JSON_PRETTY_PRINT), 0, 2000) . PHP_EOL;
        echo "..." . PHP_EOL;
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo "Trace: " . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

