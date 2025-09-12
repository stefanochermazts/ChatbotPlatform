<?php

/**
 * SCRIPT DI DIAGNOSTICA PRODUZIONE - Problema Telefono Polizia Locale
 * 
 * Da eseguire in produzione per raccogliere informazioni di debug
 * Uso: php diagnose_prod_phone_issue.php [tenant_id] [query]
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

// Parametri - gestisce anche le parentesi quadre
$tenantIdRaw = $argv[1] ?? '5';
$queryRaw = $argv[2] ?? 'telefono polizia locale';

// Rimuovi parentesi quadre se presenti
$tenantId = (int) trim($tenantIdRaw, '[]');
$query = trim($queryRaw, '[]"\'');

echo "🔍 DIAGNOSTICA PRODUZIONE - TELEFONO POLIZIA LOCALE\n";
echo "==================================================\n";
echo "Tenant ID: {$tenantId}\n";
echo "Query: \"{$query}\"\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 1. VERIFICA CONFIGURAZIONE TENANT
    echo "1️⃣ CONFIGURAZIONE TENANT\n";
    echo "------------------------\n";
    
    $tenant = \App\Models\Tenant::find($tenantId);
    if (!$tenant) {
        echo "❌ ERRORE: Tenant {$tenantId} non trovato!\n";
        exit(1);
    }
    
    echo "✅ Tenant trovato: {$tenant->name}\n";
    echo "🌍 Multi-KB Search: " . ($tenant->multi_kb_search ? 'ABILITATO' : 'DISABILITATO') . "\n";
    echo "🎯 KB Default: " . ($tenant->default_knowledge_base_id ?? 'Nessuna') . "\n";
    
    // 🆕 Configurazione RAG
    $tenantRagConfig = new \App\Services\RAG\TenantRagConfigService();
    $hybridConfig = $tenantRagConfig->getHybridConfig($tenantId);
    $neighborRadius = $hybridConfig['neighbor_radius'] ?? 'non impostato (default: 1)';
    echo "🔧 Neighbor Radius: {$neighborRadius}\n";
    
    // Knowledge Bases
    $kbs = \App\Models\KnowledgeBase::where('tenant_id', $tenantId)->get(['id', 'name']);
    echo "📚 Knowledge Bases ({$kbs->count()}):\n";
    foreach ($kbs as $kb) {
        echo "  - KB {$kb->id}: {$kb->name}\n";
    }
    echo "\n";

    // 2. VERIFICA CONTENUTO DOCUMENTI
    echo "2️⃣ VERIFICA CONTENUTO\n";
    echo "---------------------\n";
    
    // Conta documenti totali
    $totalDocs = \App\Models\Document::where('tenant_id', $tenantId)->count();
    echo "📄 Documenti totali: {$totalDocs}\n";
    
    // Cerca documenti con 'polizia'
    $poliziaChunks = DB::select("
        SELECT dc.document_id, d.title, d.knowledge_base_id, d.source_url,
               substring(dc.content, position('polizia' in lower(dc.content)) - 30, 200) as context_snippet
        FROM document_chunks dc
        INNER JOIN documents d ON d.id = dc.document_id
        WHERE dc.tenant_id = ? AND d.tenant_id = ?
          AND LOWER(dc.content) LIKE '%polizia%'
        LIMIT 5
    ", [$tenantId, $tenantId]);
    
    echo "🔍 Chunk con 'polizia' trovati: " . count($poliziaChunks) . "\n";
    foreach ($poliziaChunks as $i => $chunk) {
        echo "  " . ($i+1) . ". Doc {$chunk->document_id} (KB {$chunk->knowledge_base_id})\n";
        echo "     Titolo: " . substr($chunk->title, 0, 60) . "\n";
        echo "     URL: " . ($chunk->source_url ?: 'N/A') . "\n";
        
        // Cerca telefoni nel contesto
        if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunk->context_snippet, $phoneMatches)) {
            echo "     📞 Telefoni: " . implode(', ', array_unique($phoneMatches[0])) . "\n";
        } else {
            echo "     📞 Nessun telefono nel snippet\n";
        }
        echo "     Contesto: " . trim($chunk->context_snippet) . "\n\n";
    }

    // 3. VERIFICA STATO MILVUS
    echo "3️⃣ STATO MILVUS\n";
    echo "---------------\n";
    
    try {
        $milvusService = app(\App\Services\RAG\MilvusClient::class);
        $health = $milvusService->health();
        echo "🟢 Milvus Status: " . ($health['ok'] ? 'ONLINE' : 'OFFLINE') . "\n";
        echo "📊 Milvus Info: " . json_encode($health, JSON_PRETTY_PRINT) . "\n";
    } catch (\Exception $e) {
        echo "❌ Milvus Error: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 4. VERIFICA CACHE REDIS
    echo "4️⃣ STATO CACHE REDIS\n";
    echo "-------------------\n";
    
    try {
        $redis = Redis::connection();
        $prefix = config('cache.prefix', '');
        $ragKeys = [];
        
        // Cerca chiavi RAG
        $searchPattern = $prefix ? $prefix . 'rag:*' : 'rag:*';
        $cursor = 0;
        do {
            $result = $redis->scan($cursor, ['match' => $searchPattern, 'count' => 100]);
            if (is_array($result) && count($result) >= 2) {
                $cursor = (int) $result[0];
                $foundKeys = $result[1] ?? [];
                if (is_array($foundKeys)) {
                    $ragKeys = array_merge($ragKeys, $foundKeys);
                }
            } else {
                break;
            }
        } while ($cursor !== 0);
        
        echo "🔑 Chiavi cache RAG trovate: " . count($ragKeys) . "\n";
        if (count($ragKeys) > 0) {
            echo "📋 Prime 5 chiavi:\n";
            foreach (array_slice($ragKeys, 0, 5) as $key) {
                echo "  - {$key}\n";
            }
        }
    } catch (\Exception $e) {
        echo "❌ Redis Error: " . $e->getMessage() . "\n";
    }
    echo "\n";

    // 5. TEST RICERCA RAG REALE
    echo "5️⃣ TEST RICERCA RAG\n";
    echo "-------------------\n";
    
    try {
        $startTime = microtime(true);
        $service = app(\App\Services\RAG\KbSearchService::class);
        $result = $service->retrieve($tenantId, $query, true); // Debug abilitato
        $endTime = microtime(true);
        
        echo "⏱️ Tempo esecuzione: " . round(($endTime - $startTime) * 1000, 2) . "ms\n";
        echo "📊 Citazioni trovate: " . count($result['citations'] ?? []) . "\n";
        echo "📈 Confidence: " . ($result['confidence'] ?? 'N/A') . "\n";
        
        if (!empty($result['citations'])) {
            echo "\n🎯 Prime 3 citazioni:\n";
            foreach (array_slice($result['citations'], 0, 3) as $i => $cit) {
                echo "  " . ($i+1) . ". Doc {$cit['id']} (KB " . ($cit['knowledge_base_id'] ?? 'N/A') . ")\n";
                echo "     Score: " . round($cit['score'] ?? 0, 3) . "\n";
                echo "     Titolo: " . substr($cit['title'] ?? 'N/A', 0, 60) . "\n";
                
                // Cerca telefoni nel snippet (testo con chunk vicini)
                $snippet = $cit['snippet'] ?? '';
                if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $snippet, $phoneMatches)) {
                    echo "     📞 Telefoni nel snippet: " . implode(', ', array_unique($phoneMatches[0])) . "\n";
                }
                
                // Cerca telefoni nel chunk_text (singolo chunk)
                $chunkText = $cit['chunk_text'] ?? '';
                if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunkText, $phoneMatches2)) {
                    echo "     📞 Telefoni nel chunk_text: " . implode(', ', array_unique($phoneMatches2[0])) . "\n";
                }
                
                echo "     Snippet: " . substr(strip_tags($snippet), 0, 100) . "...\n";
                echo "     Snippet Length: " . strlen($snippet) . " chars\n\n";
            }
        } else {
            echo "❌ PROBLEMA: Nessuna citazione trovata!\n";
        }
        
        // 6. TEST CHIAMATA LLM COMPLETA
        echo "\n6️⃣ TEST CHIAMATA LLM\n";
        echo "-------------------\n";
        
        if (!empty($result['citations'])) {
            // Costruisci il contesto come fa il RAG Tester
            $contextParts = [];
            foreach ($result['citations'] as $citation) {
                $title = $citation['title'] ?? ('Doc ' . $citation['id']);
                $snippet = $citation['snippet'] ?? '';
                if (!empty($snippet)) {
                    $contextParts[] = "**{$title}**:\n{$snippet}";
                }
            }
            $contextText = implode("\n\n---\n\n", $contextParts);
            
            echo "📄 Contesto costruito: " . strlen($contextText) . " caratteri\n";
            
            // Cerca telefoni nel contesto finale
            if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $contextText, $phoneMatches)) {
                echo "📞 Telefoni nel contesto LLM: " . implode(', ', array_unique($phoneMatches[0])) . "\n";
            } else {
                echo "❌ PROBLEMA: Nessun telefono nel contesto LLM!\n";
            }
            
            // Effettua chiamata LLM
            try {
                $chatService = app(\App\Services\LLM\OpenAIChatService::class);
                
                $messages = [
                    ['role' => 'system', 'content' => 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so". Riporta sempre le fonti (titoli) usate.'],
                    ['role' => 'user', 'content' => "Domanda: {$query}\n\nContesto:\n{$contextText}"]
                ];
                
                $payload = [
                    'model' => config('openai.chat_model', 'gpt-4o-mini'),
                    'messages' => $messages,
                    'max_tokens' => 700,
                ];
                
                $startLlm = microtime(true);
                $rawResponse = $chatService->chatCompletions($payload);
                $endLlm = microtime(true);
                
                $answer = $rawResponse['choices'][0]['message']['content'] ?? '';
                
                echo "⏱️ Tempo LLM: " . round(($endLlm - $startLlm) * 1000, 2) . "ms\n";
                echo "📝 Risposta LLM:\n" . str_repeat('-', 40) . "\n";
                echo $answer . "\n";
                echo str_repeat('-', 40) . "\n";
                
                // Cerca telefoni nella risposta
                if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $answer, $answerPhones)) {
                    echo "✅ Telefoni nella risposta: " . implode(', ', array_unique($answerPhones[0])) . "\n";
                } else {
                    echo "❌ Nessun telefono nella risposta finale!\n";
                }
                
            } catch (\Exception $e) {
                echo "❌ Errore chiamata LLM: " . $e->getMessage() . "\n";
            }
        } else {
            echo "⚠️ Saltato: nessuna citazione disponibile\n";
        }
        
        // Debug info se disponibile
        if (!empty($result['debug'])) {
            echo "\n🔧 Debug Info:\n";
            echo json_encode($result['debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ ERRORE RAG: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    echo "\n✅ Diagnostica completata!\n";
    echo "\n📋 AZIONI SUGGERITE:\n";
    echo "1. Se cache RAG > 0: php artisan rag:clear-cache --tenant={$tenantId}\n";
    echo "2. Se Milvus offline: riavviare servizio Milvus\n";
    echo "3. Se nessuna citazione: verificare configurazione embeddings OpenAI\n";
    echo "4. Se citazioni senza telefoni: verificare parsing documenti\n";
    echo "5. Se telefoni nel contesto ma non nella risposta: problema prompt LLM\n";
    echo "6. Se RAG Tester diverso da diagnostic: controllare cleanup del contesto\n";

} catch (\Exception $e) {
    echo "❌ ERRORE FATALE: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
