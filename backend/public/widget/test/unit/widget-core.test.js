/**
 * Unit Tests for ChatBot Widget Core Functionality
 * Tests the main widget class and its core methods
 */

describe('ChatBot Widget Core', () => {
  let container;
  let widget;
  
  beforeEach(async () => {
    container = createWidgetContainer();
    
    // Load the widget script
    await loadWidgetScript('js/chatbot-widget.js');
    
    // Mock successful API responses
    fetch.mockResolvedValue({
      ok: true,
      json: jest.fn().mockResolvedValue({
        success: true,
        message: 'Test response'
      })
    });
  });
  
  afterEach(() => {
    if (widget) {
      widget.destroy?.();
    }
  });
  
  describe('Widget Initialization', () => {
    test('should create widget instance with valid config', () => {
      const config = {
        apiKey: 'test-api-key',
        tenantId: 1,
        theme: 'light'
      };
      
      widget = new window.ChatbotWidget(container, config);
      
      expect(widget).toBeDefined();
      expect(widget.config).toEqual(expect.objectContaining(config));
      expect(widget.container).toBe(container);
    });
    
    test('should throw error with invalid config', () => {
      expect(() => {
        new window.ChatbotWidget(container, {});
      }).toThrow();
      
      expect(() => {
        new window.ChatbotWidget(null, { apiKey: 'test' });
      }).toThrow();
    });
    
    test('should initialize with default values', () => {
      const config = {
        apiKey: 'test-api-key',
        tenantId: 1
      };
      
      widget = new window.ChatbotWidget(container, config);
      
      expect(widget.config.theme).toBe('light');
      expect(widget.config.position).toBe('bottom-right');
      expect(widget.state.isOpen).toBe(false);
      expect(widget.state.isLoading).toBe(false);
    });
  });
  
  describe('Widget State Management', () => {
    beforeEach(() => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
    });
    
    test('should open and close widget', () => {
      expect(widget.state.isOpen).toBe(false);
      
      widget.open();
      expect(widget.state.isOpen).toBe(true);
      
      widget.close();
      expect(widget.state.isOpen).toBe(false);
    });
    
    test('should toggle widget state', () => {
      expect(widget.state.isOpen).toBe(false);
      
      widget.toggle();
      expect(widget.state.isOpen).toBe(true);
      
      widget.toggle();
      expect(widget.state.isOpen).toBe(false);
    });
    
    test('should manage loading state', () => {
      expect(widget.state.isLoading).toBe(false);
      
      widget.setLoading(true);
      expect(widget.state.isLoading).toBe(true);
      
      widget.setLoading(false);
      expect(widget.state.isLoading).toBe(false);
    });
  });
  
  describe('Message Handling', () => {
    beforeEach(() => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      widget.open(); // Open widget to initialize UI
    });
    
    test('should send message and receive response', async () => {
      const message = 'Hello, test message';
      
      fetch.mockResolvedValueOnce({
        ok: true,
        json: jest.fn().mockResolvedValue({
          choices: [{
            message: {
              content: 'Hello! How can I help you?',
              role: 'assistant'
            }
          }]
        })
      });
      
      await widget.sendMessage(message);
      
      expect(fetch).toHaveBeenCalledWith(
        expect.stringContaining('/v1/chat/completions'),
        expect.objectContaining({
          method: 'POST',
          headers: expect.objectContaining({
            'Authorization': 'Bearer test-api-key',
            'Content-Type': 'application/json'
          }),
          body: expect.stringContaining(message)
        })
      );
    });
    
    test('should handle API errors gracefully', async () => {
      const message = 'Test message';
      
      fetch.mockRejectedValueOnce(new Error('Network error'));
      
      await widget.sendMessage(message);
      
      // Should not throw error, should handle gracefully
      expect(widget.state.isLoading).toBe(false);
    });
    
    test('should add messages to conversation history', async () => {
      const userMessage = 'User message';
      const botMessage = 'Bot response';
      
      widget.addMessage(userMessage, 'user');
      widget.addMessage(botMessage, 'assistant');
      
      expect(widget.messages).toHaveLength(2);
      expect(widget.messages[0]).toEqual(expect.objectContaining({
        content: userMessage,
        role: 'user'
      }));
      expect(widget.messages[1]).toEqual(expect.objectContaining({
        content: botMessage,
        role: 'assistant'
      }));
    });
  });
  
  describe('Session Management', () => {
    beforeEach(() => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
    });
    
    test('should generate unique session ID', () => {
      const sessionId1 = widget.generateSessionId();
      const sessionId2 = widget.generateSessionId();
      
      expect(sessionId1).toBeDefined();
      expect(sessionId2).toBeDefined();
      expect(sessionId1).not.toBe(sessionId2);
      expect(sessionId1).toMatch(/^session_\d+_[a-z0-9]+$/);
    });
    
    test('should persist session ID in sessionStorage', () => {
      const sessionId = 'test-session-123';
      
      widget.setSessionId(sessionId);
      
      expect(sessionStorage.setItem).toHaveBeenCalledWith(
        expect.stringContaining('session_id'),
        sessionId
      );
    });
    
    test('should retrieve session ID from sessionStorage', () => {
      const sessionId = 'stored-session-456';
      sessionStorage.getItem.mockReturnValue(sessionId);
      
      const retrievedId = widget.getSessionId();
      
      expect(retrievedId).toBe(sessionId);
    });
  });
  
  describe('Configuration Validation', () => {
    test('should validate required config properties', () => {
      expect(() => {
        new window.ChatbotWidget(container, {});
      }).toThrow('apiKey is required');
      
      expect(() => {
        new window.ChatbotWidget(container, { apiKey: 'test' });
      }).toThrow('tenantId is required');
    });
    
    test('should validate config property types', () => {
      expect(() => {
        new window.ChatbotWidget(container, {
          apiKey: 123,
          tenantId: 1
        });
      }).toThrow('apiKey must be a string');
      
      expect(() => {
        new window.ChatbotWidget(container, {
          apiKey: 'test',
          tenantId: 'invalid'
        });
      }).toThrow('tenantId must be a number');
    });
    
    test('should apply default configuration values', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      expect(widget.config.theme).toBe('light');
      expect(widget.config.position).toBe('bottom-right');
      expect(widget.config.showTitle).toBe(true);
      expect(widget.config.title).toBe('Assistente');
      expect(widget.config.placeholder).toBe('Scrivi un messaggio...');
    });
  });
  
  describe('Event System', () => {
    beforeEach(() => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
    });
    
    test('should emit events on state changes', () => {
      const onOpen = jest.fn();
      const onClose = jest.fn();
      
      widget.on('open', onOpen);
      widget.on('close', onClose);
      
      widget.open();
      expect(onOpen).toHaveBeenCalled();
      
      widget.close();
      expect(onClose).toHaveBeenCalled();
    });
    
    test('should emit message events', () => {
      const onMessageSent = jest.fn();
      const onMessageReceived = jest.fn();
      
      widget.on('message:sent', onMessageSent);
      widget.on('message:received', onMessageReceived);
      
      widget.addMessage('User message', 'user');
      expect(onMessageSent).toHaveBeenCalledWith(
        expect.objectContaining({
          content: 'User message',
          role: 'user'
        })
      );
      
      widget.addMessage('Bot response', 'assistant');
      expect(onMessageReceived).toHaveBeenCalledWith(
        expect.objectContaining({
          content: 'Bot response',
          role: 'assistant'
        })
      );
    });
    
    test('should remove event listeners', () => {
      const listener = jest.fn();
      
      widget.on('test-event', listener);
      widget.emit('test-event');
      expect(listener).toHaveBeenCalledTimes(1);
      
      widget.off('test-event', listener);
      widget.emit('test-event');
      expect(listener).toHaveBeenCalledTimes(1); // Still 1, not called again
    });
  });
  
  describe('Error Handling', () => {
    beforeEach(() => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
    });
    
    test('should handle network errors', async () => {
      fetch.mockRejectedValue(new Error('Network error'));
      
      const errorHandler = jest.fn();
      widget.on('error', errorHandler);
      
      await widget.sendMessage('Test message');
      
      expect(errorHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'network',
          message: expect.stringContaining('Network error')
        })
      );
    });
    
    test('should handle API errors', async () => {
      fetch.mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad Request',
        json: jest.fn().mockResolvedValue({
          error: 'Invalid request'
        })
      });
      
      const errorHandler = jest.fn();
      widget.on('error', errorHandler);
      
      await widget.sendMessage('Test message');
      
      expect(errorHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'api',
          status: 400,
          message: expect.stringContaining('Bad Request')
        })
      );
    });
    
    test('should handle rate limiting', async () => {
      fetch.mockResolvedValue({
        ok: false,
        status: 429,
        statusText: 'Too Many Requests',
        headers: {
          get: jest.fn().mockReturnValue('60')
        }
      });
      
      const errorHandler = jest.fn();
      widget.on('error', errorHandler);
      
      await widget.sendMessage('Test message');
      
      expect(errorHandler).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'rate_limit',
          retryAfter: 60
        })
      );
    });
  });
  
  describe('Widget Cleanup', () => {
    test('should clean up resources on destroy', () => {
      widget = new window.ChatbotWidget(container, {
        apiKey: 'test-api-key',
        tenantId: 1
      });
      
      // Add some state
      widget.open();
      widget.addMessage('Test', 'user');
      
      expect(widget.state.isOpen).toBe(true);
      expect(widget.messages).toHaveLength(1);
      
      widget.destroy();
      
      // Widget should be cleaned up
      expect(container.innerHTML).toBe('');
    });
  });
});
