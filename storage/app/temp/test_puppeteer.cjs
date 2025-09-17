const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
  let browser;
  try {
    console.log('🚀 Starting Puppeteer for: https://www.comune.palmanova.ud.it/it/vivere-il-comune-179025/eventi-179027/big-one-european-pink-floyd-show-29442');
    
    browser = await puppeteer.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage'
      ]
    });
    
    const page = await browser.newPage();
    await page.goto('https://www.comune.palmanova.ud.it/it/vivere-il-comune-179025/eventi-179027/big-one-european-pink-floyd-show-29442', { waitUntil: 'networkidle0', timeout: 60000 });
    
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
    fs.writeFileSync('C:\laragon\www\ChatbotPlatform\backend/../storage/app/temp/test_output.html', content, 'utf8');
    
    console.log('✅ Content extracted successfully');
    
  } catch (error) {
    console.error('❌ Error:', error.message);
    fs.writeFileSync('C:\laragon\www\ChatbotPlatform\backend/../storage/app/temp/test_error.log', error.stack, 'utf8');
    process.exit(1);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();