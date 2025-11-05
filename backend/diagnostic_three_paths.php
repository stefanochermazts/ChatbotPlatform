<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use App\Services\RAG\ContextBuilder;
use App\Services\LLM\OpenAIChatService;
use App\Services\Chat\ChatOrchestrationService;
use Illuminate\Support\Facades\Http;

echo str_repeat('=', 100) . PHP_EOL;
echo "🔍 DIAGNOSTIC TEST - THREE PATHS COMPARISON" . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;
$results = [];

// ============================================================================
// PATH 1: Direct PHP Script (Baseline - WORKS)
// ============================================================================
echo "📍 PATH 1: Direct PHP Script (test_direct_chat.php logic)" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

try {
    $startTime = microtime(true);
    
    $kbSearch = app(KbSearchService::class);
    $result = $kbSearch->retrieve($tenantId, $query, false);
    $citations = $result['citations'] ?? [];
    
    $contextBuilder = app(ContextBuilder::class);
    $contextData = $contextBuilder->build($citations, $tenantId);
    $context = $contextData['context'];
    
    $tenant = \App\Models\Tenant::find($tenantId);
    $systemPrompt = $tenant->custom_system_prompt ?? "Sei un assistente del Comune di San Cesareo.";
    
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Domanda: {$query}\n\n{$context}"]
    ];
    
    $chatService = app(OpenAIChatService::class);
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.1,
        'max_tokens' => 500
    ];
    
    $response = $chatService->chatCompletions($payload);
    $answer = $response['choices'][0]['message']['content'] ?? '';
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    $results['path1_direct'] = [
        'status' => 'success',
        'duration_ms' => $duration,
        'citations_count' => count($citations),
        'context_length' => strlen($context),
        'system_prompt_length' => strlen($systemPrompt),
        'llm_answer' => $answer,
        'has_correct_phone' => (strpos($answer, '06.95898223') !== false || strpos($answer, '06 95898223') !== false),
        'has_wrong_phone' => (strpos($answer, '06.9587004') !== false),
        'citations' => array_map(function($c) {
            return [
                'id' => $c['id'] ?? $c['document_id'] ?? '?',
                'score' => $c['score'] ?? 0,
                'snippet_preview' => mb_substr($c['snippet'] ?? '', 0, 100)
            ];
        }, array_slice($citations, 0, 3))
    ];
    
    echo "✅ Status: SUCCESS" . PHP_EOL;
    echo "⏱️  Duration: {$duration}ms" . PHP_EOL;
    echo "📋 Citations: " . count($citations) . PHP_EOL;
    echo "📄 Context: " . strlen($context) . " chars" . PHP_EOL;
    echo "🤖 Answer: " . mb_substr($answer, 0, 150) . "..." . PHP_EOL;
    echo "📞 Has 06.95898223: " . ($results['path1_direct']['has_correct_phone'] ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo "⚠️  Has 06.9587004 (wrong): " . ($results['path1_direct']['has_wrong_phone'] ? '❌ YES' : '✅ NO') . PHP_EOL;
    
} catch (\Throwable $e) {
    $results['path1_direct'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . PHP_EOL;

// ============================================================================
// PATH 2: ChatOrchestrationService (Widget/API Logic)
// ============================================================================
echo "📍 PATH 2: ChatOrchestrationService (Widget API logic)" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

try {
    \Illuminate\Support\Facades\Artisan::call('cache:clear', [], new \Symfony\Component\Console\Output\NullOutput());
    
    $startTime = microtime(true);
    
    $request = [
        'tenant_id' => $tenantId,
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => $query]
        ],
        'temperature' => 0.1,
        'stream' => false,
    ];
    
    $orchestrator = app(ChatOrchestrationService::class);
    $response = $orchestrator->orchestrate($request);
    $jsonResponse = $response->getData(true);
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    $answer = $jsonResponse['choices'][0]['message']['content'] ?? '';
    $citations = $jsonResponse['citations'] ?? [];
    $confidence = $jsonResponse['retrieval']['confidence'] ?? 0;
    
    $results['path2_orchestration'] = [
        'status' => 'success',
        'duration_ms' => $duration,
        'citations_count' => count($citations),
        'confidence' => $confidence,
        'llm_answer' => $answer,
        'has_correct_phone' => (strpos($answer, '06.95898223') !== false || strpos($answer, '06 95898223') !== false),
        'has_wrong_phone' => (strpos($answer, '06.9587004') !== false),
        'citations' => array_map(function($c) {
            return [
                'id' => $c['document_id'] ?? $c['id'] ?? '?',
                'score' => $c['composite_score'] ?? $c['score'] ?? 0,
                'snippet_preview' => mb_substr($c['snippet'] ?? $c['content'] ?? '', 0, 100)
            ];
        }, array_slice($citations, 0, 3))
    ];
    
    echo "✅ Status: SUCCESS" . PHP_EOL;
    echo "⏱️  Duration: {$duration}ms" . PHP_EOL;
    echo "📋 Citations: " . count($citations) . PHP_EOL;
    echo "🎯 Confidence: " . number_format($confidence, 4) . PHP_EOL;
    echo "🤖 Answer: " . mb_substr($answer, 0, 150) . "..." . PHP_EOL;
    echo "📞 Has 06.95898223: " . ($results['path2_orchestration']['has_correct_phone'] ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo "⚠️  Has 06.9587004 (wrong): " . ($results['path2_orchestration']['has_wrong_phone'] ? '❌ YES' : '✅ NO') . PHP_EOL;
    
} catch (\Throwable $e) {
    $results['path2_orchestration'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . PHP_EOL;

// ============================================================================
// PATH 3: RAG Tester Controller (Admin UI Logic)
// ============================================================================
echo "📍 PATH 3: RAG Tester Controller (Admin UI logic)" . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

try {
    $startTime = microtime(true);
    
    // Simulate RAG Tester request
    $ragTestController = app(\App\Http\Controllers\Admin\RagTestController::class);
    $kbSearch = app(KbSearchService::class);
    $chat = app(OpenAIChatService::class);
    $milvus = app(\App\Services\RAG\MilvusClient::class);
    
    // Build request data (simulating form submission)
    $requestData = new \Illuminate\Http\Request([
        'tenant_id' => $tenantId,
        'query' => $query,
        'with_answer' => true,
        'enable_hyde' => false,
        'enable_conversation' => false,
    ]);
    
    // Call the run method directly
    $viewResponse = $ragTestController->run($requestData, $kbSearch, $chat, $milvus);
    
    // Extract data from view
    $viewData = $viewResponse->getData();
    $result = $viewData['result'] ?? null;
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    if ($result) {
        $citations = $result['citations'] ?? [];
        $answer = $result['answer'] ?? '';
        $confidence = $result['confidence'] ?? 0;
        
        $results['path3_rag_tester'] = [
            'status' => 'success',
            'duration_ms' => $duration,
            'citations_count' => count($citations),
            'confidence' => $confidence,
            'llm_answer' => $answer,
            'has_correct_phone' => (strpos($answer, '06.95898223') !== false || strpos($answer, '06 95898223') !== false),
            'has_wrong_phone' => (strpos($answer, '06.9587004') !== false),
            'citations' => array_map(function($c) {
                return [
                    'id' => $c['id'] ?? $c['document_id'] ?? '?',
                    'score' => $c['score'] ?? 0,
                    'snippet_preview' => mb_substr($c['snippet'] ?? '', 0, 100)
                ];
            }, array_slice($citations, 0, 3))
        ];
        
        echo "✅ Status: SUCCESS" . PHP_EOL;
        echo "⏱️  Duration: {$duration}ms" . PHP_EOL;
        echo "📋 Citations: " . count($citations) . PHP_EOL;
        echo "🎯 Confidence: " . number_format($confidence, 4) . PHP_EOL;
        echo "🤖 Answer: " . mb_substr($answer, 0, 150) . "..." . PHP_EOL;
        echo "📞 Has 06.95898223: " . ($results['path3_rag_tester']['has_correct_phone'] ? '✅ YES' : '❌ NO') . PHP_EOL;
        echo "⚠️  Has 06.9587004 (wrong): " . ($results['path3_rag_tester']['has_wrong_phone'] ? '❌ YES' : '✅ NO') . PHP_EOL;
    } else {
        throw new \Exception("No result returned from RAG Tester");
    }
    
} catch (\Throwable $e) {
    $results['path3_rag_tester'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . PHP_EOL;

// ============================================================================
// COMPARISON REPORT
// ============================================================================
echo str_repeat('=', 100) . PHP_EOL;
echo "📊 COMPARISON REPORT" . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL . PHP_EOL;

$allSuccess = $results['path1_direct']['status'] === 'success' &&
              $results['path2_orchestration']['status'] === 'success' &&
              $results['path3_rag_tester']['status'] === 'success';

if ($allSuccess) {
    $path1Phone = $results['path1_direct']['has_correct_phone'];
    $path2Phone = $results['path2_orchestration']['has_correct_phone'];
    $path3Phone = $results['path3_rag_tester']['has_correct_phone'];
    
    echo "📞 Correct Phone (06.95898223) Detection:" . PHP_EOL;
    echo "   Path 1 (Direct):        " . ($path1Phone ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo "   Path 2 (Orchestration): " . ($path2Phone ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo "   Path 3 (RAG Tester):    " . ($path3Phone ? '✅ YES' : '❌ NO') . PHP_EOL;
    echo PHP_EOL;
    
    if ($path1Phone && $path2Phone && $path3Phone) {
        echo "🎉 SUCCESS: All 3 paths return the correct phone number!" . PHP_EOL;
    } else {
        echo "⚠️  INCONSISTENCY DETECTED:" . PHP_EOL;
        if (!$path2Phone) echo "   - Path 2 (Orchestration/Widget) FAILS" . PHP_EOL;
        if (!$path3Phone) echo "   - Path 3 (RAG Tester) FAILS" . PHP_EOL;
    }
}

// Save results to JSON
$jsonFile = __DIR__ . '/diagnostic_results_' . date('Y-m-d_H-i-s') . '.json';
file_put_contents($jsonFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo PHP_EOL;
echo "💾 Results saved to: " . basename($jsonFile) . PHP_EOL;
echo PHP_EOL;

// Generate markdown report
$mdReport = "# Diagnostic Test Report - " . date('Y-m-d H:i:s') . "\n\n";
$mdReport .= "## Query\n`{$query}`\n\n";
$mdReport .= "## Results Summary\n\n";
$mdReport .= "| Path | Status | Phone Correct | Duration | Citations |\n";
$mdReport .= "|------|--------|---------------|----------|----------|\n";

foreach ($results as $pathName => $data) {
    $status = $data['status'] === 'success' ? '✅' : '❌';
    $phone = isset($data['has_correct_phone']) ? ($data['has_correct_phone'] ? '✅' : '❌') : 'N/A';
    $duration = isset($data['duration_ms']) ? $data['duration_ms'] . 'ms' : 'N/A';
    $citations = isset($data['citations_count']) ? $data['citations_count'] : 'N/A';
    
    $mdReport .= "| {$pathName} | {$status} | {$phone} | {$duration} | {$citations} |\n";
}

$mdReport .= "\n## Detailed Responses\n\n";

foreach ($results as $pathName => $data) {
    $mdReport .= "### {$pathName}\n\n";
    if ($data['status'] === 'success') {
        $mdReport .= "**LLM Answer:**\n```\n" . ($data['llm_answer'] ?? 'N/A') . "\n```\n\n";
        $mdReport .= "**Citations:** " . ($data['citations_count'] ?? 0) . "\n\n";
    } else {
        $mdReport .= "**Error:** " . ($data['error'] ?? 'Unknown') . "\n\n";
    }
}

$mdFile = __DIR__ . '/docs/diagnostic_report_' . date('Y-m-d_H-i-s') . '.md';
file_put_contents($mdFile, $mdReport);

echo "📄 Markdown report saved to: " . str_replace(__DIR__ . '/', '', $mdFile) . PHP_EOL;
echo str_repeat('=', 100) . PHP_EOL;



