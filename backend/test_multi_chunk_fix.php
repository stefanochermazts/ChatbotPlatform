<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Cache;

$tenantId = 5;
$query = 'orario comando polizia locale';

echo "🧪 Testing Multi-Chunk per Documento Fix\n";
echo str_repeat('=', 70)."\n\n";

// Clear cache
Cache::flush();

$kbSearch = app(KbSearchService::class);
$result = $kbSearch->retrieve($tenantId, $query, false); // No LLM

echo '📋 Retrieved '.count($result['citations'])." citations\n\n";

// Group by document
$byDoc = [];
foreach ($result['citations'] as $citation) {
    $docId = $citation['document_id'] ?? floor($citation['id'] / 100000);
    $chunkIdx = $citation['chunk_index'] ?? ($citation['id'] % 100000);
    if (! isset($byDoc[$docId])) {
        $byDoc[$docId] = [];
    }
    $byDoc[$docId][] = [
        'chunk_index' => $chunkIdx,
        'score' => $citation['score'] ?? 0,
        'has_orario' => str_contains(strtolower($citation['snippet'] ?? ''), 'orari'),
        'has_polizia_locale' => str_contains(strtolower($citation['snippet'] ?? ''), 'polizia locale'),
        'snippet_preview' => substr($citation['snippet'] ?? '', 0, 100),
    ];
}

echo "📊 Citations by Document:\n\n";
foreach ($byDoc as $docId => $chunks) {
    echo "  📄 Doc {$docId}: ".count($chunks)." chunks\n";
    foreach ($chunks as $chunk) {
        $orarioIcon = $chunk['has_orario'] ? '⏰' : '';
        $poliziaIcon = $chunk['has_polizia_locale'] ? '👮' : '';
        echo "     - Chunk {$chunk['chunk_index']} score:".round($chunk['score'], 4)." {$orarioIcon} {$poliziaIcon}\n";
        echo '       Preview: '.$chunk['snippet_preview']."...\n";
    }
    echo "\n";
}

// Check specific conditions
$doc4351Chunks = $byDoc[4351] ?? [];
if (count($doc4351Chunks) >= 2) {
    echo '✅ SUCCESS: Doc 4351 has '.count($doc4351Chunks)." chunks (multiple chunks allowed!)\n";
} else {
    echo '❌ FAIL: Doc 4351 has only '.count($doc4351Chunks)." chunk (expected 2+)\n";
}

// Check if we have both orari and polizia locale
$hasOrario = false;
$hasPoliziaLocale = false;
foreach ($result['citations'] as $citation) {
    $snippet = strtolower($citation['snippet'] ?? '');
    if (str_contains($snippet, 'orari')) {
        $hasOrario = true;
    }
    if (str_contains($snippet, 'polizia locale')) {
        $hasPoliziaLocale = true;
    }
}

if ($hasOrario && $hasPoliziaLocale) {
    echo "✅ Context contains BOTH 'orari' + 'polizia locale'\n";
} else {
    echo '❌ Missing: orari='.($hasOrario ? 'YES' : 'NO').', polizia locale='.($hasPoliziaLocale ? 'YES' : 'NO')."\n";
}

echo "\n".str_repeat('=', 70)."\n";
echo "✅ Test completed\n";
