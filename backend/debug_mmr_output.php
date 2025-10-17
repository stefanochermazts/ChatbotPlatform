<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

echo "=== DEBUG MMR OUTPUT ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

// Clear all caches
Cache::flush();

// Enable debug logging
Log::channel('single')->listen(function ($level, $message, $context) {
    if (str_contains($message, '[MMR]') || str_contains($message, 'mmr_selected_idx')) {
        echo "[LOG] {$message}" . PHP_EOL;
        if (!empty($context)) {
            echo json_encode($context, JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }
});

try {
    $kbSearch = app(KbSearchService::class);
    $result = $kbSearch->retrieve($tenantId, $query, true);
    
    $debug = $result['debug'] ?? [];
    
    // Check MMR selected indices
    $mmrSelectedIdx = $debug['mmr_selected_idx'] ?? [];
    
    echo PHP_EOL . "📊 MMR SELECTED INDICES:" . PHP_EOL;
    echo "Count: " . count($mmrSelectedIdx) . PHP_EOL;
    echo "Indices: " . implode(', ', $mmrSelectedIdx) . PHP_EOL;
    echo PHP_EOL;
    
    // Check fusion top
    $fusedTop = $debug['fused_top'] ?? [];
    
    if (!empty($fusedTop) && !empty($mmrSelectedIdx)) {
        echo "📋 MMR SELECTED DOCUMENTS:" . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        echo sprintf("%-10s | %-10s | %-10s | %s", "MMR Index", "Doc ID", "Chunk", "Score") . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL;
        
        foreach ($mmrSelectedIdx as $idx) {
            if (isset($fusedTop[$idx])) {
                $hit = $fusedTop[$idx];
                $docId = $hit['document_id'] ?? 'unknown';
                $chunkIndex = $hit['chunk_index'] ?? 'unknown';
                $score = $hit['final_score'] ?? $hit['score'] ?? 0;
                
                $marker = ($docId == 4350) ? ' ✅ DOC 4350!' : '';
                
                echo sprintf("%-10s | %-10s | %-10s | %.6f%s", 
                    $idx, 
                    $docId, 
                    $chunkIndex, 
                    $score,
                    $marker
                ) . PHP_EOL;
            }
        }
        echo str_repeat('=', 80) . PHP_EOL;
    }
    
    // Final citations
    $citations = $result['citations'] ?? [];
    echo PHP_EOL . "📋 FINAL CITATIONS: " . count($citations) . PHP_EOL;
    foreach ($citations as $i => $cit) {
        $docId = $cit['id'] ?? 'unknown';
        $title = substr($cit['title'] ?? 'N/A', 0, 40);
        echo "  #" . ($i+1) . ": Doc {$docId} - {$title}" . PHP_EOL;
    }
    
    // Verdict
    echo PHP_EOL;
    $doc4350InMMR = false;
    foreach ($mmrSelectedIdx as $idx) {
        if (isset($fusedTop[$idx]) && $fusedTop[$idx]['document_id'] == 4350) {
            $doc4350InMMR = true;
            break;
        }
    }
    
    if ($doc4350InMMR) {
        echo "✅ Doc 4350 IS selected by MMR" . PHP_EOL;
        
        $doc4350InCitations = false;
        foreach ($citations as $cit) {
            if (($cit['id'] ?? null) == 4350) {
                $doc4350InCitations = true;
                break;
            }
        }
        
        if ($doc4350InCitations) {
            echo "✅ Doc 4350 IS in final citations" . PHP_EOL;
            echo "   → Problem solved!" . PHP_EOL;
        } else {
            echo "❌ Doc 4350 NOT in final citations" . PHP_EOL;
            echo "   → Problem in citations building (lines 641-697)" . PHP_EOL;
        }
    } else {
        echo "❌ Doc 4350 NOT selected by MMR" . PHP_EOL;
        echo "   → MMR algorithm problem (check mmr_lambda, mmr_take)" . PHP_EOL;
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

