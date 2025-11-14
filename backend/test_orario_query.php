<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing Query: 'orario comando polizia locale'\n";
echo str_repeat('=', 70)."\n\n";

$tenantId = 5;
$query = 'orario comando polizia locale'; // WITHOUT "e l'" prefix

// Retrieve citations
$kbSearch = app(\App\Services\RAG\KbSearchService::class);
$result = $kbSearch->retrieve($tenantId, $query, false);
$citations = $result['citations'] ?? [];

echo '📋 Retrieved '.count($citations)." citations\n\n";

// Check top 3
echo "Top 3 Citations:\n";
foreach (array_slice($citations, 0, 3) as $i => $citation) {
    $id = $citation['id'] ?? 'N/A';
    $title = substr($citation['title'] ?? '', 0, 40);
    $score = $citation['score'] ?? 0;
    $snippet = $citation['snippet'] ?? '';

    // Check for orario info
    $hasOrario = stripos($snippet, 'orari') !== false ||
                 stripos($snippet, 'martedì') !== false ||
                 stripos($snippet, 'giovedì') !== false;
    $hasPolizia = stripos($snippet, 'polizia locale') !== false;

    echo sprintf("  %d. Doc:%s Score:%.4f %s %s\n",
        $i + 1, $id, $score,
        $hasOrario ? '⏰' : '',
        $hasPolizia ? '👮' : ''
    );
    echo "     Title: {$title}\n";

    if ($hasOrario && $hasPolizia) {
        echo "     ✅ Contains BOTH orario + polizia locale!\n";
        // Find the orario section
        if (preg_match('/Orari.*?Polizia Locale.*?\|\s*(.+?)\s*\|/si', $snippet, $matches)) {
            echo "     Orario: {$matches[1]}\n";
        }
        echo "     Snippet preview:\n";
        echo '     '.str_repeat('-', 60)."\n";
        echo '     '.substr($snippet, 0, 300)."...\n";
        echo '     '.str_repeat('-', 60)."\n";
    }
}

echo "\n".str_repeat('=', 70)."\n";
echo "✅ Check completed\n";
