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
    
    // Special handling for problematic sites
    if ('$url'.includes('comune.palmanova.ud.it')) {
      console.log('🏛️ Palmanova site detected - applying special handling...');
      
      // Click away browser warning if present
      try {
        const browserWarning = await page.$('button, .close, [onclick*="close"]');
        if (browserWarning) {
          await browserWarning.click();
          console.log('✅ Closed browser warning');
          await new Promise(resolve => setTimeout(resolve, 1000));
        }
      } catch (e) {
        console.log('ℹ️ No browser warning to close');
      }
      
      // Try to trigger content loading by scrolling
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight / 2);
      });
      await new Promise(resolve => setTimeout(resolve, 2000));
    }
    
    // Attendi che Angular/SPA sia completamente caricato
    console.log('⏳ Waiting for JavaScript rendering...');
    
    // Strategy 1: Wait for main content to appear
    try {
      await page.waitForFunction(() => {
        const body = document.querySelector('body');
        const hasMainContent = body && body.textContent.length > 1000; // Sufficient content
        const noLoadingIndicators = !body.textContent.includes('Loading') && 
                                   !body.textContent.includes('Caricamento') &&
                                   !body.textContent.includes('Please enable JavaScript') &&
                                   !body.textContent.includes('JavaScript to continue');
        
        console.log('Content check:', {
          contentLength: body ? body.textContent.length : 0,
          hasMainContent,
          noLoadingIndicators,
          readyState: document.readyState
        });
        
        return hasMainContent && noLoadingIndicators && document.readyState === 'complete';
      }, { timeout: 25000 });
      
      console.log('✅ Angular content loaded successfully');
    } catch (e) {
      console.log('⚠️ Timeout waiting for main content, trying fallback...');
      
      // Strategy 2: Wait for specific Angular elements
      try {
        await page.waitForSelector('main, article, .content, .main-content, [role="main"]', { timeout: 10000 });
        console.log('✅ Found main content selector');
      } catch (e2) {
        console.log('⚠️ No main content selectors found, proceeding with current state...');
      }
    }
    
    // Attendi ulteriori 5 secondi per lazy loading e AJAX calls
    console.log('⏳ Waiting for lazy loading...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    
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
