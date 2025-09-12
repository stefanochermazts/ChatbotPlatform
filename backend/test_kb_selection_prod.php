<?php

/**
 * TEST KB SELECTION IN PRODUZIONE
 * 
 * Testa specificamente la selezione della Knowledge Base per debug del problema
 * Uso: php test_kb_selection_prod.php [tenant_id] [query]
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Parametri
$tenantId = (int) ($argv[1] ?? 5);
$query = $argv[2] ?? 'telefono polizia locale';

echo "🎯 TEST KB SELECTION - PRODUZIONE\n";
echo "=================================\n";
echo "Tenant: {$tenantId} | Query: \"{$query}\"\n\n";

try {
    $tenant = \App\Models\Tenant::find($tenantId);
    if (!$tenant) {
        echo "❌ Tenant non trovato!\n";
        exit(1);
    }
    
    // 1. Test selezione KB
    echo "1️⃣ SELEZIONE KNOWLEDGE BASE\n";
    echo "---------------------------\n";
    
    $kbSelector = app(\App\Services\RAG\KnowledgeBaseSelector::class);
    $selection = $kbSelector->selectForQuery($tenantId, $query);
    
    echo "🎯 KB selezionata: " . ($selection['knowledge_base_id'] ?? 'NULL') . "\n";
    echo "📝 Motivo: " . ($selection['reason'] ?? 'N/A') . "\n";
    echo "🏷️ Nome KB: " . ($selection['kb_name'] ?? 'N/A') . "\n\n";
    
    // 2. Test ricerca specifica nella KB selezionata
    if ($selection['knowledge_base_id']) {
        echo "2️⃣ RICERCA NELLA KB SELEZIONATA\n";
        echo "-------------------------------\n";
        
        $textSearch = app(\App\Services\RAG\TextSearchService::class);
        $phoneResults = $textSearch->findPhonesNearName($tenantId, 'polizia locale', 5, $selection['knowledge_base_id']);
        
        echo "📞 Risultati telefono trovati: " . count($phoneResults) . "\n";
        foreach ($phoneResults as $i => $result) {
            echo "  " . ($i+1) . ". Doc {$result['document_id']}\n";
            echo "     Telefono: " . ($result['value'] ?? 'N/A') . "\n";
            echo "     Distanza: " . ($result['distance'] ?? 'N/A') . "\n";
            echo "     Excerpt: " . substr($result['excerpt'] ?? '', 0, 100) . "...\n\n";
        }
        
        // Test anche in tutte le KB
        echo "3️⃣ CONFRONTO CON TUTTE LE KB\n";
        echo "----------------------------\n";
        
        $allKbResults = $textSearch->findPhonesNearName($tenantId, 'polizia locale', 5, null);
        echo "📞 Risultati in tutte le KB: " . count($allKbResults) . "\n";
        
        if (count($allKbResults) !== count($phoneResults)) {
            echo "⚠️ DIFFERENZA RILEVATA!\n";
            echo "  KB specifica: " . count($phoneResults) . " risultati\n";
            echo "  Tutte le KB: " . count($allKbResults) . " risultati\n\n";
            
            // Mostra i risultati mancanti
            echo "📋 Risultati in altre KB:\n";
            foreach ($allKbResults as $i => $result) {
                echo "  " . ($i+1) . ". Doc {$result['document_id']}\n";
                echo "     Telefono: " . ($result['value'] ?? 'N/A') . "\n";
                
                // Trova la KB del documento
                $doc = DB::selectOne('SELECT knowledge_base_id FROM documents WHERE id = ? AND tenant_id = ?', 
                    [$result['document_id'], $tenantId]);
                echo "     KB: " . ($doc->knowledge_base_id ?? 'N/A') . "\n";
                echo "     Excerpt: " . substr($result['excerpt'] ?? '', 0, 80) . "...\n\n";
            }
        } else {
            echo "✅ Stesso numero di risultati in entrambi i casi\n";
        }
    }
    
    // 4. Test multi-KB se abilitato
    echo "4️⃣ TEST MULTI-KB\n";
    echo "----------------\n";
    echo "Multi-KB attualmente: " . ($tenant->multi_kb_search ? 'ABILITATO' : 'DISABILITATO') . "\n";
    
    if (!$tenant->multi_kb_search) {
        echo "🔄 Test temporaneo con Multi-KB abilitato...\n";
        
        // Salva stato originale e abilita temporaneamente
        $originalState = $tenant->multi_kb_search;
        $tenant->update(['multi_kb_search' => true]);
        
        try {
            $service = app(\App\Services\RAG\KbSearchService::class);
            $multiKbResult = $service->retrieve($tenantId, $query, false);
            
            echo "📊 Risultati Multi-KB: " . count($multiKbResult['citations'] ?? []) . " citazioni\n";
            echo "📈 Confidence Multi-KB: " . ($multiKbResult['confidence'] ?? 'N/A') . "\n";
            
        } finally {
            // Ripristina stato originale
            $tenant->update(['multi_kb_search' => $originalState]);
            echo "🔄 Stato Multi-KB ripristinato\n";
        }
    }
    
    echo "\n✅ Test KB Selection completato!\n";
    
} catch (\Exception $e) {
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
