<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo '=== DEBUG SYNONYM EXPANSION STEP-BY-STEP ==='.PHP_EOL.PHP_EOL;

$query = 'telefono comando polizia locale';
$tenantId = 5;

echo "Query: \"{$query}\"".PHP_EOL;
echo "Tenant ID: {$tenantId}".PHP_EOL;
echo PHP_EOL;

// Get synonyms
$tenant = Tenant::find($tenantId);
$synonyms = $tenant->custom_synonyms;

if (is_string($synonyms)) {
    $synonyms = json_decode($synonyms, true);
}

if (empty($synonyms)) {
    echo '❌ No synonyms configured!'.PHP_EOL;
    exit(1);
}

echo '✅ Synonyms loaded: '.count($synonyms).' entries'.PHP_EOL;
echo PHP_EOL;

// Simulate expandQueryWithSynonyms() logic
$queryLower = mb_strtolower($query);
$expanded = $query;
$addedSynonyms = [];

// Sort by length descending
$sortedSynonyms = $synonyms;
uksort($sortedSynonyms, fn ($a, $b) => strlen($b) - strlen($a));

echo '🔄 PROCESSING SYNONYMS (sorted by length desc):'.PHP_EOL;
echo str_repeat('=', 80).PHP_EOL;

$stepCount = 0;
foreach ($sortedSynonyms as $term => $synonymList) {
    $stepCount++;
    $termLower = mb_strtolower($term);

    // Only show relevant terms
    if (! str_contains($queryLower, $termLower) && $term !== 'telefono' && $term !== 'polizia locale') {
        continue; // Skip irrelevant
    }

    echo PHP_EOL;
    echo "STEP {$stepCount}: Term '{$term}' (length ".strlen($term).')'.PHP_EOL;
    echo "  - Synonyms: \"{$synonymList}\"".PHP_EOL;

    // Check if term matches query
    $pattern = '/\b'.preg_quote($termLower, '/').'\b/u';
    $matches = preg_match($pattern, $queryLower);

    echo "  - Pattern: {$pattern}".PHP_EOL;
    echo '  - Matches in query: '.($matches ? 'YES ✅' : 'NO ❌').PHP_EOL;

    if ($matches) {
        // Process synonyms
        $synonymWords = preg_split('/[\s,]+/', $synonymList, -1, PREG_SPLIT_NO_EMPTY);
        echo '  - Synonym words: '.implode(', ', $synonymWords).PHP_EOL;

        $addedThisStep = [];
        foreach ($synonymWords as $synonym) {
            $synonym = trim($synonym);
            $synonymLower = mb_strtolower($synonym);

            $alreadyInQuery = str_contains($queryLower, $synonymLower);
            $alreadyAdded = in_array($synonymLower, $addedSynonyms, true);

            if ($synonym !== '' && ! $alreadyInQuery && ! $alreadyAdded) {
                $addedSynonyms[] = $synonymLower;
                $addedThisStep[] = $synonym;
            }
        }

        if (empty($addedThisStep)) {
            echo '  - Added: (none - all already in query or added)'.PHP_EOL;
        } else {
            echo '  - Added: '.implode(', ', $addedThisStep).' ✅'.PHP_EOL;
        }
    }
}

echo PHP_EOL;
echo str_repeat('=', 80).PHP_EOL;
echo PHP_EOL;

// Final result
if (! empty($addedSynonyms)) {
    $expanded .= ' '.implode(' ', $addedSynonyms);
}

echo '📊 FINAL RESULT:'.PHP_EOL;
echo "  Original: \"{$query}\"".PHP_EOL;
echo "  Expanded: \"{$expanded}\"".PHP_EOL;
echo PHP_EOL;

echo '➕ ALL ADDED SYNONYMS:'.PHP_EOL;
if (empty($addedSynonyms)) {
    echo '  (none)'.PHP_EOL;
} else {
    foreach ($addedSynonyms as $syn) {
        echo "  - {$syn}".PHP_EOL;
    }
}
echo PHP_EOL;

// Check if 'tel' was added
if (in_array('tel', $addedSynonyms)) {
    echo "✅ SUCCESS: 'tel' WAS ADDED!".PHP_EOL;
} else {
    echo "❌ FAILURE: 'tel' WAS NOT ADDED!".PHP_EOL;
}
