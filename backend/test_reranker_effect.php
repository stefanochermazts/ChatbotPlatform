<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenantId = 5;
$query = 'telefono comando polizia locale';

echo "🧪 Testing Reranker Effect on Query: '$query'\n";
echo str_repeat('=', 70)."\n\n";

// Get services
$kbSearch = app(\App\Services\RAG\KbSearchService::class);
$ragConfig = app(\App\Services\RAG\TenantRagConfigService::class);

// Get current config
$rerankerConfig = $ragConfig->getRerankerConfig($tenantId);
$originalEnabled = $rerankerConfig['enabled'] ?? false;
$originalDriver = $rerankerConfig['driver'] ?? 'embedding';

echo "📊 Original Config:\n";
echo '   Enabled: '.($originalEnabled ? 'YES' : 'NO')."\n";
echo "   Driver:  {$originalDriver}\n\n";

// Test 1: WITHOUT reranker
echo '='.str_repeat('=', 69)."\n";
echo "📊 TEST 1: WITHOUT Reranker\n";
echo '='.str_repeat('=', 69)."\n";

// Temporarily disable
$tenant = \App\Models\Tenant::find($tenantId);
$settings = $tenant->rag_settings ?? [];
$settings['reranker']['enabled'] = false;
$tenant->rag_settings = $settings;
$tenant->save();

// Clear cache
\Illuminate\Support\Facades\Cache::flush();

try {
    $result1 = $kbSearch->retrieve($tenantId, $query, false);
    $citations1 = $result1['citations'] ?? [];

    echo 'Total Citations: '.count($citations1)."\n\n";
    echo "Top 5 Citations:\n";
    foreach (array_slice($citations1, 0, 5) as $i => $citation) {
        $docId = $citation['document_id'] ?? 'N/A';
        $chunkIdx = $citation['chunk_index'] ?? 'N/A';
        $score = $citation['score'] ?? 0;
        $snippet = $citation['snippet'] ?? '';
        $hasPhone = str_contains($snippet, '06.95898223') ? '📞✅' : '';
        $hasPolizia = stripos($snippet, 'polizia locale') !== false ? '👮' : '';
        $title = substr($citation['title'] ?? '', 0, 40);

        echo sprintf(
            "  %d. Doc:%4s Chunk:%2s Score:%.4f %s %s\n",
            $i + 1, $docId, $chunkIdx, $score, $hasPhone, $hasPolizia
        );
        echo "     Title: {$title}\n";
        if ($hasPhone) {
            echo '     Snippet: '.substr($snippet, 0, 100)."...\n";
        }
    }
} catch (\Exception $e) {
    echo '❌ Error: '.$e->getMessage()."\n";
}

// Test 2: WITH embedding reranker
echo "\n".str_repeat('=', 70)."\n";
echo "📊 TEST 2: WITH Reranker (driver: embedding)\n";
echo str_repeat('=', 70)."\n";

// Update using raw SQL to avoid multiple jsonb_set conflict
$tenant = \App\Models\Tenant::find($tenantId);
$settings = $tenant->rag_settings ?? [];
$settings['reranker']['enabled'] = true;
$settings['reranker']['driver'] = 'embedding';
$tenant->rag_settings = $settings;
$tenant->save();

\Illuminate\Support\Facades\Cache::flush();

try {
    $result2 = $kbSearch->retrieve($tenantId, $query, false);
    $citations2 = $result2['citations'] ?? [];

    echo 'Total Citations: '.count($citations2)."\n\n";
    echo "Top 5 Citations:\n";
    foreach (array_slice($citations2, 0, 5) as $i => $citation) {
        $docId = $citation['document_id'] ?? 'N/A';
        $chunkIdx = $citation['chunk_index'] ?? 'N/A';
        $score = $citation['score'] ?? 0;
        $snippet = $citation['snippet'] ?? '';
        $hasPhone = str_contains($snippet, '06.95898223') ? '📞✅' : '';
        $hasPolizia = stripos($snippet, 'polizia locale') !== false ? '👮' : '';
        $title = substr($citation['title'] ?? '', 0, 40);

        echo sprintf(
            "  %d. Doc:%4s Chunk:%2s Score:%.4f %s %s\n",
            $i + 1, $docId, $chunkIdx, $score, $hasPhone, $hasPolizia
        );
        echo "     Title: {$title}\n";
        if ($hasPhone) {
            echo '     Snippet: '.substr($snippet, 0, 100)."...\n";
        }
    }
} catch (\Exception $e) {
    echo '❌ Error: '.$e->getMessage()."\n";
}

// Restore original state
$tenant = \App\Models\Tenant::find($tenantId);
$settings = $tenant->rag_settings ?? [];
$settings['reranker']['enabled'] = $originalEnabled;
$tenant->rag_settings = $settings;
$tenant->save();

// Compare
echo "\n".str_repeat('=', 70)."\n";
echo "📈 COMPARISON\n";
echo str_repeat('=', 70)."\n";

$doc4350Pos1 = null;
$doc4350Pos2 = null;

foreach ($citations1 as $i => $c) {
    if (($c['document_id'] ?? 0) == 4350) {
        $snippet = $c['snippet'] ?? '';
        if (str_contains($snippet, '06.95898223')) {
            $doc4350Pos1 = $i + 1;
            break;
        }
    }
}

foreach ($citations2 as $i => $c) {
    if (($c['document_id'] ?? 0) == 4350) {
        $snippet = $c['snippet'] ?? '';
        if (str_contains($snippet, '06.95898223')) {
            $doc4350Pos2 = $i + 1;
            break;
        }
    }
}

echo "Doc 4350 (with correct phone 06.95898223) position:\n";
echo '  WITHOUT reranker: '.($doc4350Pos1 ? "#$doc4350Pos1" : '❌ NOT IN TOP 10')."\n";
echo '  WITH reranker:    '.($doc4350Pos2 ? "#$doc4350Pos2" : '❌ NOT IN TOP 10')."\n\n";

// Verdict
if ($doc4350Pos2 && $doc4350Pos2 <= 3) {
    echo "✅ SUCCESS: Doc 4350 remains in top 3 with embedding reranker!\n";
    echo "   Recommendation: ENABLE embedding reranker\n";
} elseif ($doc4350Pos2 && $doc4350Pos2 <= 5) {
    echo "⚠️  WARNING: Doc 4350 demoted to position {$doc4350Pos2}\n";
    echo "   Recommendation: Test LLM reranker or keep disabled\n";
} elseif ($doc4350Pos2) {
    echo "❌ FAIL: Doc 4350 demoted to position {$doc4350Pos2}\n";
    echo "   Recommendation: DISABLE reranker or test LLM driver\n";
} else {
    echo "❌ CRITICAL: Doc 4350 not in top 10 with reranker!\n";
    echo "   Recommendation: KEEP RERANKER DISABLED\n";
}

echo "\n".str_repeat('=', 70)."\n";
echo "✅ Test completed\n";
