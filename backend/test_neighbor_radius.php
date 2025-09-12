<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 TEST NEIGHBOR RADIUS\n";
echo "======================\n\n";

$docId = 893;
$baseChunk = 10;
$neighbor = 10;

$textService = app(\App\Services\RAG\TextSearchService::class);

echo "📄 Doc {$docId}, Chunk base {$baseChunk}, Neighbor radius {$neighbor}\n\n";

// Test come nel KbSearchService
$text = $textService->getChunkSnippet($docId, $baseChunk, 512) ?? '';
echo "🎯 CHUNK BASE ({$baseChunk}):\n";
echo substr($text, 0, 200) . "...\n\n";

// Test neighbor expansion
echo "🔄 ESPANSIONE NEIGHBOR:\n";
for ($d = -$neighbor; $d <= $neighbor; $d++) {
    if ($d === 0) continue;
    
    $chunkIndex = $baseChunk + $d;
    $neighborText = $textService->getChunkSnippet($docId, $chunkIndex, 200);
    
    if ($neighborText) {
        echo "  Chunk {$chunkIndex}: " . substr($neighborText, 0, 100) . "...\n";
        
        // Cerca telefoni in questo chunk
        if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $neighborText, $phoneMatches)) {
            echo "    📞 TELEFONI: " . implode(', ', $phoneMatches[0]) . "\n";
        }
        
        $text .= "\n" . $neighborText;
    } else {
        echo "  Chunk {$chunkIndex}: VUOTO\n";
    }
}

echo "\n📊 RISULTATO FINALE:\n";
echo "Lunghezza testo espanso: " . strlen($text) . " caratteri\n";

if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $text, $finalPhones)) {
    echo "📞 TELEFONI NEL TESTO FINALE: " . implode(', ', array_unique($finalPhones[0])) . "\n";
    
    // Verifica se c'è il telefono specifico
    if (in_array('tel:06.95898223', $finalPhones[0])) {
        echo "✅ TELEFONO POLIZIA LOCALE TROVATO!\n";
    } else {
        echo "❌ TELEFONO POLIZIA LOCALE NON TROVATO!\n";
    }
} else {
    echo "❌ NESSUN TELEFONO NEL TESTO FINALE!\n";
}

echo "\n✅ Test completato!\n";
