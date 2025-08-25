# 🧪 ChatBot Widget Test Suite

Comprehensive testing framework for the ChatBot Widget Platform, ensuring quality, accessibility, and reliability across all components.

## 📋 Overview

This test suite provides multiple layers of testing:

- **Unit Tests**: Test individual components and functions
- **Integration Tests**: Test component interactions and workflows  
- **Accessibility Tests**: Ensure WCAG 2.1 AA compliance
- **End-to-End Tests**: Test complete user journeys
- **Performance Tests**: Monitor load times and memory usage

## 🚀 Quick Start

### Prerequisites

- Node.js 16+ and npm
- Modern browser (Chrome, Firefox, Safari)
- Internet connection (for downloading dependencies)

### Installation

```bash
# Navigate to test directory
cd backend/public/widget/test

# Install dependencies
npm install

# Run all tests
npm test
```

### Alternative Setup

```bash
# Use the test runner script
node run-tests.js deps  # Install dependencies
node run-tests.js all   # Run all tests
```

## 🎯 Test Commands

### NPM Scripts

```bash
# Run all tests
npm test
npm run test:all

# Run specific test types
npm run test:unit           # Unit tests only
npm run test:integration    # Integration tests only
npm run test:accessibility  # Accessibility tests only
npm run test:e2e           # End-to-end tests only

# Development commands
npm run test:watch         # Watch mode for development
npm run test:coverage      # Generate coverage report
npm run lint              # Code linting
npm run lint:fix          # Auto-fix linting issues
```

### Test Runner Script

```bash
# Run all tests with detailed output
node run-tests.js

# Run specific test suites
node run-tests.js unit
node run-tests.js integration
node run-tests.js accessibility
node run-tests.js e2e

# Utility commands
node run-tests.js coverage
node run-tests.js lint
node run-tests.js deps
node run-tests.js help
```

## 📁 Test Structure

```
test/
├── setup.js                    # Jest configuration and global utilities
├── package.json                # Test dependencies and scripts
├── run-tests.js                # Test runner utility
├── README.md                   # This documentation
├── unit/                       # Unit tests
│   ├── widget-core.test.js     # Core widget functionality
│   ├── quick-actions.test.js   # Quick actions component
│   ├── analytics.test.js       # Analytics tracking
│   └── ...
├── integration/                # Integration tests
│   ├── widget-integration.test.js  # Component interactions
│   ├── api-integration.test.js     # API integrations
│   └── ...
├── accessibility/              # Accessibility tests
│   ├── wcag-compliance.test.js     # WCAG 2.1 AA compliance
│   ├── screen-reader.test.js       # Screen reader compatibility
│   └── ...
├── e2e/                        # End-to-end tests
│   ├── user-journey.test.js        # Complete user workflows
│   ├── cross-browser.test.js       # Browser compatibility
│   └── ...
└── coverage/                   # Coverage reports (generated)
    ├── lcov-report/
    └── ...
```

## 🧪 Test Types

### Unit Tests

Test individual components and functions in isolation.

**What's tested:**
- Widget initialization and configuration
- Message handling and state management
- Event system and lifecycle methods
- Utility functions and helpers
- Error handling and validation

**Example:**
```javascript
test('should initialize widget with valid config', () => {
  const widget = new ChatbotWidget(container, {
    apiKey: 'test-key',
    tenantId: 1
  });
  
  expect(widget.config.apiKey).toBe('test-key');
  expect(widget.state.isOpen).toBe(false);
});
```

### Integration Tests

Test how components work together and interact with external systems.

**What's tested:**
- Component communication and data flow
- API integrations and error handling
- Quick actions with form handling
- Analytics tracking and reporting
- Dark mode and theming integration

**Example:**
```javascript
test('should execute quick action with form', async () => {
  widget.open();
  
  const actionButton = container.querySelector('[data-action-type="contact_support"]');
  simulateMouse(actionButton, 'click');
  
  // Form appears and can be filled
  const modal = await waitForMutation(() => document.querySelector('.quick-action-modal'));
  // ... test form interaction
});
```

### Accessibility Tests

Ensure the widget meets WCAG 2.1 AA standards and works with assistive technologies.

**What's tested:**
- ARIA attributes and roles
- Keyboard navigation and focus management
- Screen reader compatibility
- Color contrast and visual accessibility
- Touch targets and mobile accessibility

**Example:**
```javascript
test('should pass axe accessibility audit', async () => {
  const results = await axe(container);
  expect(results).toHaveNoViolations();
});
```

### End-to-End Tests

Test complete user workflows using real browsers with Puppeteer.

**What's tested:**
- Widget loading and initialization
- User interaction flows
- Cross-browser compatibility
- Performance and loading times
- Error recovery and offline handling

**Example:**
```javascript
test('should send and receive messages', async () => {
  await page.click('.chatbot-trigger');
  await page.type('input', 'Hello test message');
  await page.keyboard.press('Enter');
  
  await page.waitForSelector('.bot-message');
  // ... verify response
});
```

## 📊 Coverage Requirements

The test suite enforces minimum coverage thresholds:

- **Branches**: 80%
- **Functions**: 80%
- **Lines**: 80%
- **Statements**: 80%

### Viewing Coverage

```bash
# Generate coverage report
npm run test:coverage

# Open detailed HTML report
open coverage/lcov-report/index.html
```

## 🔧 Configuration

### Jest Configuration

