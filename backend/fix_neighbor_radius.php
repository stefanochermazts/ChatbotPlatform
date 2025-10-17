<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

echo "=== INCREASE NEIGHBOR_RADIUS FOR TENANT 5 ===" . PHP_EOL . PHP_EOL;

$tenant = Tenant::find(5);

if (!$tenant) {
    echo "❌ Tenant 5 not found!" . PHP_EOL;
    exit(1);
}

$ragSettings = is_string($tenant->rag_settings) 
    ? json_decode($tenant->rag_settings, true) 
    : $tenant->rag_settings;

echo "BEFORE:" . PHP_EOL;
echo "  neighbor_radius: " . ($ragSettings['hybrid']['neighbor_radius'] ?? 'NOT SET') . PHP_EOL;
echo PHP_EOL;

// Increase neighbor_radius to include more context
if (!isset($ragSettings['hybrid'])) {
    $ragSettings['hybrid'] = [];
}
$ragSettings['hybrid']['neighbor_radius'] = 2;  // was: 1

$tenant->rag_settings = json_encode($ragSettings, JSON_PRETTY_PRINT);
$tenant->save();

// Clear cache
\Illuminate\Support\Facades\Cache::forget("rag_config_tenant_5");
\Illuminate\Support\Facades\Artisan::call('cache:clear');

echo "AFTER:" . PHP_EOL;
echo "  neighbor_radius: 2 (include ±2 chunks around selected)" . PHP_EOL;
echo PHP_EOL;

echo "✅ Neighbor radius increased for tenant 5!" . PHP_EOL;
echo "   When selecting chunk #2, it will now include:" . PHP_EOL;
echo "   - Chunk #0 (if exists)" . PHP_EOL;
echo "   - Chunk #1 ← COMANDO POLIZIA LOCALE label!" . PHP_EOL;
echo "   - Chunk #2 (selected)" . PHP_EOL;
echo "   - Chunk #3 (if exists)" . PHP_EOL;
echo "   - Chunk #4 (if exists)" . PHP_EOL;
echo PHP_EOL;
echo "🧪 TEST: php backend/test_full_debug.php" . PHP_EOL;

