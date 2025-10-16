<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\TextSearchService;

echo "=== BM25 WITH EXPANDED QUERY ===" . PHP_EOL . PHP_EOL;

$expandedQuery = "telefono comando polizia locale municipale vigili urbani tel phone numero contatto";
$tenantId = 5;
$topK = 10;

echo "Expanded Query: \"{$expandedQuery}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo PHP_EOL;

try {
    $textSearch = app(TextSearchService::class);
    $results = $textSearch->searchTopK($tenantId, $expandedQuery, $topK, null);
    
    if (empty($results)) {
        echo "❌ NO RESULTS from BM25 even with expanded query!" . PHP_EOL;
        echo PHP_EOL;
        echo "Testing individual terms:" . PHP_EOL;
        
        $terms = ['tel', 'telefono', 'polizia', 'comando'];
        foreach ($terms as $term) {
            $termResults = $textSearch->searchTopK($tenantId, $term, 5, null);
            echo "  - '{$term}': " . count($termResults) . " results" . PHP_EOL;
            if (!empty($termResults)) {
                echo "    Top result: Doc " . $termResults[0]['document_id'] . ", Score " . round($termResults[0]['score'], 4) . PHP_EOL;
            }
        }
        exit(1);
    }
    
    echo "✅ FOUND " . count($results) . " RESULTS!" . PHP_EOL;
    echo PHP_EOL;
    
    $doc4350Found = false;
    
    foreach ($results as $i => $result) {
        $position = $i + 1;
        $docId = $result['document_id'];
        $chunkIndex = $result['chunk_index'];
        $score = $result['score'];
        
        echo "#{$position}: Doc {$docId}, Chunk {$chunkIndex}, Score " . round($score, 4) . PHP_EOL;
        
        if ($docId == 4350) {
            $doc4350Found = true;
        }
    }
    
    echo PHP_EOL;
    
    if ($doc4350Found) {
        echo "✅ Document 4350 FOUND with BM25!" . PHP_EOL;
    } else {
        echo "❌ Document 4350 NOT FOUND with BM25!" . PHP_EOL;
    }
    
    exit($doc4350Found ? 0 : 1);
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

