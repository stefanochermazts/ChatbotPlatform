<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;

echo "=== VERIFY WIDGET CITATIONS ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

$kbSearch = app(KbSearchService::class);
$retrieval = $kbSearch->retrieve($tenantId, $query, true); // with debug=true like orchestration
$citations = $retrieval['citations'] ?? [];

echo "Citations received (with debug=true, like ChatOrchestrationService):" . PHP_EOL;
foreach (array_slice($citations, 0, 5) as $i => $c) {
    $id = $c['id'] ?? $c['document_id'] ?? '?';
    $score = $c['score'] ?? 0;
    $snippet = $c['snippet'] ?? $c['chunk_text'] ?? '';
    
    $hasPhone = strpos($snippet, '06.95898223') !== false;
    $hasPolizia = stripos($snippet, 'polizia locale') !== false;
    
    echo "   [" . ($i + 1) . "] ID:" . $id . " Score:" . number_format($score, 4);
    if ($hasPhone && $hasPolizia) {
        echo " ✅✅ HAS BOTH!";
    } elseif ($hasPhone) {
        echo " ✅ phone only";
    } elseif ($hasPolizia) {
        echo " ✅ text only";
    }
    echo PHP_EOL;
}

echo PHP_EOL;
echo "🔍 Looking for doc 4350..." . PHP_EOL;
$found4350 = false;
foreach ($citations as $i => $c) {
    if (($c['id'] ?? $c['document_id'] ?? 0) == 4350) {
        echo "   ✅ Doc 4350 FOUND at position #" . ($i + 1) . PHP_EOL;
        $found4350 = true;
        break;
    }
}

if (!$found4350) {
    echo "   ❌ Doc 4350 NOT FOUND in citations!" . PHP_EOL;
}

