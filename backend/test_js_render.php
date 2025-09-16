<?php

require_once __DIR__ . '/vendor/autoload.php';

class SimpleJSRenderer 
{
    public function fetchUrlWithJS(string $url): ?string
    {
        try {
            $timeout = 60000; // 60 secondi
            
            // Path temporaneo per script Node.js
            $tempDir = __DIR__ . '/../storage/app/temp';
            $scriptPath = $tempDir . '/test_puppeteer.cjs';
            $outputPath = $tempDir . '/test_output.html';
            $errorPath = $tempDir . '/test_error.log';
            
            // Crea directory se non esiste
            if (!file_exists(dirname($scriptPath))) {
                mkdir(dirname($scriptPath), 0755, true);
            }
            
            // Genera script Puppeteer
            $script = $this->generateScript($url, $timeout, $outputPath, $errorPath);
            file_put_contents($scriptPath, $script);
            
            // Esegui Puppeteer
            $nodeCmd = "cd " . __DIR__ . " && node \"$scriptPath\"";
            $exitCode = 0;
            $output = [];
            
            exec($nodeCmd . " 2>&1", $output, $exitCode);
            
            echo "Exit code: $exitCode\n";
            echo "Output: " . implode("\n", $output) . "\n";
            
            if ($exitCode !== 0) {
                echo "Error executing Puppeteer\n";
                return null;
            }
            
            // Leggi contenuto
            if (!file_exists($outputPath)) {
                echo "Output file not found\n";
                return null;
            }
            
            $content = file_get_contents($outputPath);
            
            // Cleanup
            @unlink($scriptPath);
            @unlink($outputPath);
            @unlink($errorPath);
            
            return $content;
            
        } catch (Exception $e) {
            echo "Exception: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    private function generateScript(string $url, int $timeout, string $outputPath, string $errorPath): string
    {
        return <<<JS
const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
  let browser;
  try {
    console.log('🚀 Starting Puppeteer for: $url');
    
    browser = await puppeteer.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage'
      ]
    });
    
    const page = await browser.newPage();
    await page.goto('$url', { waitUntil: 'networkidle0', timeout: $timeout });
    
    // Wait for Angular
    try {
      await page.waitForFunction(() => {
        return !document.querySelector('body').textContent.includes('Please enable JavaScript');
      }, { timeout: 15000 });
    } catch (e) {
      console.log('Timeout waiting for JS, proceeding...');
    }
    
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    const content = await page.content();
    fs.writeFileSync('$outputPath', content, 'utf8');
    
    console.log('✅ Content extracted successfully');
    
  } catch (error) {
    console.error('❌ Error:', error.message);
    fs.writeFileSync('$errorPath', error.stack, 'utf8');
    process.exit(1);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
JS;
    }
}

// Test
$renderer = new SimpleJSRenderer();
$testUrl = 'https://www.comune.palmanova.ud.it/it/vivere-il-comune-179025/eventi-179027/big-one-european-pink-floyd-show-29442';

echo "Testing JavaScript rendering...\n";
echo "URL: $testUrl\n\n";

$content = $renderer->fetchUrlWithJS($testUrl);

if ($content) {
    $textContent = strip_tags($content);
    echo "✅ SUCCESS!\n";
    echo "Content length: " . strlen($content) . " characters\n";
    echo "Text length: " . strlen($textContent) . " characters\n";
    echo "Contains JS warning: " . (strpos($textContent, 'Please enable JavaScript') !== false ? 'YES' : 'NO') . "\n";
    echo "Sample text: " . substr($textContent, 0, 200) . "...\n";
} else {
    echo "❌ FAILED\n";
}
