<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenantId = 5;
$query = "telefono comando polizia locale";

$kbSearch = app(\App\Services\RAG\KbSearchService::class);

echo "🔍 Debugging Citation Structure\n\n";

$result = $kbSearch->retrieve($tenantId, $query, false);
$citations = $result['citations'] ?? [];

echo "Total citations: " . count($citations) . "\n\n";

if (!empty($citations)) {
    echo "First citation structure:\n";
    echo json_encode($citations[0], JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Keys available:\n";
    echo "- " . implode("\n- ", array_keys($citations[0])) . "\n\n";
    
    // Check if doc 4350 is in the list
    echo "Searching for doc 4350...\n";
    foreach ($citations as $i => $c) {
        // Try different keys
        $docId = $c['document_id'] ?? $c['id'] ?? $c['doc_id'] ?? null;
        $snippet = $c['snippet'] ?? $c['content'] ?? '';
        
        if ($docId == 4350 || str_contains($snippet, '06.95898223')) {
            echo "  Found at position " . ($i+1) . ":\n";
            echo "    document_id: " . ($c['document_id'] ?? 'N/A') . "\n";
            echo "    id: " . ($c['id'] ?? 'N/A') . "\n";
            echo "    Has phone: " . (str_contains($snippet, '06.95898223') ? 'YES' : 'NO') . "\n";
            echo "    Has 'polizia locale': " . (stripos($snippet, 'polizia locale') !== false ? 'YES' : 'NO') . "\n";
        }
    }
}