Located in `package.json` under the `jest` key:

```json
{
  "jest": {
    "testEnvironment": "jsdom",
    "setupFilesAfterEnv": ["<rootDir>/test/setup.js"],
    "coverageThreshold": {
      "global": {
        "branches": 80,
        "functions": 80,
        "lines": 80,
        "statements": 80
      }
    }
  }
}
```

### Environment Variables

```bash
# Continuous Integration mode
CI=true npm test

# Debug mode with verbose output
DEBUG=true npm test

# Include coverage in all test runs
COVERAGE=true npm test

# Headless browser mode for E2E tests
HEADLESS=true npm run test:e2e
```

## 🐛 Debugging Tests

### Debug Failed Tests

```bash
# Run tests in watch mode
npm run test:watch

# Run specific test file
npm test -- widget-core.test.js

# Run tests with debug output
DEBUG=true npm test

# Run single test case
npm test -- --testNamePattern="should initialize widget"
```

### Debug E2E Tests

```bash
# Run E2E tests with visible browser
HEADLESS=false npm run test:e2e

# Slow down test execution for debugging
SLOWMO=250 npm run test:e2e

# Enable Puppeteer debug mode
DEBUG=puppeteer:* npm run test:e2e
```

### Common Issues

**Dependencies not found:**
```bash
node run-tests.js deps
```

**Tests failing in CI:**
- Check that all dependencies are installed
- Verify environment variables are set correctly
- Ensure browser binaries are available

**Accessibility tests failing:**
- Check ARIA attributes and roles
- Verify keyboard navigation works
- Test with actual screen readers

## 📈 Performance Testing

### Metrics Monitored

- Widget initialization time (< 100ms)
- First interaction response (< 500ms)
- Memory usage (< 50MB)
- Network requests optimization
- Bundle size and load times

### Performance Budgets

```javascript
// Example performance assertions
expect(initTime).toBeLessThan(100);
expect(responseTime).toBeLessThan(500);
expect(memoryUsage).toBeLessThan(50 * 1024 * 1024);
```

## 🌐 Cross-Browser Testing

### Supported Browsers

- **Chrome**: Latest stable
- **Firefox**: Latest stable  
- **Safari**: Latest stable
- **Edge**: Latest stable

### Mobile Testing

- **iOS Safari**: Latest 2 versions
- **Android Chrome**: Latest 2 versions

### Running Cross-Browser Tests

```bash
# Run E2E tests across all browsers
BROWSERS=chrome,firefox,safari npm run test:e2e

# Test specific browser
BROWSER=firefox npm run test:e2e
```

## 🔄 Continuous Integration

### GitHub Actions Example

```yaml
name: Widget Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup Node.js
      uses: actions/setup-node@v2
      with:
        node-version: '18'
        
    - name: Install dependencies
      run: |
        cd backend/public/widget/test
        npm ci
        
    - name: Run tests
      run: |
        cd backend/public/widget/test
        CI=true npm run test:all
        
    - name: Upload coverage
      uses: codecov/codecov-action@v1
      with:
        file: ./backend/public/widget/test/coverage/lcov.info
```

## 📚 Writing New Tests

### Test Naming Convention

```javascript
// ✅ Good
describe('Widget Core Functionality', () => {
  test('should initialize with valid configuration', () => {
    // Test implementation
  });
});

// ❌ Avoid
describe('Tests', () => {
  test('test1', () => {
    // Unclear test purpose
  });
});
```

### Test Structure

```javascript
describe('Component Name', () => {
  let component;
  let container;
  
  beforeEach(() => {
    container = createWidgetContainer();
    component = new Component(container);
  });
  
  afterEach(() => {
    component.destroy?.();
    cleanupDOM();
  });
  
  describe('Feature Group', () => {
    test('should behave as expected', () => {
      // Arrange
      const input = 'test input';
      
      // Act
      const result = component.process(input);
      
      // Assert
      expect(result).toBe('expected output');
    });
  });
});
```

### Best Practices

1. **Descriptive Names**: Use clear, descriptive test names
2. **Single Responsibility**: Test one thing at a time
3. **Arrange-Act-Assert**: Structure tests clearly
4. **Independent Tests**: Tests should not depend on each other
5. **Mock External Dependencies**: Use mocks for API calls, etc.
6. **Test Edge Cases**: Include error conditions and edge cases

## 🤝 Contributing

### Adding New Tests

1. Identify the component/feature to test
2. Choose appropriate test type (unit/integration/e2e)
3. Create test file in correct directory
4. Follow naming conventions and patterns
5. Ensure tests pass and add value
6. Update documentation if needed

### Test Review Checklist

- [ ] Tests have descriptive names
- [ ] All test cases pass
- [ ] Edge cases are covered
- [ ] Mock data is realistic
- [ ] No test dependencies
- [ ] Performance impact considered
- [ ] Accessibility considered
- [ ] Documentation updated

## 🆘 Support

### Getting Help

- Check existing test files for examples
- Review Jest and Puppeteer documentation
- Run `node run-tests.js help` for commands
- Check console output for specific errors

### Common Commands

```bash
# Quick test run
npm test

# Full test suite with coverage
node run-tests.js

# Fix linting issues
npm run lint:fix

# Clean start
rm -rf node_modules && npm install
```

---

**Happy Testing! 🧪✨**

*Ensure your widget changes don't break existing functionality by running the test suite before committing.*
