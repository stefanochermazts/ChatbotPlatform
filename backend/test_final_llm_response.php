<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Chat\ChatOrchestrationService;
use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Cache;

$tenantId = 5;
$query = 'orario comando polizia locale';

echo "🧪 Testing Final LLM Response\n";
echo str_repeat('=', 70)."\n\n";

Cache::flush();

$kbSearch = app(KbSearchService::class);
$chatOrchestrationService = app(ChatOrchestrationService::class);

// 1. Retrieve
$result = $kbSearch->retrieve($tenantId, $query, false);
echo '📋 Retrieved '.count($result['citations'])." citations\n";

// Check if we have the correct chunk
$hasOrarioComando = false;
foreach ($result['citations'] as $cit) {
    if ($cit['id'] == 4351 && $cit['chunk_index'] == 1) {
        $hasOrarioComando = true;
        echo "✅ Citation #8 (Doc 4351, chunk 1) with orari comando: PRESENT\n";
        break;
    }
}
if (! $hasOrarioComando) {
    echo "❌ WARNING: Chunk 4351.1 with orari comando NOT in citations\n";
}

// 2. Call ChatOrchestrationService (builds context + calls LLM)
echo "\n🤖 Calling ChatOrchestrationService...\n";
try {
    $response = $chatOrchestrationService->chat($tenantId, $query, []);
    $llmAnswer = $response['content'] ?? '';

    echo "\n📢 LLM Response:\n";
    echo str_repeat('-', 70)."\n";
    echo $llmAnswer."\n";
    echo str_repeat('-', 70)."\n\n";

    // Check if answer is correct
    if (str_contains($llmAnswer, 'Martedì') || str_contains($llmAnswer, '8:30') || str_contains($llmAnswer, '9:00')) {
        echo "✅ SUCCESS: LLM mentions orario!\n";
    } else {
        echo "❌ FAIL: LLM does NOT mention orario\n";
    }

    if (str_contains(strtolower($llmAnswer), 'comando') || str_contains(strtolower($llmAnswer), 'polizia locale')) {
        echo "✅ SUCCESS: LLM mentions comando/polizia locale\n";
    } else {
        echo "❌ FAIL: LLM does NOT mention comando/polizia locale\n";
    }
} catch (\Exception $e) {
    echo '❌ Error: '.$e->getMessage()."\n";
}

echo "\n".str_repeat('=', 70)."\n";
echo "✅ Test completed\n";
