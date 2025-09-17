<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Scraper\JavaScriptRenderer;

// Test specifico per il sito di Palmanova
$url = 'https://www.comune.palmanova.ud.it/it/novita-179021/notizie-179022/attivazione-del-servizio-pedibus-311002';

echo "🧪 Testing Palmanova specific URL with enhanced rendering...\n";
echo "URL: $url\n\n";

$startTime = microtime(true);

try {
    $renderer = new JavaScriptRenderer();
    $content = $renderer->renderUrl($url, 90); // 90 seconds timeout
    
    if ($content) {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        echo "✅ SUCCESS!\n";
        echo "Duration: {$duration}ms\n";
        echo "Content length: " . strlen($content) . "\n";
        
        // Analyze content quality
        $textLength = strlen(strip_tags($content));
        $hasJsWarning = strpos($content, 'Please enable JavaScript') !== false;
        $hasMainContent = strpos($content, 'Pedibus') !== false;
        $hasAngularElements = strpos($content, 'ng-') !== false || strpos($content, 'angular') !== false;
        
        echo "Text length: {$textLength}\n";
        echo "Has JS warning: " . ($hasJsWarning ? 'YES ❌' : 'NO ✅') . "\n";
        echo "Has Pedibus content: " . ($hasMainContent ? 'YES ✅' : 'NO ❌') . "\n";
        echo "Has Angular elements: " . ($hasAngularElements ? 'YES' : 'NO') . "\n\n";
        
        // Extract meaningful text sample
        $text = strip_tags($content);
        $cleanText = preg_replace('/\s+/', ' ', $text);
        echo "Content preview:\n";
        echo str_repeat('-', 50) . "\n";
        echo substr($cleanText, 0, 500) . "...\n";
        echo str_repeat('-', 50) . "\n";
        
        // Save full content for inspection
        file_put_contents(__DIR__ . '/storage/app/temp/palmanova_test_output.html', $content);
        echo "\n📁 Full content saved to: storage/app/temp/palmanova_test_output.html\n";
        
    } else {
        echo "❌ FAILED: No content returned\n";
    }
    
} catch (Exception $e) {
    echo "💥 ERROR: " . $e->getMessage() . "\n";
}


