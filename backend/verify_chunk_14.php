<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔍 VERIFICA CHUNK 14 DIRETTO\n";
echo "============================\n\n";

$docId = 893;
$chunkIndex = 14;
$tenantId = 1;

// Query diretta al database
$chunk = DB::selectOne("
    SELECT content 
    FROM document_chunks 
    WHERE document_id = ? AND chunk_index = ? AND tenant_id = ?
", [$docId, $chunkIndex, $tenantId]);

if ($chunk) {
    echo "📄 CHUNK 14 DIRETTO DAL DB:\n";
    echo str_repeat("=", 50) . "\n";
    echo $chunk->content . "\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // Cerca il telefono
    if (strpos($chunk->content, '06.95898223') !== false) {
        echo "✅ TELEFONO 06.95898223 TROVATO NEL DB!\n";
    } else {
        echo "❌ TELEFONO 06.95898223 NON TROVATO NEL DB!\n";
    }
    
    // Cerca tutti i telefoni
    if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunk->content, $phones)) {
        echo "📞 TELEFONI NEL CHUNK 14: " . implode(', ', $phones[0]) . "\n";
    }
    
} else {
    echo "❌ CHUNK 14 NON TROVATO NEL DATABASE!\n";
}

// Confronta con getChunkSnippet
echo "\n🔄 CONFRONTO CON getChunkSnippet:\n";
$textService = app(\App\Services\RAG\TextSearchService::class);
$snippetText = $textService->getChunkSnippet($docId, $chunkIndex, 1000);

if ($snippetText) {
    echo str_repeat("-", 50) . "\n";
    echo $snippetText . "\n";
    echo str_repeat("-", 50) . "\n\n";
    
    if (strpos($snippetText, '06.95898223') !== false) {
        echo "✅ TELEFONO 06.95898223 TROVATO VIA getChunkSnippet!\n";
    } else {
        echo "❌ TELEFONO 06.95898223 NON TROVATO VIA getChunkSnippet!\n";
    }
} else {
    echo "❌ getChunkSnippet restituisce NULL!\n";
}

echo "\n✅ Verifica completata!\n";
