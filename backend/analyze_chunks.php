<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "📊 ANALISI CHUNK DOCUMENTO 1795\n";
echo "================================\n\n";

$chunks = DB::table('document_chunks')
    ->where('document_id', 1795)
    ->orderBy('chunk_index')
    ->get();

echo "Totale chunks: " . $chunks->count() . "\n\n";

foreach ($chunks as $chunk) {
    $hasPolizia = (stripos($chunk->content, 'polizia') !== false || stripos($chunk->content, 'comando') !== false);
    $primaryId = (1795 * 100000) + $chunk->chunk_index;
    
    echo "🔹 CHUNK {$chunk->chunk_index}\n";
    echo "Primary ID: {$primaryId}\n";
    echo "Length: " . strlen($chunk->content) . " chars\n";
    echo "Contains POLIZIA: " . ($hasPolizia ? "✅ YES" : "❌ NO") . "\n";
    echo "Preview (first 200 chars):\n";
    echo substr($chunk->content, 0, 200) . "...\n";
    
    if ($hasPolizia) {
        echo "\n🎯 FOUND POLIZIA CONTENT!\n";
        echo "Looking for schedule patterns...\n";
        
        // Cerca pattern di orari
        if (preg_match_all('/\b(?:mart|giov|vener)[a-zì]*\s*[|:]\s*\d{1,2}[:.]\d{2}\s*[-–—]\s*\d{1,2}[:.]\d{2}/i', $chunk->content, $matches)) {
            echo "Schedule patterns found:\n";
            foreach ($matches[0] as $match) {
                echo "  - " . trim($match) . "\n";
            }
        }
    }
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
}

echo "🤔 PROBLEMA IDENTIFICATO:\n";
echo "Se Milvus restituisce solo il chunk 0, non troverà mai le informazioni sulla polizia locale!\n";
echo "Possibili cause:\n";
echo "1. Query embedding non è simile al contenuto del chunk con la polizia\n";
echo "2. Il chunk con la polizia non è stato indicizzato correttamente in Milvus\n";
echo "3. Il reranking sta penalizzando il chunk corretto\n";
