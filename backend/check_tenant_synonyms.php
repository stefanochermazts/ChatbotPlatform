<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo '=== CHECK TENANT 5 CUSTOM SYNONYMS ==='.PHP_EOL.PHP_EOL;

$tenant = Tenant::find(5);

if (! $tenant) {
    echo '❌ Tenant 5 not found!'.PHP_EOL;
    exit(1);
}

echo "Tenant: {$tenant->name}".PHP_EOL;
echo PHP_EOL;

$customSynonyms = $tenant->custom_synonyms;

if (empty($customSynonyms)) {
    echo '✅ NO custom_synonyms - will use global getSynonymsMap()'.PHP_EOL;
    echo PHP_EOL;
    echo 'This is GOOD - the new synonyms in getSynonymsMap() should work!'.PHP_EOL;
} else {
    echo '⚠️  HAS custom_synonyms - OVERRIDING global getSynonymsMap()!'.PHP_EOL;
    echo PHP_EOL;
    echo '📚 CUSTOM SYNONYMS:'.PHP_EOL;

    if (is_string($customSynonyms)) {
        $customSynonyms = json_decode($customSynonyms, true);
    }

    foreach ($customSynonyms as $term => $synonymList) {
        echo "  '{$term}' => '{$synonymList}'".PHP_EOL;
    }
    echo PHP_EOL;

    // Check if 'telefono' is in custom synonyms
    if (isset($customSynonyms['telefono'])) {
        echo "✅ 'telefono' FOUND in custom_synonyms!".PHP_EOL;
        echo "   Synonyms: {$customSynonyms['telefono']}".PHP_EOL;
    } else {
        echo "❌ 'telefono' NOT in custom_synonyms!".PHP_EOL;
        echo PHP_EOL;
        echo '🔧 SOLUTION:'.PHP_EOL;
        echo "  Option 1: Add 'telefono' to custom_synonyms for Tenant 5".PHP_EOL;
        echo '  Option 2: Clear custom_synonyms to use global map'.PHP_EOL;
        echo '  Option 3: Merge custom_synonyms with global map in code'.PHP_EOL;
    }
}
