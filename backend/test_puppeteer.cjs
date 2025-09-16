const puppeteer = require('puppeteer');

(async () => {
  let browser;
  try {
    console.log('🚀 Starting Puppeteer test...');
    
    browser = await puppeteer.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-extensions',
        '--disable-gpu'
      ]
    });
    
    const page = await browser.newPage();
    
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    await page.setViewport({ width: 1280, height: 720 });
    
    const testUrl = 'https://www.comune.palmanova.ud.it/it/novita-179021/notizie-179022/attivazione-del-servizio-pedibus-311002';
    
    console.log('📄 Navigating to:', testUrl);
    await page.goto(testUrl, { 
      waitUntil: 'networkidle0', 
      timeout: 60000 
    });
    
    // Wait for Angular to load
    console.log('🏛️ Palmanova site - applying enhanced waiting strategy...');
    
    // Try to close any browser warnings
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
    
    // Scroll to trigger lazy loading
    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight / 2);
    });
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    console.log('⏳ Waiting for main content...');
    
    // Enhanced waiting strategy
    try {
      await page.waitForFunction(() => {
        const body = document.querySelector('body');
        const hasMainContent = body && body.textContent.length > 1000;
        const noLoadingIndicators = !body.textContent.includes('Loading') && 
                                   !body.textContent.includes('Caricamento') &&
                                   !body.textContent.includes('Please enable JavaScript');
        
        console.log('Content check:', {
          contentLength: body ? body.textContent.length : 0,
          hasMainContent,
          noLoadingIndicators,
          readyState: document.readyState
        });
        
        return hasMainContent && noLoadingIndicators && document.readyState === 'complete';
      }, { timeout: 25000 });
      
      console.log('✅ Main content loaded successfully');
    } catch (e) {
      console.log('⚠️ Timeout waiting for main content, trying selectors...');
      
      try {
        await page.waitForSelector('main, article, .content, [role="main"]', { timeout: 10000 });
        console.log('✅ Found main content selector');
      } catch (e2) {
        console.log('⚠️ No content selectors found, proceeding anyway...');
      }
    }
    
    // Additional wait for lazy loading
    console.log('⏳ Waiting for lazy loading...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    const content = await page.content();
    const bodyText = await page.evaluate(() => document.querySelector('body').textContent);
    
    console.log('✅ Content extracted');
    console.log('📊 Stats:');
    console.log('- HTML length:', content.length);
    console.log('- Body text length:', bodyText.length);
    console.log('- Contains JS warning:', bodyText.includes('Please enable JavaScript'));
    console.log('- Sample text:', bodyText.substring(0, 200).replace(/\s+/g, ' '));
    
    // Look for specific Pedibus content
    const hasPedibus = bodyText.toLowerCase().includes('pedibus');
    const hasServizio = bodyText.toLowerCase().includes('servizio');
    const hasAttivazione = bodyText.toLowerCase().includes('attivazione');
    
    console.log('🔍 Content analysis:');
    console.log('- Contains "pedibus":', hasPedibus ? '✅' : '❌');
    console.log('- Contains "servizio":', hasServizio ? '✅' : '❌');
    console.log('- Contains "attivazione":', hasAttivazione ? '✅' : '❌');
    
    if (hasPedibus) {
      // Find and show the Pedibus section
      const lowerText = bodyText.toLowerCase();
      const pedibusIndex = lowerText.indexOf('pedibus');
      const start = Math.max(0, pedibusIndex - 100);
      const end = Math.min(bodyText.length, pedibusIndex + 500);
      
      console.log('\n📄 Pedibus content found:');
      console.log('='.repeat(60));
      console.log(bodyText.substring(start, end));
      console.log('='.repeat(60));
    }
    
  } catch (error) {
    console.error('❌ Error:', error.message);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
