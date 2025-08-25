/**
 * Jest Setup for ChatBot Widget Tests
 * Configures test environment, mocks, and global utilities
 */

// Import jest-axe for accessibility testing
import { configureAxe, toHaveNoViolations } from 'jest-axe';

// Extend Jest matchers
expect.extend(toHaveNoViolations);

// Configure axe for accessibility testing
const axe = configureAxe({
  rules: {
    // Disable color-contrast rule in tests (requires actual rendering)
    'color-contrast': { enabled: false },
    // Focus custom rules for chatbot widget
    'aria-allowed-attr': { enabled: true },
    'aria-required-attr': { enabled: true },
    'aria-valid-attr': { enabled: true },
    'aria-valid-attr-value': { enabled: true },
    'button-name': { enabled: true },
    'focus-order-semantics': { enabled: true },
    'keyboard': { enabled: true },
    'label': { enabled: true },
    'landmark-one-main': { enabled: false }, // Widget doesn't need main landmark
    'page-has-heading-one': { enabled: false }, // Widget doesn't need h1
    'region': { enabled: false } // Widget content is in application region
  }
});

// Global test utilities
global.axe = axe;

// Mock global objects that the widget expects
global.ResizeObserver = jest.fn().mockImplementation(() => ({
  observe: jest.fn(),
  unobserve: jest.fn(),
  disconnect: jest.fn(),
}));

global.IntersectionObserver = jest.fn().mockImplementation(() => ({
  observe: jest.fn(),
  unobserve: jest.fn(),
  disconnect: jest.fn(),
}));

// Mock fetch API
global.fetch = jest.fn();

// Mock localStorage
const localStorageMock = {
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
};
global.localStorage = localStorageMock;

// Mock sessionStorage
const sessionStorageMock = {
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
};
global.sessionStorage = sessionStorageMock;

// Mock navigator properties
Object.defineProperty(global.navigator, 'userAgent', {
  value: 'Jest Test Runner',
  writable: true
});

Object.defineProperty(global.navigator, 'language', {
  value: 'it-IT',
  writable: true
});

Object.defineProperty(global.navigator, 'sendBeacon', {
  value: jest.fn(),
  writable: true
});

// Mock screen properties
Object.defineProperty(global.screen, 'width', {
  value: 1920,
  writable: true
});

Object.defineProperty(global.screen, 'height', {
  value: 1080,
  writable: true
});

// Mock window properties
Object.defineProperty(global.window, 'innerWidth', {
  value: 1920,
  writable: true
});

Object.defineProperty(global.window, 'innerHeight', {
  value: 1080,
  writable: true
});

// Mock crypto for secure random generation
Object.defineProperty(global.crypto, 'getRandomValues', {
  value: jest.fn().mockImplementation((buffer) => {
    for (let i = 0; i < buffer.length; i++) {
      buffer[i] = Math.floor(Math.random() * 256);
    }
    return buffer;
  })
});

// Mock Date.now for consistent testing
const originalDateNow = Date.now;
global.mockDateNow = (mockTime) => {
  Date.now = jest.fn(() => mockTime);
};

global.restoreDateNow = () => {
  Date.now = originalDateNow;
};

// Helper to create DOM elements for testing
global.createWidgetContainer = () => {
  const container = document.createElement('div');
  container.id = 'chatbot-widget-container';
  container.setAttribute('data-tenant-id', '1');
  container.setAttribute('data-api-key', 'test-api-key');
  document.body.appendChild(container);
  return container;
};

// Helper to clean up DOM after tests
global.cleanupDOM = () => {
  document.body.innerHTML = '';
  // Reset any global widget instances
  if (window.chatbotWidget) {
    delete window.chatbotWidget;
  }
  if (window.ChatbotWidget) {
    delete window.ChatbotWidget;
  }
};

// Helper to load widget scripts in tests
global.loadWidgetScript = async (scriptPath) => {
  const fs = require('fs');
  const path = require('path');
  
  const fullPath = path.join(__dirname, '..', scriptPath);
  const scriptContent = fs.readFileSync(fullPath, 'utf8');
  
  // Execute script in current context
  eval(scriptContent);
};

// Helper to wait for DOM mutations
global.waitForMutation = (callback, timeout = 1000) => {
  return new Promise((resolve, reject) => {
    const observer = new MutationObserver(() => {
      if (callback()) {
        observer.disconnect();
        resolve();
      }
    });
    
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true
    });
    
    setTimeout(() => {
      observer.disconnect();
      reject(new Error('Timeout waiting for DOM mutation'));
    }, timeout);
  });
};

// Helper to simulate user events
global.simulateEvent = (element, eventType, options = {}) => {
  const event = new Event(eventType, {
    bubbles: true,
    cancelable: true,
    ...options
  });
  
  Object.keys(options).forEach(key => {
    event[key] = options[key];
  });
  
  element.dispatchEvent(event);
};

// Helper to simulate keyboard events
global.simulateKeyboard = (element, key, options = {}) => {
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    cancelable: true,
    ...options
  });
  
  element.dispatchEvent(event);
};

// Helper to simulate mouse events
global.simulateMouse = (element, eventType, options = {}) => {
  const event = new MouseEvent(eventType, {
    bubbles: true,
    cancelable: true,
    clientX: 100,
    clientY: 100,
    ...options
  });
  
  element.dispatchEvent(event);
};

// Mock console methods to avoid noise in tests
const originalConsole = { ...console };

global.mockConsole = () => {
  console.log = jest.fn();
  console.warn = jest.fn();
  console.error = jest.fn();
  console.info = jest.fn();
};

global.restoreConsole = () => {
  Object.assign(console, originalConsole);
};

// Setup and teardown for each test
beforeEach(() => {
  // Reset all mocks
  jest.clearAllMocks();
  
  // Reset fetch mock
  fetch.mockClear();
  
  // Reset storage mocks
  localStorage.getItem.mockClear();
  localStorage.setItem.mockClear();
  localStorage.removeItem.mockClear();
  localStorage.clear.mockClear();
  
  sessionStorage.getItem.mockClear();
  sessionStorage.setItem.mockClear();
  sessionStorage.removeItem.mockClear();
  sessionStorage.clear.mockClear();
  
  // Clean up DOM
  cleanupDOM();
  
  // Mock console to reduce noise
  mockConsole();
});

afterEach(() => {
  // Clean up DOM
  cleanupDOM();
  
  // Restore console
  restoreConsole();
  
  // Restore Date.now if mocked
  restoreDateNow();
});

// Global error handler for unhandled promise rejections
process.on('unhandledRejection', (reason, promise) => {
  console.error('Unhandled Rejection at:', promise, 'reason:', reason);
});

console.log('🧪 Jest setup complete for ChatBot Widget tests');
