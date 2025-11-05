<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RAG\KbSearchService;
use Illuminate\Support\Facades\Cache;

$tenantId = 5;
$query = 'orario comando polizia locale';

echo "🔍 Debug Citation Keys\n\n";

Cache::flush();

$kbSearch = app(KbSearchService::class);
$result = $kbSearch->retrieve($tenantId, $query, false);

if (!empty($result['citations'])) {
    $first = $result['citations'][0];
    echo "First Citation Keys:\n";
    foreach (array_keys($first) as $key) {
        echo "  - {$key}: " . json_encode($first[$key]) . "\n";
    }
    
    echo "\n\nFirst 3 citations document IDs:\n";
    foreach (array_slice($result['citations'], 0, 3) as $idx => $cit) {
        echo "  {$idx}. document_id=" . ($cit['document_id'] ?? 'MISSING') . 
             ", id=" . ($cit['id'] ?? 'MISSING') . 
             ", chunk_index=" . ($cit['chunk_index'] ?? 'MISSING') . "\n";
    }
}

