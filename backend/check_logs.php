<?php

$logFile = __DIR__ . '/storage/logs/laravel.log';

if (!file_exists($logFile)) {
    echo "❌ Log file not found: {$logFile}" . PHP_EOL;
    exit(1);
}

echo "=== CHECKING LARAVEL LOGS ===" . PHP_EOL . PHP_EOL;

// Read last 200 lines
$lines = file($logFile);
$lastLines = array_slice($lines, -200);

$queryNormalizedLines = [];
$retrieveLines = [];
$bm25Lines = [];

foreach ($lastLines as $line) {
    if (stripos($line, 'query normalized') !== false) {
        $queryNormalizedLines[] = $line;
    }
    if (stripos($line, '[RETRIEVE]') !== false) {
        $retrieveLines[] = $line;
    }
    if (stripos($line, 'bm25') !== false || stripos($line, 'BM25') !== false) {
        $bm25Lines[] = $line;
    }
}

echo "📊 QUERY NORMALIZATION LOGS:" . PHP_EOL;
if (empty($queryNormalizedLines)) {
    echo "  ⚠️  NO logs found for 'query normalized'" . PHP_EOL;
    echo "  This means either:" . PHP_EOL;
    echo "    1. Query was not different after normalization/expansion" . PHP_EOL;
    echo "    2. Debug mode not enabled" . PHP_EOL;
    echo "    3. Synonym expansion not working" . PHP_EOL;
} else {
    foreach ($queryNormalizedLines as $line) {
        echo "  " . trim($line) . PHP_EOL;
    }
}
echo PHP_EOL;

echo "📊 RETRIEVE LOGS (last 5):" . PHP_EOL;
if (empty($retrieveLines)) {
    echo "  ⚠️  NO retrieve logs found" . PHP_EOL;
} else {
    foreach (array_slice($retrieveLines, -5) as $line) {
        echo "  " . trim($line) . PHP_EOL;
    }
}
echo PHP_EOL;

echo "📊 BM25 LOGS (last 5):" . PHP_EOL;
if (empty($bm25Lines)) {
    echo "  ⚠️  NO BM25 logs found" . PHP_EOL;
} else {
    foreach (array_slice($bm25Lines, -5) as $line) {
        echo "  " . trim($line) . PHP_EOL;
    }
}
echo PHP_EOL;

