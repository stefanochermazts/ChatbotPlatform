<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\MilvusClient;

echo "🔍 RAG TESTER PATH TEST (Simulated)" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;

try {
    $startTime = microtime(true);
    
    // Simulate what RagTestController.php does (without authentication)
    $kbSearch = app(KbSearchService::class);
    $chat = app(OpenAIChatService::class);
    $milvus = app(MilvusClient::class);
    
    $tenant = \App\Models\Tenant::find($tenantId);
    if (!$tenant) {
        throw new \Exception("Tenant {$tenantId} not found");
    }
    
    // Health check
    $health = $milvus->health();
    echo "Milvus Health: " . json_encode($health) . PHP_EOL . PHP_EOL;
    
    // Clear cache (same as RagTestController)
    \Illuminate\Support\Facades\Cache::forget("rag_config_tenant_{$tenantId}");
    
    // Retrieval (same as RagTestController line 132)
    $retrievalStart = microtime(true);
    $retrieval = $kbSearch->retrieve($tenantId, $query, true); // with debug=true
    $retrievalTime = round((microtime(true) - $retrievalStart) * 1000, 2);
    
    $citations = $retrieval['citations'] ?? [];
    $confidence = (float) ($retrieval['confidence'] ?? 0.0);
    
    echo "📋 Retrieval Results:" . PHP_EOL;
    echo "   Citations: " . count($citations) . PHP_EOL;
    echo "   Confidence: " . number_format($confidence, 4) . PHP_EOL;
    echo "   Time: {$retrievalTime}ms" . PHP_EOL;
    echo PHP_EOL;
    
    // Log citations preview
    echo "Top 3 Citations:" . PHP_EOL;
    foreach (array_slice($citations, 0, 3) as $i => $c) {
        $id = $c['id'] ?? $c['document_id'] ?? '?';
        $score = $c['score'] ?? 0;
        $snippet = $c['snippet'] ?? $c['content'] ?? '';
        $hasPhone = strpos($snippet, '06.95898223') !== false;
        $hasPolizia = stripos($snippet, 'polizia locale') !== false;
        
        echo "   [" . ($i + 1) . "] Doc:{$id} Score:" . number_format($score, 4);
        if ($hasPhone && $hasPolizia) echo " ✅✅ (phone + text)";
        elseif ($hasPhone) echo " ✅ (phone only)";
        elseif ($hasPolizia) echo " ✅ (text only)";
        echo PHP_EOL;
    }
    echo PHP_EOL;
    
    // Build context (same as RagTestController line 204-207)
    $contextBuilder = app(\App\Services\RAG\ContextBuilder::class);
    $contextResult = $contextBuilder->build($citations, $tenantId, [
        'compression_enabled' => false, // Disabled for RAG Tester
    ]);
    $contextText = $contextResult['context'] ?? '';
    
    echo "📄 Context Built:" . PHP_EOL;
    echo "   Length: " . strlen($contextText) . " chars" . PHP_EOL;
    echo PHP_EOL;
    
    // Build messages (same as RagTestController line 213-226)
    $messages = [];
    
    if ($tenant && ! empty($tenant->custom_system_prompt)) {
        $messages[] = ['role' => 'system', 'content' => $tenant->custom_system_prompt];
        echo "✅ Using custom system prompt (length: " . strlen($tenant->custom_system_prompt) . ")" . PHP_EOL;
    } else {
        $defaultPrompt = 'Seleziona solo informazioni dai passaggi forniti nel contesto. Se non sono sufficienti, rispondi: "Non lo so".';
        $messages[] = ['role' => 'system', 'content' => $defaultPrompt];
        echo "⚠️  Using default system prompt" . PHP_EOL;
    }
    
    $messages[] = ['role' => 'user', 'content' => 'Domanda: '.$query."\n".$contextText];
    
    $payload = [
        'model' => (string) config('openai.chat_model', 'gpt-4o-mini'),
        'messages' => $messages,
        'max_tokens' => 1000,
    ];
    
    echo PHP_EOL;
    echo "🤖 Calling LLM..." . PHP_EOL;
    
    // Call LLM (same as RagTestController line 236)
    $llmStart = microtime(true);
    $rawResponse = $chat->chatCompletions($payload);
    $llmTime = round((microtime(true) - $llmStart) * 1000, 2);
    
    $answer = $rawResponse['choices'][0]['message']['content'] ?? '';
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "   Time: {$llmTime}ms" . PHP_EOL;
    echo PHP_EOL;
    
    echo str_repeat('=', 80) . PHP_EOL;
    echo "📢 LLM ANSWER:" . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo $answer . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo PHP_EOL;
    
    // Check phones
    $hasCorrectPhone = (strpos($answer, '06.95898223') !== false || strpos($answer, '06 95898223') !== false);
    $hasWrongPhone = (strpos($answer, '06.9587004') !== false);
    
    echo "📞 Phone Detection:" . PHP_EOL;
    echo "   06.95898223 (correct): " . ($hasCorrectPhone ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo "   06.9587004 (Carabinieri): " . ($hasWrongPhone ? '❌ YES' : '✅ NO') . PHP_EOL;
    echo PHP_EOL;
    
    echo "⏱️  Total Duration: {$duration}ms" . PHP_EOL;
    echo "   Retrieval: {$retrievalTime}ms (" . round($retrievalTime / $duration * 100, 1) . "%)" . PHP_EOL;
    echo "   LLM: {$llmTime}ms (" . round($llmTime / $duration * 100, 1) . "%)" . PHP_EOL;
    
    if ($hasCorrectPhone) {
        echo PHP_EOL;
        echo "🎉 SUCCESS! RAG Tester logic returns correct phone!" . PHP_EOL;
    } else {
        echo PHP_EOL;
        echo "❌ FAIL! Correct phone NOT found in answer" . PHP_EOL;
    }
    
} catch (\Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}



