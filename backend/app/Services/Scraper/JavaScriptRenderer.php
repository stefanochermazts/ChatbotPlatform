<?php

namespace App\Services\Scraper;

class JavaScriptRenderer
{
    public function renderUrl(string $url, int $timeout = 60): ?string
    {
        try {
            $timeoutMs = $timeout * 1000;
            
            // Path temporaneo per script Node.js
            $tempDir = storage_path('app/temp');
            $scriptPath = $tempDir . '/puppeteer_' . uniqid() . '.cjs';
            $outputPath = $tempDir . '/output_' . uniqid() . '.html';
            $errorPath = $tempDir . '/error_' . uniqid() . '.log';
            
            // Crea directory se non esiste
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Genera script Puppeteer
            $script = $this->generatePuppeteerScript($url, $timeoutMs, $outputPath, $errorPath);
            file_put_contents($scriptPath, $script);
            
            // Esegui Puppeteer dalla directory corrente (backend)
            $backendDir = base_path(); // Già punta a /backend
            $absoluteScriptPath = $scriptPath;
            
            // Comando bash-friendly (Git Bash su Windows)
            $nodeCmd = "cd \"$backendDir\" && node \"$absoluteScriptPath\"";
            
            // Debug: log del comando
            \Log::debug("Executing command: $nodeCmd", [
                'backend_dir' => $backendDir,
                'script_path' => $absoluteScriptPath
            ]);
            $exitCode = 0;
            $output = [];
            
            exec($nodeCmd . " 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                $errorMsg = implode("\n", $output);
                if (file_exists($errorPath)) {
                    $errorMsg .= "\n" . file_get_contents($errorPath);
                }
                \Log::error("❌ [JS-RENDER] Puppeteer failed", [
                    'url' => $url,
                    'exit_code' => $exitCode,
                    'error' => $errorMsg,
                    'command' => $nodeCmd,
                    'output' => $output
                ]);
                
                // Cleanup
                @unlink($scriptPath);
                @unlink($outputPath);
                @unlink($errorPath);
                
                return null;
            }
            
            // Leggi contenuto renderizzato
            if (!file_exists($outputPath)) {
                \Log::error("❌ [JS-RENDER] Output file not found", ['url' => $url]);
                
                // Cleanup
                @unlink($scriptPath);
                @unlink($errorPath);
                
                return null;
            }
            
            $content = file_get_contents($outputPath);
            
            // Cleanup file temporanei
            @unlink($scriptPath);
            @unlink($outputPath);
            @unlink($errorPath);
            
            \Log::info("✅ [JS-RENDER] Content extracted successfully", [
                'url' => $url,
                'content_length' => strlen($content)
            ]);
            
            return $content;
            
        } catch (\Exception $e) {
            \Log::error("💥 [JS-RENDER] Exception during JavaScript rendering", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    private function generatePuppeteerScript(string $url, int $timeout, string $outputPath, string $errorPath): string
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        
        // Fix path per Windows - converte backslash in forward slash per JavaScript
        $outputPath = str_replace('\\', '/', $outputPath);
        $errorPath = str_replace('\\', '/', $errorPath);
        
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
        '--disable-dev-shm-usage',
        '--disable-extensions',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-default-apps'
      ],
      timeout: $timeout
    });
    
    const page = await browser.newPage();
    
    // Set user agent
    await page.setUserAgent('$userAgent');
    
    // Imposta viewport per consistenza
    await page.setViewport({ width: 1280, height: 720 });
    
    // Naviga alla pagina con timeout
    console.log('📄 Navigating to page...');
    await page.goto('$url', { 
      waitUntil: 'networkidle0', 
      timeout: $timeout 
    });
    
    // Attendi che Angular/SPA sia completamente caricato
    console.log('⏳ Waiting for JavaScript rendering...');
    
    // Attendi elementi comuni di Angular
    try {
      await page.waitForFunction(() => {
        // Verifica che Angular sia caricato
        return (
          !document.querySelector('body').textContent.includes('Please enable JavaScript') &&
          !document.querySelector('body').textContent.includes('JavaScript to continue') &&
          document.readyState === 'complete'
        );
      }, { timeout: 15000 });
    } catch (e) {
      console.log('⚠️ Timeout waiting for Angular, proceeding anyway...');
    }
    
    // Attendi ulteriori 2 secondi per eventuali chiamate AJAX
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    // Estrai contenuto HTML completo
    console.log('📝 Extracting content...');
    const content = await page.content();
    
    // Salva contenuto
    fs.writeFileSync('$outputPath', content, 'utf8');
    
    console.log('✅ Content extracted successfully');
    
  } catch (error) {
    console.error('❌ Puppeteer error:', error.message);
    fs.writeFileSync('$errorPath', error.stack || error.message, 'utf8');
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
