<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing RAG Flow with Reranker ENABLED\n";
echo str_repeat('=', 70)."\n\n";

$tenantId = 5;
$query = 'telefono comando polizia locale';

// Verify reranker is enabled
$tenant = \App\Models\Tenant::find($tenantId);
$rerankerEnabled = $tenant->rag_settings['reranker']['enabled'] ?? false;
echo '📊 Reranker Status: '.($rerankerEnabled ? '✅ ENABLED' : '❌ DISABLED')."\n\n";

if (! $rerankerEnabled) {
    exit("⚠️  Reranker is not enabled. Please enable it first.\n");
}

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
    $hasPhone = str_contains($snippet, '06.95898223') ? '📞 ✅' : '';
    $hasPolizia = stripos($snippet, 'polizia locale') !== false ? '👮' : '';

    echo sprintf("  %d. Doc:%s Score:%.4f %s %s\n", $i + 1, $id, $score, $hasPhone, $hasPolizia);
    echo "     Title: {$title}\n";

    if ($hasPhone) {
        echo "     ✅ Contains correct phone!\n";
        echo '     Snippet preview: '.substr($snippet, 0, 150)."...\n";
    }
}

echo "\n".str_repeat('=', 70)."\n";

// Now test with LLM generation (simulating RAG Tester)
echo "🤖 Testing LLM Generation\n";
echo str_repeat('=', 70)."\n\n";

$contextBuilder = app(\App\Services\RAG\ContextBuilder::class);
$contextData = $contextBuilder->build($citations, $tenantId);

$systemPrompt = $tenant->custom_system_prompt ?? config('rag.system_prompt', 'You are a helpful assistant.');

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => "Domanda: {$query}\n\n{$contextData['context']}"],
];

echo "📤 Calling OpenAI...\n";

try {
    $chatService = app(\App\Services\OpenAI\ChatService::class);
    $response = $chatService->chatCompletions([
        'model' => 'gpt-4.1-nano',
        'messages' => $messages,
        'temperature' => 0.1,
        'max_tokens' => 500,
    ]);

    $answer = $response['choices'][0]['message']['content'] ?? 'NO RESPONSE';

    echo "\n📥 LLM Response:\n";
    echo str_repeat('-', 70)."\n";
    echo $answer."\n";
    echo str_repeat('-', 70)."\n\n";

    // Check if answer contains correct phone
    if (str_contains($answer, '06.95898223')) {
        echo "✅ SUCCESS: Answer contains correct phone number!\n";
    } elseif (str_contains($answer, '06.9587004')) {
        echo "❌ FAIL: Answer contains WRONG phone (Carabinieri)\n";
    } elseif (str_contains($answer, '113')) {
        echo "❌ FAIL: Answer contains emergency number instead of local police\n";
    } else {
        echo "⚠️  WARNING: No specific phone number found in answer\n";
    }

} catch (\Exception $e) {
    echo '❌ Error: '.$e->getMessage()."\n";
}

echo "\n".str_repeat('=', 70)."\n";
echo "✅ Test completed\n";
