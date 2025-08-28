<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;

$kb = app(KbSearchService::class);
$queries = [
    "Cos'è 'Studio e faccio i compiti'?",     // Con apostrofo e virgolette
    'Cos\'è "Studio e faccio i compiti"?',    // Con virgolette doppie
    'Studio e faccio i compiti',               // Semplice
    'cosa è Studio e faccio i compiti',        // Variante
    'iniziativa studio e faccio i compiti'     // Con 'iniziativa'
];

echo "=== TEST QUERY VARIATIONS ===\n\n";

foreach ($queries as $query) {
    echo str_repeat('-', 60) . "\n";
    echo "Query: {$query}\n";
    
    $result = $kb->retrieve(5, $query, true);
    
    $kbName = $result['debug']['selected_kb']['kb_name'] ?? 'N/A';
    $kbId = $result['debug']['selected_kb']['knowledge_base_id'] ?? 'N/A';
    $citations = count($result['citations']);
    
    echo "KB selezionata: {$kbName} (ID: {$kbId})\n";
    echo "Citazioni: {$citations}\n";
    
    if (!empty($result['citations'])) {
        $firstCit = $result['citations'][0];
        echo "Prima citazione: " . ($firstCit['title'] ?? 'N/A') . "\n";
        
        // Verifica la KB del documento
        if (isset($firstCit['id'])) {
            $doc = \App\Models\Document::find($firstCit['id']);
            if ($doc) {
                echo "KB del documento: " . $doc->knowledge_base_id . " (" . ($doc->knowledgeBase->name ?? 'N/A') . ")\n";
            }
        }
    }
    echo "\n";
}

echo "\n=== ANALISI PROBLEMA ===\n";
echo "Il sistema potrebbe selezionare KB diverse basandosi su:\n";
echo "- Presenza di virgolette o apostrofi nella query\n";
echo "- Ordine delle parole\n";
echo "- Punteggiatura\n";

