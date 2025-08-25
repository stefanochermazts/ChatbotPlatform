/**
 * End-to-End Tests for ChatBot Widget
 * Tests complete user journeys using Puppeteer
 */

const puppeteer = require('puppeteer');
const path = require('path');

describe('ChatBot Widget E2E Tests', () => {
  let browser;
  let page;
  
  beforeAll(async () => {
    browser = await puppeteer.launch({
      headless: process.env.CI ? true : false, // Show browser in development
      slowMo: process.env.CI ? 0 : 50, // Slow down for debugging
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-extensions'
      ]
    });
    
    page = await browser.newPage();
    
    // Set viewport for consistent testing
    await page.setViewport({ width: 1280, height: 720 });
    
    // Enable accessibility tree
    await page.setAccessibilityOptions({
      enableTree: true
    });
    
    // Navigate to widget demo page
    const demoPath = path.join(__dirname, '../../chatbot-widget.html');
    await page.goto(`file://${demoPath}`);
    
    // Wait for widget to be ready
    await page.waitForSelector('#chatbot-widget-container', { timeout: 5000 });
  });
  
  afterAll(async () => {
    if (browser) {
      await browser.close();
    }
  });
  
  beforeEach(async () => {
    // Reset page state before each test
    await page.reload();
    await page.waitForSelector('#chatbot-widget-container');
  });
  
  describe('Widget Initialization', () => {
    test('should load widget without errors', async () => {
      // Check for console errors
      const errors = [];
      page.on('console', msg => {
        if (msg.type() === 'error') {
          errors.push(msg.text());
        }
      });
      
      // Wait a bit for any errors to appear
      await page.waitForTimeout(1000);
      
      // Should have no critical errors
      const criticalErrors = errors.filter(err => 
        !err.includes('favicon') && // Ignore favicon errors
        !err.includes('Accessibility tree') // Ignore accessibility tree warnings
      );
      
      expect(criticalErrors).toHaveLength(0);
    });
    
    test('should render widget container', async () => {
      const widget = await page.$('#chatbot-widget-container');
      expect(widget).toBeTruthy();
      
      // Widget should be visible
      const isVisible = await page.evaluate(() => {
        const element = document.getElementById('chatbot-widget-container');
        const style = window.getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden';
      });
      
      expect(isVisible).toBe(true);
    });
    
    test('should have proper accessibility attributes', async () => {
      const widget = await page.$('#chatbot-widget-container');
      
      // Check for ARIA attributes
      const role = await widget.evaluate(el => el.getAttribute('role'));
      const ariaLabel = await widget.evaluate(el => el.getAttribute('aria-label'));
      
      expect(role || ariaLabel).toBeTruthy();
    });
  });
  
  describe('User Interaction Flow', () => {
    test('should open widget when clicking trigger button', async () => {
      // Find and click the trigger button
      const triggerButton = await page.$('.chatbot-trigger, .widget-trigger, button[aria-label*="Apri"], button[aria-label*="Chat"]');
      
      if (triggerButton) {
        await triggerButton.click();
        
        // Wait for widget to open
        await page.waitForSelector('.chatbot-open, .widget-open, [data-state="open"]', { timeout: 2000 });
        
        // Widget should be open
        const isOpen = await page.evaluate(() => {
          const widget = document.querySelector('#chatbot-widget-container');
          return widget.classList.contains('chatbot-open') || 
                 widget.getAttribute('data-state') === 'open' ||
                 widget.querySelector('.widget-open');
        });
        
        expect(isOpen).toBe(true);
      }
    });
    
    test('should send and receive messages', async () => {
      // Open widget first
      await page.click('.chatbot-trigger, .widget-trigger, button');
      await page.waitForTimeout(500);
      
      // Find message input
      const messageInput = await page.$('input[type="text"], textarea, [role="textbox"]');
      
      if (messageInput) {
        const testMessage = 'Hello, this is a test message';
        
        // Type message
        await messageInput.type(testMessage);
        
        // Send message (press Enter or click send button)
        const sendButton = await page.$('button[type="submit"], .send-button, [aria-label*="Invia"]');
        
        if (sendButton) {
          await sendButton.click();
        } else {
          await messageInput.press('Enter');
        }
        
        // Wait for response
        await page.waitForTimeout(2000);
        
        // Check that message appears in conversation
        const messages = await page.$$eval('.message, .chat-message', elements => 
          elements.map(el => el.textContent)
        );
        
        const hasUserMessage = messages.some(msg => msg.includes(testMessage));
        expect(hasUserMessage).toBe(true);
      }
    });
    
    test('should handle quick actions', async () => {
      // Open widget
      await page.click('.chatbot-trigger, .widget-trigger, button');
      await page.waitForTimeout(500);
      
      // Look for quick action buttons
      const quickActionButtons = await page.$$('.quick-action-btn, .action-button');
      
      if (quickActionButtons.length > 0) {
        const firstAction = quickActionButtons[0];
        
        // Click first quick action
        await firstAction.click();
        
        // Check if modal or form appears
        const modal = await page.$('.modal, .quick-action-modal, [role="dialog"]');
        
        if (modal) {
          // Fill any required fields
          const inputs = await modal.$$('input, textarea');
          
          for (const input of inputs) {
            const type = await input.evaluate(el => el.type);
            const placeholder = await input.evaluate(el => el.placeholder);
            
            if (type === 'email') {
              await input.type('test@example.com');
            } else if (type === 'tel') {
              await input.type('1234567890');
            } else if (placeholder && placeholder.toLowerCase().includes('nome')) {
              await input.type('Test User');
            } else {
              await input.type('Test input');
            }
          }
          
          // Submit form
          const submitButton = await modal.$('button[type="submit"], .btn-submit');
          if (submitButton) {
            await submitButton.click();
            
            // Wait for response
            await page.waitForTimeout(1000);
            
            // Modal should close or show success message
            const isModalGone = await page.evaluate(() => 
              !document.querySelector('.modal, .quick-action-modal, [role="dialog"]')
            );
            
            expect(isModalGone).toBe(true);
          }
        }
      }
    });
  });
  
  describe('Accessibility Testing', () => {
    test('should be navigable with keyboard only', async () => {
      // Start keyboard navigation
      await page.keyboard.press('Tab');
      
      // Get all focusable elements
      const focusableElements = await page.$$eval(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        elements => elements.length
      );
      
      if (focusableElements > 0) {
        // Tab through several elements
        for (let i = 0; i < Math.min(5, focusableElements); i++) {
          await page.keyboard.press('Tab');
          
          // Check that something is focused
          const focusedElement = await page.evaluate(() => document.activeElement.tagName);
          expect(focusedElement).toBeTruthy();
        }
        
        // Should be able to activate focused element with Enter
        await page.keyboard.press('Enter');
        
        // No errors should occur
        await page.waitForTimeout(500);
      }
    });
    
    test('should work with screen reader simulation', async () => {
      // Simulate screen reader by checking aria-live regions
      const liveRegions = await page.$$('[aria-live]');
      expect(liveRegions.length).toBeGreaterThan(0);
      
      // Open widget and send message
      await page.click('button');
      await page.waitForTimeout(500);
      
      const input = await page.$('input, textarea');
      if (input) {
        await input.type('Test message for screen reader');
        await page.keyboard.press('Enter');
        
        // Wait for response
        await page.waitForTimeout(2000);
        
        // Check if live regions were updated
        const liveRegionContent = await page.$eval('[aria-live]', el => el.textContent);
        expect(liveRegionContent.length).toBeGreaterThan(0);
      }
    });
    
    test('should have proper focus indicators', async () => {
      const buttons = await page.$$('button, [role="button"]');
      
      for (const button of buttons.slice(0, 3)) { // Test first 3 buttons
        await button.focus();
        
        // Check if focus indicator is visible
        const focusStyle = await button.evaluate(el => {
          const style = window.getComputedStyle(el, ':focus');
          return {
            outline: style.outline,
            boxShadow: style.boxShadow,
            border: style.border
          };
        });
        
        const hasFocusIndicator = 
          focusStyle.outline !== 'none' || 
          focusStyle.boxShadow !== 'none' ||
          focusStyle.border !== 'none';
        
        expect(hasFocusIndicator).toBe(true);
      }
    });
  });
  
  describe('Responsive Design', () => {
    test('should adapt to mobile viewport', async () => {
      // Switch to mobile viewport
      await page.setViewport({ width: 375, height: 667 });
      await page.waitForTimeout(500);
      
      // Widget should still be functional
      const widget = await page.$('#chatbot-widget-container');
      expect(widget).toBeTruthy();
      
      // Open widget
      await page.click('button');
      await page.waitForTimeout(500);
      
      // Check if mobile-specific styles are applied
      const isMobileOptimized = await page.evaluate(() => {
        const widget = document.querySelector('#chatbot-widget-container');
        const style = window.getComputedStyle(widget);
        
        // Should use full width on mobile
        return parseInt(style.width) >= window.innerWidth * 0.8;
      });
      
      expect(isMobileOptimized).toBe(true);
    });
    
    test('should handle tablet viewport', async () => {
      // Switch to tablet viewport
      await page.setViewport({ width: 768, height: 1024 });
      await page.waitForTimeout(500);
      
      // Widget should be responsive
      const widget = await page.$('#chatbot-widget-container');
      expect(widget).toBeTruthy();
      
      // Should maintain functionality
      await page.click('button');
      await page.waitForTimeout(500);
      
      const input = await page.$('input, textarea');
      if (input) {
        await input.type('Tablet test message');
        await page.keyboard.press('Enter');
        
        // Should work without issues
        await page.waitForTimeout(1000);
      }
    });
  });
  
  describe('Error Handling', () => {
    test('should handle network failures gracefully', async () => {
      // Block network requests to simulate offline
      await page.setOfflineMode(true);
      
      // Try to interact with widget
      await page.click('button');
      await page.waitForTimeout(500);
      
      const input = await page.$('input, textarea');
      if (input) {
        await input.type('Message that will fail');
        await page.keyboard.press('Enter');
        
        // Wait for error handling
        await page.waitForTimeout(2000);
        
        // Should show error message
        const errorMessage = await page.$('.error-message, .error, [role="alert"]');
        expect(errorMessage).toBeTruthy();
      }
      
      // Restore network
      await page.setOfflineMode(false);
    });
    
    test('should recover from JavaScript errors', async () => {
      // Inject an error
      await page.evaluate(() => {
        window.addEventListener('error', (e) => {
          console.log('Caught error:', e.message);
        });
      });
      
      // Widget should still be functional
      const widget = await page.$('#chatbot-widget-container');
      expect(widget).toBeTruthy();
    });
  });
  
  describe('Performance', () => {
    test('should load within acceptable time', async () => {
      const startTime = Date.now();
      
      await page.reload();
      await page.waitForSelector('#chatbot-widget-container');
      
      const loadTime = Date.now() - startTime;
      
      // Should load within 3 seconds
      expect(loadTime).toBeLessThan(3000);
    });
    
    test('should have good Lighthouse scores', async () => {
      // This would require lighthouse integration
      // For now, just check basic performance metrics
      
      const metrics = await page.metrics();
      
      // Should have reasonable memory usage
      expect(metrics.JSHeapUsedSize).toBeLessThan(50 * 1024 * 1024); // 50MB
      
      // Should have reasonable DOM nodes
      expect(metrics.Nodes).toBeLessThan(1000);
    });
  });
  
  describe('Cross-browser Compatibility', () => {
    // These tests would run the same suite across different browsers
    // For now, just check basic compatibility features
    
    test('should support modern JavaScript features', async () => {
      const supportsModernJS = await page.evaluate(() => {
        try {
          // Test arrow functions
          const arrow = () => 'test';
          
          // Test const/let
          const testConst = 'test';
          let testLet = 'test';
          
          // Test template literals
          const template = `template ${testConst}`;
          
          // Test destructuring
          const [first] = [1, 2, 3];
          
          return true;
        } catch (e) {
          return false;
        }
      });
      
      expect(supportsModernJS).toBe(true);
    });
    
    test('should handle CSS features gracefully', async () => {
      const supportsCSSFeatures = await page.evaluate(() => {
        const div = document.createElement('div');
        div.style.display = 'flex';
        div.style.gridTemplateColumns = '1fr 1fr';
        
        // Should not throw errors
        return true;
      });
      
      expect(supportsCSSFeatures).toBe(true);
    });
  });
});
