<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\TextSearchService;

echo "=== TEST BM25 FOR DOC 4350 ===" . PHP_EOL . PHP_EOL;

$tenantId = 5;
$query = "telefono comando polizia locale";

try {
    $bm25 = app(TextSearchService::class);
    
    echo "Query: '{$query}'" . PHP_EOL;
    echo "Tenant: {$tenantId}" . PHP_EOL;
    echo PHP_EOL;
    
    // Test con query originale
    echo "1. BM25 search (query originale)..." . PHP_EOL;
    $results = $bm25->searchTopK($tenantId, $query, 20);
    
    echo "   Risultati: " . count($results) . PHP_EOL;
    
    if (count($results) > 0) {
        echo PHP_EOL;
        echo "   Top 10:" . PHP_EOL;
        foreach (array_slice($results, 0, 10) as $i => $hit) {
            $docId = $hit['document_id'] ?? 'unknown';
            $chunkIdx = $hit['chunk_index'] ?? 'unknown';
            echo "     #" . ($i+1) . ": Doc {$docId}, chunk {$chunkIdx}" . PHP_EOL;
        }
        
        // Check doc 4350
        $doc4350Found = false;
        $doc4350Position = null;
        foreach ($results as $i => $hit) {
            if (($hit['document_id'] ?? 0) == 4350) {
                $doc4350Found = true;
                $doc4350Position = $i + 1;
                break;
            }
        }
        
        echo PHP_EOL;
        if ($doc4350Found) {
            echo "   ✅ Doc 4350 trovato in posizione #{$doc4350Position}!" . PHP_EOL;
        } else {
            echo "   ❌ Doc 4350 NON trovato nei top-20!" . PHP_EOL;
        }
    } else {
        echo "   ❌ Nessun risultato!" . PHP_EOL;
    }
    
    echo PHP_EOL;
    
    // Test con query espansa (con sinonimi)
    echo "2. BM25 search (query espansa con sinonimi)..." . PHP_EOL;
    $expandedQuery = "telefono tel phone comando polizia locale";
    echo "   Query espansa: '{$expandedQuery}'" . PHP_EOL;
    
    $resultsExpanded = $bm25->searchTopK($tenantId, $expandedQuery, 20);
    
    echo "   Risultati: " . count($resultsExpanded) . PHP_EOL;
    
    if (count($resultsExpanded) > 0) {
        echo PHP_EOL;
        echo "   Top 10:" . PHP_EOL;
        foreach (array_slice($resultsExpanded, 0, 10) as $i => $hit) {
            $docId = $hit['document_id'] ?? 'unknown';
            $chunkIdx = $hit['chunk_index'] ?? 'unknown';
            echo "     #" . ($i+1) . ": Doc {$docId}, chunk {$chunkIdx}" . PHP_EOL;
        }
        
        // Check doc 4350
        $doc4350Found = false;
        $doc4350Position = null;
        foreach ($resultsExpanded as $i => $hit) {
            if (($hit['document_id'] ?? 0) == 4350) {
                $doc4350Found = true;
                $doc4350Position = $i + 1;
                $doc4350Chunk = $hit['chunk_index'] ?? 'unknown';
                break;
            }
        }
        
        echo PHP_EOL;
        if ($doc4350Found) {
            echo "   ✅ Doc 4350 (chunk #{$doc4350Chunk}) trovato in posizione #{$doc4350Position}!" . PHP_EOL;
        } else {
            echo "   ❌ Doc 4350 NON trovato nei top-20!" . PHP_EOL;
        }
    } else {
        echo "   ❌ Nessun risultato!" . PHP_EOL;
    }
    
    echo PHP_EOL;
    echo "✅ Test completato!" . PHP_EOL;
    
} catch (\Throwable $e) {
    echo PHP_EOL;
    echo "❌ ERRORE: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

