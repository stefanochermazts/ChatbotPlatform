<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Log;

echo "=== TEST SYNONYM EXPANSION ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

echo "Original Query: \"{$query}\"" . PHP_EOL;
echo PHP_EOL;

try {
    $kbSearch = app(KbSearchService::class);
    
    // Use reflection to call private method
    $reflection = new ReflectionClass($kbSearch);
    
    // Get getTenantSynonyms
    $getTenantSynonyms = $reflection->getMethod('getTenantSynonyms');
    $getTenantSynonyms->setAccessible(true);
    $synonyms = $getTenantSynonyms->invoke($kbSearch, $tenantId);
    
    echo "📚 SYNONYM MAP (first 10):" . PHP_EOL;
    $count = 0;
    foreach ($synonyms as $term => $synonymList) {
        echo "  '{$term}' => '{$synonymList}'" . PHP_EOL;
        $count++;
        if ($count >= 10) break;
    }
    echo PHP_EOL;
    
    // Check if 'telefono' is in map
    if (isset($synonyms['telefono'])) {
        echo "✅ 'telefono' FOUND in synonym map!" . PHP_EOL;
        echo "   Synonyms: {$synonyms['telefono']}" . PHP_EOL;
    } else {
        echo "❌ 'telefono' NOT in synonym map!" . PHP_EOL;
    }
    echo PHP_EOL;
    
    // Test expandQueryWithSynonyms
    $expandQueryWithSynonyms = $reflection->getMethod('expandQueryWithSynonyms');
    $expandQueryWithSynonyms->setAccessible(true);
    $expanded = $expandQueryWithSynonyms->invoke($kbSearch, $query, $tenantId);
    
    echo "🔄 EXPANSION RESULT:" . PHP_EOL;
    echo "  Original: \"{$query}\"" . PHP_EOL;
    echo "  Expanded: \"{$expanded}\"" . PHP_EOL;
    echo PHP_EOL;
    
    // Parse expanded to see what was added
    $original_words = explode(' ', strtolower($query));
    $expanded_words = explode(' ', strtolower($expanded));
    $added_words = array_diff($expanded_words, $original_words);
    
    echo "➕ ADDED WORDS:" . PHP_EOL;
    if (empty($added_words)) {
        echo "  (none)" . PHP_EOL;
    } else {
        foreach ($added_words as $word) {
            echo "  - {$word}" . PHP_EOL;
        }
    }
    echo PHP_EOL;
    
    // Check if 'tel' was added
    if (in_array('tel', $added_words)) {
        echo "✅ 'tel' WAS ADDED!" . PHP_EOL;
    } else {
        echo "❌ 'tel' WAS NOT ADDED!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 DEBUGGING WHY 'tel' WAS NOT ADDED:" . PHP_EOL;
        
        // Manually check the logic
        $queryLower = mb_strtolower($query);
        echo "  1. Query lowercase: \"{$queryLower}\"" . PHP_EOL;
        
        $termLower = 'telefono';
        echo "  2. Checking term: \"{$termLower}\"" . PHP_EOL;
        
        $pattern = '/\b' . preg_quote($termLower, '/') . '\b/u';
        echo "  3. Pattern: {$pattern}" . PHP_EOL;
        
        $matches = preg_match($pattern, $queryLower);
        echo "  4. Pattern matches: " . ($matches ? 'YES' : 'NO') . PHP_EOL;
        
        if ($matches) {
            $synonymList = $synonyms['telefono'];
            $synonymWords = preg_split('/[\s,]+/', $synonymList, -1, PREG_SPLIT_NO_EMPTY);
            echo "  5. Synonym words to add: " . implode(', ', $synonymWords) . PHP_EOL;
            
            foreach ($synonymWords as $synonym) {
                $synonym = trim($synonym);
                $synonymLower = mb_strtolower($synonym);
                
                $already_in_query = str_contains($queryLower, $synonymLower);
                echo "  6. Check '{$synonym}': already in query = " . ($already_in_query ? 'YES (skip)' : 'NO (add)') . PHP_EOL;
            }
        }
    }
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

