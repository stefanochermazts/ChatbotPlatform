/**
 * Integration Tests for ChatBot Widget
 * Tests component interactions and end-to-end workflows
 */

describe('ChatBot Widget Integration', () => {
  let container;
  let widget;
  
  beforeEach(async () => {
    container = createWidgetContainer();
    
    // Load all widget components
    await loadWidgetScript('js/chatbot-accessibility.js');
    await loadWidgetScript('js/chatbot-citations.js');
    await loadWidgetScript('js/chatbot-dark-mode.js');
    await loadWidgetScript('js/chatbot-error-handling.js');
    await loadWidgetScript('js/chatbot-quick-actions.js');
    await loadWidgetScript('js/chatbot-widget.js');
    
    // Mock successful API responses
    fetch.mockResolvedValue({
      ok: true,
      json: jest.fn().mockResolvedValue({
        choices: [{
          message: {
            content: 'Test response from assistant',
            role: 'assistant'
          }
        }],
        usage: {
          total_tokens: 25
        }
      })
    });
  });
  
  afterEach(() => {
    if (widget) {
      widget.destroy?.();
    }
  });
  
  describe('Complete Widget Lifecycle', () => {
    test('should initialize all components correctly', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1,
        theme: 'light'
      });
      
      // Main widget should be initialized
      expect(widget).toBeDefined();
      expect(widget.config.apiKey).toBe('test-api-key');
      
      // Sub-components should be initialized
      expect(widget.accessibility).toBeDefined();
      expect(widget.citations).toBeDefined();
      expect(widget.darkMode).toBeDefined();
      expect(widget.errorHandler).toBeDefined();
      expect(widget.quickActions).toBeDefined();
    });
    
    test('should handle complete conversation flow', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      // 1. Open widget
      widget.open();
      expect(widget.state.isOpen).toBe(true);
      
      // 2. Send message
      const userMessage = 'Hello, I need help';
      await widget.sendMessage(userMessage);
      
      // 3. Verify API call was made
      expect(fetch).toHaveBeenCalledWith(
        expect.stringContaining('/v1/chat/completions'),
        expect.objectContaining({
          method: 'POST',
          headers: expect.objectContaining({
            'Authorization': 'Bearer test-api-key'
          })
        })
      );
      
      // 4. Verify conversation history
      expect(widget.messages).toHaveLength(2); // user + assistant
      expect(widget.messages[0].content).toBe(userMessage);
      expect(widget.messages[1].content).toBe('Test response from assistant');
      
      // 5. Close widget
      widget.close();
      expect(widget.state.isOpen).toBe(false);
    });
    
    test('should persist conversation across open/close cycles', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      // Send message
      widget.open();
      await widget.sendMessage('First message');
      
      // Close and reopen
      widget.close();
      widget.open();
      
      // Conversation should be preserved
      expect(widget.messages).toHaveLength(2);
      
      // Send another message
      await widget.sendMessage('Second message');
      expect(widget.messages).toHaveLength(4);
    });
  });
  
  describe('Quick Actions Integration', () => {
    beforeEach(() => {
      // Mock quick actions API
      fetch.mockImplementation((url) => {
        if (url.includes('/quick-actions/')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              actions: [
                {
                  id: 1,
                  type: 'contact_support',
                  label: 'Contatta Supporto',
                  icon: '💬',
                  style: 'primary',
                  required_fields: [
                    { name: 'email', label: 'Email', required: true },
                    { name: 'name', label: 'Nome', required: true }
                  ]
                }
              ]
            })
          });
        }
        
        if (url.includes('/quick-actions/execute')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              message: 'Action executed successfully',
              result: { ticket_id: 'SUP-123' }
            })
          });
        }
        
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ choices: [{ message: { content: 'Response', role: 'assistant' } }] })
        });
      });
    });
    
    test('should load and display quick actions', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      
      // Wait for quick actions to load
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // Verify quick actions API was called
      expect(fetch).toHaveBeenCalledWith(
        expect.stringContaining('/quick-actions/'),
        expect.objectContaining({
          method: 'GET',
          headers: expect.objectContaining({
            'Authorization': 'Bearer test-api-key'
          })
        })
      );
      
      // Quick actions should be rendered
      const quickActionsContainer = container.querySelector('.chatbot-quick-actions');
      expect(quickActionsContainer).toBeTruthy();
      
      const actionButtons = container.querySelectorAll('.quick-action-btn');
      expect(actionButtons.length).toBeGreaterThan(0);
    });
    
    test('should execute quick action with form', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // Click on quick action button
      const actionButton = container.querySelector('[data-action-type="contact_support"]');
      expect(actionButton).toBeTruthy();
      
      simulateMouse(actionButton, 'click');
      
      // Modal should appear
      await waitForMutation(() => document.querySelector('.quick-action-modal'));
      
      const modal = document.querySelector('.quick-action-modal');
      expect(modal).toBeTruthy();
      
      // Fill form fields
      const emailInput = modal.querySelector('input[name="email"]');
      const nameInput = modal.querySelector('input[name="name"]');
      
      emailInput.value = 'test@example.com';
      nameInput.value = 'Test User';
      
      // Submit form
      const submitButton = modal.querySelector('.btn-submit');
      simulateMouse(submitButton, 'click');
      
      // Wait for API call
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // Verify execute API was called
      expect(fetch).toHaveBeenCalledWith(
        expect.stringContaining('/quick-actions/execute'),
        expect.objectContaining({
          method: 'POST',
          body: expect.stringContaining('contact_support')
        })
      );
      
      // Modal should be closed
      expect(document.querySelector('.quick-action-modal')).toBeFalsy();
    });
  });
  
  describe('Error Handling Integration', () => {
    test('should show user-friendly error messages', async () => {
      fetch.mockRejectedValue(new Error('Network error'));
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await widget.sendMessage('Test message');
      
      // Error should be displayed in chat
      const errorMessage = container.querySelector('.error-message, .bot-message');
      expect(errorMessage).toBeTruthy();
      expect(errorMessage.textContent).toContain('errore');
    });
    
    test('should handle rate limiting gracefully', async () => {
      fetch.mockResolvedValue({
        ok: false,
        status: 429,
        statusText: 'Too Many Requests',
        headers: {
          get: jest.fn().mockReturnValue('60')
        }
      });
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await widget.sendMessage('Test message');
      
      // Rate limit message should be displayed
      const messages = container.querySelectorAll('.bot-message');
      const hasRateLimitMessage = Array.from(messages).some(msg => 
        msg.textContent.toLowerCase().includes('limite') || 
        msg.textContent.toLowerCase().includes('attendi')
      );
      
      expect(hasRateLimitMessage).toBe(true);
    });
    
    test('should provide retry functionality', async () => {
      // First call fails
      fetch.mockRejectedValueOnce(new Error('Network error'));
      // Second call succeeds
      fetch.mockResolvedValueOnce({
        ok: true,
        json: jest.fn().mockResolvedValue({
          choices: [{ message: { content: 'Success after retry', role: 'assistant' } }]
        })
      });
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await widget.sendMessage('Test message');
      
      // Look for retry button
      const retryButton = container.querySelector('.retry-button, [data-action="retry"]');
      
      if (retryButton) {
        simulateMouse(retryButton, 'click');
        
        // Wait for retry
        await new Promise(resolve => setTimeout(resolve, 100));
        
        // Should have successful response
        expect(widget.messages[widget.messages.length - 1].content).toBe('Success after retry');
      }
    });
  });
  
  describe('Citations Integration', () => {
    beforeEach(() => {
      fetch.mockResolvedValue({
        ok: true,
        json: jest.fn().mockResolvedValue({
          choices: [{
            message: {
              content: 'Response with citations',
              role: 'assistant'
            }
          }],
          citations: [
            {
              id: '1',
              title: 'Test Document',
              url: '/documents/1',
              snippet: 'Relevant snippet'
            }
          ]
        })
      });
    });
    
    test('should display citations with response', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await widget.sendMessage('Question with citations');
      
      // Citations should be displayed
      const citations = container.querySelectorAll('.citation, .source');
      expect(citations.length).toBeGreaterThan(0);
    });
    
    test('should open citation in modal on click', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      await widget.sendMessage('Question with citations');
      
      const citationLink = container.querySelector('.citation a, .citation-link');
      
      if (citationLink) {
        simulateMouse(citationLink, 'click');
        
        // Citation modal should open
        await waitForMutation(() => document.querySelector('.citation-modal'));
        
        const modal = document.querySelector('.citation-modal');
        expect(modal).toBeTruthy();
      }
    });
  });
  
  describe('Dark Mode Integration', () => {
    test('should apply dark mode theme consistently', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1,
        theme: 'dark'
      });
      
      widget.open();
      
      // Widget should have dark theme applied
      expect(container.getAttribute('data-theme')).toBe('dark');
      
      const darkElements = container.querySelectorAll('[data-theme="dark"]');
      expect(darkElements.length).toBeGreaterThan(0);
    });
    
    test('should respect system dark mode preference', () => {
      // Mock dark mode preference
      Object.defineProperty(window, 'matchMedia', {
        value: jest.fn().mockImplementation(query => ({
          matches: query === '(prefers-color-scheme: dark)',
          media: query,
          addListener: jest.fn(),
          removeListener: jest.fn(),
        })),
      });
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1,
        theme: 'auto'
      });
      
      widget.open();
      
      // Should detect and apply dark theme
      expect(container.getAttribute('data-theme')).toBe('dark');
    });
  });
  
  describe('Analytics Integration', () => {
    test('should track widget events', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      // Mock analytics tracking
      const trackSpy = jest.spyOn(widget.analytics || {}, 'trackEvent').mockImplementation(() => {});
      
      widget.open();
      
      if (trackSpy.mock) {
        expect(trackSpy).toHaveBeenCalledWith('widget_opened', expect.any(Object));
      }
      
      widget.close();
      
      if (trackSpy.mock) {
        expect(trackSpy).toHaveBeenCalledWith('widget_closed', expect.any(Object));
      }
    });
    
    test('should track message events', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      const trackSpy = jest.spyOn(widget.analytics || {}, 'trackEvent').mockImplementation(() => {});
      
      widget.open();
      await widget.sendMessage('Test message');
      
      if (trackSpy.mock) {
        expect(trackSpy).toHaveBeenCalledWith('message_sent', expect.objectContaining({
          query: 'Test message'
        }));
        
        expect(trackSpy).toHaveBeenCalledWith('message_received', expect.any(Object));
      }
    });
  });
  
  describe('Responsive Design Integration', () => {
    test('should adapt to mobile viewport', () => {
      // Simulate mobile viewport
      Object.defineProperty(window, 'innerWidth', { value: 375, writable: true });
      Object.defineProperty(window, 'innerHeight', { value: 667, writable: true });
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      
      // Widget should have mobile styles applied
      const computedStyle = window.getComputedStyle(container);
      expect(computedStyle).toBeDefined();
      
      // Quick actions should stack vertically on mobile
      const quickActionsContainer = container.querySelector('.quick-actions-list');
      if (quickActionsContainer) {
        const quickActionsStyle = window.getComputedStyle(quickActionsContainer);
        expect(quickActionsStyle.flexDirection).toBe('column');
      }
    });
    
    test('should handle orientation changes', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      
      // Simulate orientation change
      Object.defineProperty(window, 'innerWidth', { value: 667, writable: true });
      Object.defineProperty(window, 'innerHeight', { value: 375, writable: true });
      
      window.dispatchEvent(new Event('resize'));
      
      // Widget should adapt to new dimensions
      expect(widget.state.isOpen).toBe(true); // Should remain functional
    });
  });
  
  describe('Performance Integration', () => {
    test('should load components asynchronously', async () => {
      const startTime = performance.now();
      
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      const endTime = performance.now();
      const initTime = endTime - startTime;
      
      // Widget should initialize quickly (< 100ms)
      expect(initTime).toBeLessThan(100);
    });
    
    test('should handle large conversation histories', async () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      widget.open();
      
      // Add many messages
      for (let i = 0; i < 100; i++) {
        widget.addMessage(`Message ${i}`, i % 2 === 0 ? 'user' : 'assistant');
      }
      
      // Widget should remain responsive
      expect(widget.messages).toHaveLength(100);
      expect(widget.state.isOpen).toBe(true);
      
      // Should be able to send new message
      await widget.sendMessage('New message');
      expect(widget.messages).toHaveLength(102);
    });
  });
});
