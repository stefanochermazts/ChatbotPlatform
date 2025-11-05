<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Cache;

$tenantId = 5;
$query = 'orario comando polizia locale';

echo "🔍 Check Doc 4351 Chunks in Citations\n\n";

Cache::flush();

$kbSearch = app(KbSearchService::class);
$result = $kbSearch->retrieve($tenantId, $query, false);

$doc4351Chunks = [];
foreach ($result['citations'] as $idx => $cit) {
    if ($cit['id'] == 4351) {
        $doc4351Chunks[] = [
            'position' => $idx + 1,
            'chunk_index' => $cit['chunk_index'],
            'score' => $cit['score'],
            'has_orario_comando' => str_contains(strtolower($cit['chunk_text'] ?? ''), 'orari apertura al pubblico comando'),
            'has_polizia_locale' => str_contains(strtolower($cit['chunk_text'] ?? ''), 'polizia locale'),
            'text_preview' => substr($cit['chunk_text'] ?? '', 0, 200),
        ];
    }
}

if (empty($doc4351Chunks)) {
    echo "❌ NO chunks from doc 4351 in final citations!\n";
} else {
    echo "✅ Found " . count($doc4351Chunks) . " chunks from doc 4351:\n\n";
    foreach ($doc4351Chunks as $chunk) {
        echo "  Position #{$chunk['position']} - Chunk {$chunk['chunk_index']} - Score: " . round($chunk['score'], 4) . "\n";
        echo "    Has 'orari apertura al pubblico comando': " . ($chunk['has_orario_comando'] ? '✅' : '❌') . "\n";
        echo "    Has 'polizia locale': " . ($chunk['has_polizia_locale'] ? '✅' : '❌') . "\n";
        echo "    Preview: " . $chunk['text_preview'] . "...\n\n";
    }
}

// Look specifically for the chunk with "COMANDO POLIZIA LOCALE" orari
echo "🎯 Looking for 'COMANDO POLIZIA LOCALE' with orario table...\n";
$found = false;
foreach ($result['citations'] as $cit) {
    $text = strtolower($cit['chunk_text'] ?? '');
    if (str_contains($text, 'comando') && 
        str_contains($text, 'polizia locale') && 
        str_contains($text, '9:00')) {
        $found = true;
        echo "✅ FOUND in Doc {$cit['id']}, chunk {$cit['chunk_index']}\n";
        echo "Text:\n" . substr($cit['chunk_text'], 0, 500) . "\n";
        break;
    }
}
if (!$found) {
    echo "❌ NOT FOUND in any citation\n";
}

