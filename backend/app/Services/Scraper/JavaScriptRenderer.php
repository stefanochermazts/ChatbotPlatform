<?php

namespace App\Services\Scraper;

class JavaScriptRenderer
{
    public function renderUrl(string $url, int $timeout = 60): ?string
    {
        try {
            $timeoutMs = $timeout * 1000;
            
            \Log::info("🌐 [JS-RENDER] Starting JavaScript rendering", [
                'url' => $url,
                'timeout_seconds' => $timeout,
                'timeout_ms' => $timeoutMs,
                'environment' => app()->environment()
            ]);
            
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

            // Risolvi percorso Node.js in modo robusto (Windows/Linux)
            $envNodePath = env('NODE_BINARY_PATH');
            $candidateNodes = [];
            if ($envNodePath) {
                $candidateNodes[] = $envNodePath;
            }
            // Aggiungi path comuni
            $candidateNodes = array_merge($candidateNodes, [
                'node',
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
                'C:\\Program Files\\Git\\usr\\bin\\node.exe',
                '/usr/bin/node',
                '/usr/local/bin/node'
            ]);

            $nodeBinary = 'node';
            foreach ($candidateNodes as $candidate) {
                $versionOutput = null;
                $exitCodeProbe = 0;
                @exec("\"$candidate\" --version 2>&1", $versionOutput, $exitCodeProbe);
                if ($exitCodeProbe === 0 && !empty($versionOutput)) {
                    $nodeBinary = $candidate;
                    break;
                }
            }

            // Comando bash-friendly (Git Bash su Windows) con path esplicito
            $nodeCmd = "cd \"$backendDir\" && \"$nodeBinary\" \"$absoluteScriptPath\"";
            
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
            
            // Analisi contenuto per debug
            $contentLength = strlen($content);
            $textContent = strip_tags($content);
            $textLength = strlen($textContent);
            $hasJsWarning = strpos($content, 'Please enable JavaScript') !== false;
            $hasPedibus = stripos($textContent, 'pedibus') !== false;
            
            \Log::info("🌐 [JS-RENDER] Content analysis", [
                'url' => $url,
                'content_length' => $contentLength,
                'text_length' => $textLength,
                'has_js_warning' => $hasJsWarning,
                'has_pedibus' => $hasPedibus,
                'text_preview' => substr(trim(preg_replace('/\s+/', ' ', $textContent)), 0, 200)
            ]);
            
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
    
    // Generic handling for modern websites
    console.log('🌐 Applying generic website handling...');
    
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
    
    // Generic handling for homepage navigation
    if ('$url'.endsWith('/') || '$url'.split('/').length <= 4) {
      console.log('🏠 Homepage detected - waiting for navigation menu...');
      
      // Wait longer for Angular router to load navigation
      await new Promise(resolve => setTimeout(resolve, 5000));
      
      // Try to trigger menu/navigation loading
      try {
        await page.evaluate(() => {
          // Look for hamburger menu or navigation triggers
          const menuTriggers = document.querySelectorAll('button[aria-label*="menu"], .menu-toggle, .nav-toggle, [role="button"]');
          menuTriggers.forEach(trigger => trigger.click());
          
          // Scroll to trigger lazy loading
          window.scrollTo(0, document.body.scrollHeight / 4);
          window.scrollTo(0, document.body.scrollHeight / 2);
          window.scrollTo(0, 0);
        });
        console.log('🎯 Triggered navigation interactions');
        await new Promise(resolve => setTimeout(resolve, 3000));
      } catch (e) {
        console.log('ℹ️ Navigation interaction failed:', e.message);
      }
    }
    
    // Try to trigger content loading by scrolling (for all sites)
    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight / 2);
    });
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    // Attendi che Angular/SPA sia completamente caricato
    console.log('⏳ Waiting for JavaScript rendering...');
    
    // Strategy 1: Wait for actual content, not just page load
    let contentFound = false;
    try {
      await page.waitForFunction(() => {
        const body = document.querySelector('body');
        if (!body) return false;
        
        const bodyText = body.textContent || '';
        
        // Check for meaningful content indicators (not just JavaScript)
        const hasRealContent = bodyText.length > 8000; // Much more content needed for complex sites
        const hasSpecificContent = bodyText.toLowerCase().includes('pedibus') ||
                                  bodyText.toLowerCase().includes('attivazione') ||
                                  bodyText.toLowerCase().includes('servizio') ||
                                  bodyText.toLowerCase().includes('comune') ||
                                  bodyText.toLowerCase().includes('content');
        
        // More aggressive JavaScript detection
        const jsCodeRatio = (bodyText.match(/function|var |const |let |if\s*\(|\.prototype\.|addEventListener|querySelector/g) || []).length;
        const hasLowJsRatio = jsCodeRatio < 20; // Much stricter JS threshold
        
        // Check for typical content indicators
        const hasContentIndicators = bodyText.includes('pubblicato') ||
                                    bodyText.includes('notizie') ||
                                    bodyText.includes('data') ||
                                    bodyText.includes('informazioni') ||
                                    bodyText.length > 10000;
        
        // Check for navigation/content structure
        const hasNavigation = document.querySelector('nav, .nav, .menu') !== null;
        const hasMainContent = document.querySelector('main, article, .content, .text') !== null;
        
        const result = (hasRealContent || hasContentIndicators) && hasLowJsRatio && (hasSpecificContent || hasNavigation || hasMainContent);
        
        console.log('Enhanced content check:', {
          contentLength: bodyText.length,
          hasRealContent,
          hasSpecificContent,
          hasContentIndicators,
          jsCodeCount: jsCodeRatio,
          hasLowJsRatio,
          hasNavigation,
          hasMainContent,
          readyState: document.readyState,
          finalResult: result
        });
        
        return result;
      }, { timeout: 60000 }); // Much longer timeout for complex Angular sites
      
      contentFound = true;
      console.log('✅ Real Angular content loaded successfully');
    } catch (e) {
      console.log('⚠️ Timeout waiting for real content, trying specific selectors...');
      
      // Strategy 2: Wait for specific content indicators
      try {
        // Try multiple strategies in sequence
        await page.waitForFunction(() => {
          const textContent = document.body.textContent || '';
          return textContent.toLowerCase().includes('pedibus') && 
                 textContent.toLowerCase().includes('attivazione') &&
                 textContent.length > 2000;
        }, { timeout: 30000 });
        
        contentFound = true;
        console.log('✅ Found specific Pedibus content');
      } catch (e2) {
        console.log('⚠️ Specific content not found, trying selectors...');
        
        try {
          await page.waitForSelector('main, article, .content, [role="main"], .post, .news', { timeout: 20000 });
          contentFound = true;
          console.log('✅ Found content container selector');
        } catch (e3) {
          console.log('⚠️ No content containers found, proceeding anyway...');
        }
      }
    }
    
    // If content not found with normal waiting, try interaction
    if (!contentFound) {
      console.log('🔄 Trying to trigger content loading with interactions...');
      
      // Try aggressive scrolling and interaction to trigger Angular content loading
      console.log('🔄 Aggressive content loading strategy...');
      
      await page.evaluate(() => {
        window.scrollTo(0, 0);
      });
      await new Promise(resolve => setTimeout(resolve, 2000));
      
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight / 4);
      });
      await new Promise(resolve => setTimeout(resolve, 2000));
      
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight / 2);
      });
      await new Promise(resolve => setTimeout(resolve, 3000));
      
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight * 3/4);
      });
      await new Promise(resolve => setTimeout(resolve, 2000));
      
      await page.evaluate(() => {
        window.scrollTo(0, document.body.scrollHeight);
      });
      await new Promise(resolve => setTimeout(resolve, 3000));
      
      // Try to trigger any lazy loading by clicking potential expanders
      await page.evaluate(() => {
        const clickableElements = document.querySelectorAll('button, [role="button"], .btn, .load-more, .expand');
        clickableElements.forEach((el, index) => {
          if (index < 3) { // Only click first 3 to avoid too much interaction
            try {
              el.click();
            } catch (e) {
              // Ignore click errors
            }
          }
        });
      });
      await new Promise(resolve => setTimeout(resolve, 5000));
      
      // Try clicking on potential navigation elements
      try {
        const navElements = await page.$$('a, button, .nav-item, .menu-item');
        if (navElements.length > 0) {
          console.log('Found ' + navElements.length + ' navigation elements');
        }
      } catch (e) {
        console.log('No navigation elements found');
      }
    }
    
    // Final wait for any remaining async content (much longer for Angular)
    console.log('⏳ Final wait for content stabilization...');
    await new Promise(resolve => setTimeout(resolve, contentFound ? 8000 : 15000));
    
    // Estrai contenuto HTML completo con pulizia selettiva
    console.log('📝 Extracting content...');
    
    // Try to extract meaningful content first
    let cleanedContent = null;
    try {
      cleanedContent = await page.evaluate(() => {
        // Remove script tags and their content
        const scripts = document.querySelectorAll('script');
        scripts.forEach(script => script.remove());
        
        // Remove style tags
        const styles = document.querySelectorAll('style');
        styles.forEach(style => style.remove());
        
        // Try to find main content containers (expanded for complex sites)
        const selectors = [
          'main',
          'article', 
          '.content',
          '.main-content',
          '.post-content',
          '.article-content',
          '[role="main"]',
          '.news-content',
          '.page-content',
          '#content',
          '#main-content', 
          '.site-content',
          '.entry-content',
          '.contenuto',
          '.testo',
          '.body-content'
        ];
        
        let mainContent = null;
        for (const selector of selectors) {
          const element = document.querySelector(selector);
          if (element && element.textContent.trim().length > 500) {
            mainContent = element;
            break;
          }
        }
        
        if (mainContent) {
          console.log('Found main content container:', mainContent.tagName);
          return mainContent.outerHTML;
        } else {
          // Try less restrictive content detection
          console.log('No main content container found, trying less restrictive approach');
          
          // Remove only scripts, but keep more content
          const bodyClone = document.body.cloneNode(true);
          
          // Remove navigation, header, footer that are typically not content
          const elementsToRemove = bodyClone.querySelectorAll('nav, header, footer, .nav, .navbar, .menu, .sidebar, #sidebar, .header, .footer');
          elementsToRemove.forEach(el => el.remove());
          
          // If body still has reasonable content, return it
          if (bodyClone.textContent.trim().length > 300) {
            console.log('Using body with navigation removed');
            return bodyClone.outerHTML;
          } else {
            // Last resort: return full body
            console.log('Using full cleaned body as last resort');
            return document.body.outerHTML;
          }
        }
      });
      
      if (cleanedContent && !cleanedContent.includes('var global = window')) {
        console.log('✅ Using cleaned content extraction');
        // Save cleaned content
        fs.writeFileSync('$outputPath', cleanedContent, 'utf8');
        console.log('✅ Cleaned content extracted successfully');
        return;
      }
    } catch (e) {
      console.log('⚠️ Cleaned extraction failed:', e.message);
    }
    
    // Fallback to full page content
    console.log('📄 Using full page content');
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
