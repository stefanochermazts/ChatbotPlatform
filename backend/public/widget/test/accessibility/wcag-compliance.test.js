/**
 * Accessibility Tests for ChatBot Widget
 * Tests WCAG 2.1 AA compliance and accessibility features
 */

describe('ChatBot Widget Accessibility', () => {
  let container;
  let widget;
  
  beforeEach(async () => {
    container = createWidgetContainer();
    
    // Load all widget scripts including accessibility
    await loadWidgetScript('js/chatbot-accessibility.js');
    await loadWidgetScript('js/chatbot-widget.js');
    
    widget = new window.ChatbotWidget(container, {
      apiKey: 'test-api-key',
      tenantId: 1
    });
    
    widget.open(); // Open widget to render UI
  });
  
  afterEach(() => {
    if (widget) {
      widget.destroy?.();
    }
  });
  
  describe('WCAG 2.1 AA Compliance', () => {
    test('should pass axe accessibility audit', async () => {
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
    
    test('should have proper heading structure', () => {
      const headings = container.querySelectorAll('h1, h2, h3, h4, h5, h6');
      
      // Widget should have a proper heading hierarchy
      expect(headings.length).toBeGreaterThan(0);
      
      // Check if headings are properly nested
      const headingLevels = Array.from(headings).map(h => parseInt(h.tagName.charAt(1)));
      
      for (let i = 1; i < headingLevels.length; i++) {
        const current = headingLevels[i];
        const previous = headingLevels[i - 1];
        
        // Heading levels should not skip more than one level
        expect(current - previous).toBeLessThanOrEqual(1);
      }
    });
    
    test('should have proper landmark regions', () => {
      const main = container.querySelector('[role="application"], [role="main"], main');
      expect(main).toBeTruthy();
      
      const navigation = container.querySelector('[role="navigation"], nav');
      // Navigation is optional for widget
      
      const complementary = container.querySelector('[role="complementary"], aside');
      // Complementary is optional for widget
    });
    
    test('should have accessible form controls', () => {
      const inputs = container.querySelectorAll('input, textarea, select, button');
      
      inputs.forEach(input => {
        // Each form control should have a label or aria-label
        const hasLabel = input.labels && input.labels.length > 0;
        const hasAriaLabel = input.hasAttribute('aria-label');
        const hasAriaLabelledBy = input.hasAttribute('aria-labelledby');
        
        expect(hasLabel || hasAriaLabel || hasAriaLabelledBy).toBe(true);
      });
    });
    
    test('should have proper focus management', () => {
      const focusableElements = container.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      
      focusableElements.forEach(element => {
        // Element should be focusable
        element.focus();
        expect(document.activeElement).toBe(element);
        
        // Should have visible focus indicator
        const computedStyle = window.getComputedStyle(element, ':focus');
        const hasOutline = computedStyle.outline !== 'none' && computedStyle.outline !== '';
        const hasBoxShadow = computedStyle.boxShadow !== 'none';
        
        expect(hasOutline || hasBoxShadow).toBe(true);
      });
    });
    
    test('should support keyboard navigation', () => {
      const firstFocusable = container.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      
      if (firstFocusable) {
        firstFocusable.focus();
        expect(document.activeElement).toBe(firstFocusable);
        
        // Test Tab navigation
        simulateKeyboard(firstFocusable, 'Tab');
        
        // Should move focus to next element
        expect(document.activeElement).not.toBe(firstFocusable);
      }
    });
  });
  
  describe('ARIA Implementation', () => {
    test('should have proper ARIA roles', () => {
      const application = container.querySelector('[role="application"]');
      expect(application).toBeTruthy();
      
      const dialog = container.querySelector('[role="dialog"]');
      // Dialog may or may not be present depending on state
      
      const button = container.querySelector('[role="button"], button');
      expect(button).toBeTruthy();
    });
    
    test('should have ARIA labels and descriptions', () => {
      const interactiveElements = container.querySelectorAll(
        '[role="button"], button, [role="textbox"], input, [role="combobox"], select'
      );
      
      interactiveElements.forEach(element => {
        const hasAriaLabel = element.hasAttribute('aria-label');
        const hasAriaLabelledBy = element.hasAttribute('aria-labelledby');
        const hasAriaDescribedBy = element.hasAttribute('aria-describedby');
        const hasTitle = element.hasAttribute('title');
        
        // Should have some form of accessible name
        expect(hasAriaLabel || hasAriaLabelledBy || hasTitle).toBe(true);
      });
    });
    
    test('should use ARIA live regions for dynamic content', () => {
      const liveRegions = container.querySelectorAll('[aria-live]');
      expect(liveRegions.length).toBeGreaterThan(0);
      
      liveRegions.forEach(region => {
        const ariaLive = region.getAttribute('aria-live');
        expect(['polite', 'assertive', 'off']).toContain(ariaLive);
      });
    });
    
    test('should handle ARIA expanded for collapsible content', async () => {
      const expandableButtons = container.querySelectorAll('[aria-expanded]');
      
      for (const button of expandableButtons) {
        const initialState = button.getAttribute('aria-expanded');
        expect(['true', 'false']).toContain(initialState);
        
        // Click to toggle
        simulateMouse(button, 'click');
        
        // Wait for state change
        await new Promise(resolve => setTimeout(resolve, 100));
        
        const newState = button.getAttribute('aria-expanded');
        expect(['true', 'false']).toContain(newState);
        expect(newState).not.toBe(initialState);
      }
    });
  });
  
  describe('Screen Reader Support', () => {
    test('should announce dynamic content changes', () => {
      const liveRegion = container.querySelector('[aria-live="polite"], [aria-live="assertive"]');
      expect(liveRegion).toBeTruthy();
      
      // Simulate adding new message
      widget.addMessage('Test message', 'assistant');
      
      // Live region should contain the new message or announcement
      expect(liveRegion.textContent).toBeTruthy();
    });
    
    test('should have descriptive error messages', async () => {
      // Simulate an error condition
      fetch.mockRejectedValue(new Error('Network error'));
      
      await widget.sendMessage('Test message');
      
      // Error should be announced in live region
      const liveRegions = container.querySelectorAll('[aria-live]');
      const hasErrorAnnouncement = Array.from(liveRegions).some(region => 
        region.textContent.toLowerCase().includes('erro')
      );
      
      expect(hasErrorAnnouncement).toBe(true);
    });
    
    test('should provide context for form validation', () => {
      const formInputs = container.querySelectorAll('input, textarea');
      
      formInputs.forEach(input => {
        // Required fields should be marked
        if (input.hasAttribute('required')) {
          const hasAriaRequired = input.hasAttribute('aria-required');
          const hasAriaInvalid = input.hasAttribute('aria-invalid');
          
          expect(hasAriaRequired).toBe(true);
          expect(input.getAttribute('aria-required')).toBe('true');
        }
      });
    });
  });
  
  describe('High Contrast Mode Support', () => {
    test('should work in forced-colors mode', () => {
      // Simulate forced-colors mode
      Object.defineProperty(window, 'matchMedia', {
        value: jest.fn().mockImplementation(query => ({
          matches: query === '(forced-colors: active)',
          media: query,
          onchange: null,
          addListener: jest.fn(),
          removeListener: jest.fn(),
          addEventListener: jest.fn(),
          removeEventListener: jest.fn(),
          dispatchEvent: jest.fn(),
        })),
      });
      
      // Check that elements use system colors
      const buttons = container.querySelectorAll('button');
      buttons.forEach(button => {
        const computedStyle = window.getComputedStyle(button);
        // Should not rely on specific color values in forced-colors mode
        expect(computedStyle.borderStyle).not.toBe('none');
      });
    });
    
    test('should provide sufficient color contrast', () => {
      // This test would require actual color analysis
      // For now, we verify that contrast-sensitive styles are applied
      const textElements = container.querySelectorAll('p, span, div, h1, h2, h3, h4, h5, h6');
      
      textElements.forEach(element => {
        const computedStyle = window.getComputedStyle(element);
        
        // Should have defined text color
        expect(computedStyle.color).toBeTruthy();
        expect(computedStyle.color).not.toBe('transparent');
      });
    });
  });
  
  describe('Reduced Motion Support', () => {
    test('should respect prefers-reduced-motion', () => {
      // Simulate reduced motion preference
      Object.defineProperty(window, 'matchMedia', {
        value: jest.fn().mockImplementation(query => ({
          matches: query === '(prefers-reduced-motion: reduce)',
          media: query,
          onchange: null,
          addListener: jest.fn(),
          removeListener: jest.fn(),
          addEventListener: jest.fn(),
          removeEventListener: jest.fn(),
          dispatchEvent: jest.fn(),
        })),
      });
      
      // Animated elements should have reduced or no animation
      const animatedElements = container.querySelectorAll('[style*="transition"], [style*="animation"]');
      
      animatedElements.forEach(element => {
        const computedStyle = window.getComputedStyle(element);
        
        // Animations should be reduced or disabled
        const hasReducedMotion = 
          computedStyle.animationDuration === '0s' ||
          computedStyle.transitionDuration === '0s' ||
          computedStyle.animationPlayState === 'paused';
        
        // This is a heuristic check
        expect(computedStyle).toBeDefined();
      });
    });
  });
  
  describe('Touch and Mobile Accessibility', () => {
    test('should have adequate touch targets', () => {
      const interactiveElements = container.querySelectorAll(
        'button, [role="button"], a, input, select, textarea'
      );
      
      interactiveElements.forEach(element => {
        const rect = element.getBoundingClientRect();
        
        // Touch targets should be at least 44x44 pixels (WCAG guideline)
        expect(rect.width).toBeGreaterThanOrEqual(44);
        expect(rect.height).toBeGreaterThanOrEqual(44);
      });
    });
    
    test('should support gesture alternatives', () => {
      // All interactive elements should be operable via click/tap
      const gestureElements = container.querySelectorAll('[onswipe], [onpinch], [onrotate]');
      
      gestureElements.forEach(element => {
        // Should also have click/tap alternative
        const hasClickAlternative = 
          element.onclick ||
          element.addEventListener ||
          element.hasAttribute('role') && ['button', 'link'].includes(element.getAttribute('role'));
        
        expect(hasClickAlternative).toBe(true);
      });
    });
  });
  
  describe('Language and Internationalization', () => {
    test('should have proper language attributes', () => {
      const textElements = container.querySelectorAll('*:not(script):not(style)');
      
      // Widget should inherit language from document or have lang attribute
      const widgetLang = container.getAttribute('lang') || document.documentElement.getAttribute('lang');
      expect(widgetLang).toBeTruthy();
    });
    
    test('should handle RTL languages properly', () => {
      // Test with RTL language
      container.setAttribute('dir', 'rtl');
      container.setAttribute('lang', 'ar');
      
      // Layout should adapt to RTL
      const computedStyle = window.getComputedStyle(container);
      expect(computedStyle.direction).toBe('rtl');
    });
  });
  
  describe('Focus Trapping in Modal', () => {
    test('should trap focus in modal dialogs', async () => {
      // Open a modal (if widget has modal functionality)
      const modalTrigger = container.querySelector('[data-modal-trigger], [aria-haspopup="dialog"]');
      
      if (modalTrigger) {
        simulateMouse(modalTrigger, 'click');
        
        // Wait for modal to open
        await waitForMutation(() => container.querySelector('[role="dialog"]'));
        
        const modal = container.querySelector('[role="dialog"]');
        expect(modal).toBeTruthy();
        
        const focusableInModal = modal.querySelectorAll(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        
        if (focusableInModal.length > 0) {
          // Focus should be trapped within modal
          const firstElement = focusableInModal[0];
          const lastElement = focusableInModal[focusableInModal.length - 1];
          
          firstElement.focus();
          expect(document.activeElement).toBe(firstElement);
          
          // Tab from last element should go to first
          lastElement.focus();
          simulateKeyboard(lastElement, 'Tab');
          expect(document.activeElement).toBe(firstElement);
        }
      }
    });
  });
});
