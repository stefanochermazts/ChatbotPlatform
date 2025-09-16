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
    
    const testUrl = 'https://www.comune.palmanova.ud.it/it/vivere-il-comune-179025/eventi-179027/big-one-european-pink-floyd-show-29442';
    
    console.log('📄 Navigating to:', testUrl);
    await page.goto(testUrl, { 
      waitUntil: 'networkidle0', 
      timeout: 60000 
    });
    
    // Wait for Angular to load
    console.log('⏳ Waiting for Angular...');
    try {
      await page.waitForFunction(() => {
        return (
          !document.querySelector('body').textContent.includes('Please enable JavaScript') &&
          !document.querySelector('body').textContent.includes('JavaScript to continue') &&
          document.readyState === 'complete'
        );
      }, { timeout: 15000 });
    } catch (e) {
      console.log('⚠️ Timeout waiting for Angular, proceeding...');
    }
    
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    const content = await page.content();
    const bodyText = await page.evaluate(() => document.querySelector('body').textContent);
    
    console.log('✅ Content extracted');
    console.log('📊 Stats:');
    console.log('- HTML length:', content.length);
    console.log('- Body text length:', bodyText.length);
    console.log('- Contains JS warning:', bodyText.includes('Please enable JavaScript'));
    console.log('- Sample text:', bodyText.substring(0, 200).replace(/\s+/g, ' '));
    
  } catch (error) {
    console.error('❌ Error:', error.message);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
