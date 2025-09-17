const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
  let browser;
  try {
    console.log('🚀 Starting simple test...');
    
    browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    await page.goto('https://example.com', { waitUntil: 'domcontentloaded' });
    
    const content = await page.content();
    console.log('✅ Content length:', content.length);
    
    // Save to file
    const outputPath = '../storage/app/temp/test_output.html';
    fs.writeFileSync(outputPath, content, 'utf8');
    console.log('📁 Saved to:', outputPath);
    
  } catch (error) {
    console.error('❌ Error:', error.message);
    process.exit(1);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();


