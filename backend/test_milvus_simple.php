<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\MilvusClient;
use Illuminate\Support\Facades\Log;

echo "=== TEST MILVUS SIMPLE ===" . PHP_EOL . PHP_EOL;

$tenantId = 5;
$docId = 4350;

try {
    $milvus = app(MilvusClient::class);
    
    echo "1. Verifica collection esistente..." . PHP_EOL;
    $collection = config('rag.milvus.collection', 'kb_chunks_v1');
    echo "   Collection: {$collection}" . PHP_EOL;
    
    echo PHP_EOL;
    echo "2. Count total vectors per tenant..." . PHP_EOL;
    
    // Conta i vettori del tenant
    $count = $milvus->countByTenant($tenantId);
    
    echo "   Total vectors: {$count}" . PHP_EOL;
    
    echo PHP_EOL;
    echo "3. Test similarity search (query semplice)..." . PHP_EOL;
    
    // Prova una ricerca vettoriale base
    $testQuery = "telefono comando polizia locale";
    echo "   Query: '{$testQuery}'" . PHP_EOL;
    
    // Genera embedding per la query
    $embedService = app(\App\Services\LLM\OpenAIEmbeddingsService::class);
    $embeddings = $embedService->embedTexts([$testQuery]);
    
    if (empty($embeddings) || empty($embeddings[0])) {
        echo "   ❌ ERRORE: Non riesco a generare embeddings per la query!" . PHP_EOL;
        exit(1);
    }
    
    echo "   Embedding generato: " . count($embeddings[0]) . " dimensioni" . PHP_EOL;
    
    // Fai ricerca base (top 10)
    $results = $milvus->searchTopKWithEmbedding($tenantId, $embeddings[0], 10);
    
    echo "   Risultati trovati: " . count($results) . PHP_EOL;
    
    if (count($results) > 0) {
        echo PHP_EOL;
        echo "   Top 3 risultati:" . PHP_EOL;
        foreach (array_slice($results, 0, 3) as $i => $hit) {
            $hitDocId = $hit['document_id'] ?? 'unknown';
            $chunkIdx = $hit['chunk_index'] ?? 'unknown';
            $score = $hit['score'] ?? 0;
            echo "     #" . ($i+1) . ": Doc {$hitDocId}, chunk {$chunkIdx}, score: " . round($score, 4) . PHP_EOL;
        }
        
        // Check se doc 4350 è nei risultati
        $doc4350Found = false;
        foreach ($results as $hit) {
            if (($hit['document_id'] ?? 0) == 4350) {
                $doc4350Found = true;
                break;
            }
        }
        
        echo PHP_EOL;
        if ($doc4350Found) {
            echo "   ✅ Doc 4350 È nei risultati!" . PHP_EOL;
        } else {
            echo "   ❌ Doc 4350 NON è nei top-10 risultati" . PHP_EOL;
        }
    } else {
        echo "   ❌ Nessun risultato dalla ricerca!" . PHP_EOL;
    }
    
    echo PHP_EOL;
    echo "✅ Test completato con successo!" . PHP_EOL;
    
} catch (\Throwable $e) {
    echo PHP_EOL;
    echo "❌ ERRORE: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo PHP_EOL;
    echo "Stack trace:" . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

