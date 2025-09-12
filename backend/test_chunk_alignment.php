<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 TEST ALLINEAMENTO CHUNK\n";
echo "=========================\n\n";

$docId = 893;
$baseChunk = 10;
$neighbor = 10;

$textService = app(\App\Services\RAG\TextSearchService::class);

echo "📄 Doc {$docId}, Chunk base {$baseChunk}, Neighbor radius {$neighbor}\n\n";

// Simula ESATTAMENTE la logica del KbSearchService
$text = $textService->getChunkSnippet($docId, $baseChunk, 512) ?? '';
echo "🎯 CHUNK BASE ({$baseChunk}) [512 chars]:\n";
echo $text . "\n";
echo str_repeat("=", 80) . "\n\n";

echo "🔄 ESPANSIONE NEIGHBOR [500 chars each]:\n";
for ($d = -$neighbor; $d <= $neighbor; $d++) {
    if ($d === 0) continue;
    
    $chunkIndex = $baseChunk + $d;
    $neighborText = $textService->getChunkSnippet($docId, $chunkIndex, 500);
    
    if ($neighborText) {
        echo "--- CHUNK {$chunkIndex} ---\n";
        echo $neighborText . "\n";
        
        // Cerca il telefono specifico
        if (strpos($neighborText, '06.95898223') !== false) {
            echo "🎯 TELEFONO TROVATO IN CHUNK {$chunkIndex}!\n";
        }
        
        echo str_repeat("-", 50) . "\n";
        $text .= "\n" . $neighborText;
    }
}

echo "\n📊 RISULTATO FINALE COMBINATO:\n";
echo "Lunghezza totale: " . strlen($text) . " caratteri\n";

// Cerca tutti i telefoni nel testo finale
if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $text, $finalPhones)) {
    echo "📞 TUTTI I TELEFONI: " . implode(', ', array_unique($finalPhones[0])) . "\n";
    
    if (in_array('tel:06.95898223', $finalPhones[0])) {
        echo "✅ TELEFONO POLIZIA LOCALE TROVATO!\n";
    } else {
        echo "❌ TELEFONO POLIZIA LOCALE NON TROVATO!\n";
        
        // Cerca pattern parziali
        if (strpos($text, '95898223') !== false) {
            echo "⚠️ NUMERO SENZA PREFIX TROVATO\n";
        }
        if (strpos($text, '06.95898223') !== false) {
            echo "⚠️ NUMERO CON PUNTO TROVATO\n";
        }
    }
} else {
    echo "❌ NESSUN TELEFONO NEL TESTO FINALE!\n";
}

echo "\n✅ Test completato!\n";
