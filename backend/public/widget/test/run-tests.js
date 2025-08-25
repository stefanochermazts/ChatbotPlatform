#!/usr/bin/env node

/**
 * Test Runner Script for ChatBot Widget
 * Provides convenient commands to run different types of tests
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// ANSI color codes for console output
const colors = {
  reset: '\x1b[0m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  magenta: '\x1b[35m',
  cyan: '\x1b[36m',
  white: '\x1b[37m',
  bold: '\x1b[1m'
};

function log(message, color = 'white') {
  console.log(`${colors[color]}${message}${colors.reset}`);
}

function logHeader(message) {
  log(`\n${'='.repeat(50)}`, 'cyan');
  log(`🧪 ${message}`, 'cyan');
  log(`${'='.repeat(50)}`, 'cyan');
}

function logSuccess(message) {
  log(`✅ ${message}`, 'green');
}

function logError(message) {
  log(`❌ ${message}`, 'red');
}

function logWarning(message) {
  log(`⚠️  ${message}`, 'yellow');
}

function logInfo(message) {
  log(`ℹ️  ${message}`, 'blue');
}

function execCommand(command, options = {}) {
  try {
    const result = execSync(command, {
      stdio: 'inherit',
      cwd: __dirname,
      ...options
    });
    return { success: true, result };
  } catch (error) {
    return { success: false, error };
  }
}

function checkDependencies() {
  logHeader('Checking Dependencies');
  
  const packageJsonPath = path.join(__dirname, 'package.json');
  
  if (!fs.existsSync(packageJsonPath)) {
    logError('package.json not found. Please run npm init first.');
    return false;
  }
  
  const nodeModulesPath = path.join(__dirname, 'node_modules');
  
  if (!fs.existsSync(nodeModulesPath)) {
    logWarning('node_modules not found. Installing dependencies...');
    
    const installResult = execCommand('npm install');
    if (!installResult.success) {
      logError('Failed to install dependencies.');
      return false;
    }
    
    logSuccess('Dependencies installed successfully.');
  }
  
  return true;
}

function runUnitTests() {
  logHeader('Running Unit Tests');
  
  const result = execCommand('npm run test:unit');
  
  if (result.success) {
    logSuccess('Unit tests passed!');
  } else {
    logError('Unit tests failed!');
  }
  
  return result.success;
}

function runIntegrationTests() {
  logHeader('Running Integration Tests');
  
  const result = execCommand('npm run test:integration');
  
  if (result.success) {
    logSuccess('Integration tests passed!');
  } else {
    logError('Integration tests failed!');
  }
  
  return result.success;
}

function runAccessibilityTests() {
  logHeader('Running Accessibility Tests');
  
  const result = execCommand('npm run test:accessibility');
  
  if (result.success) {
    logSuccess('Accessibility tests passed!');
  } else {
    logError('Accessibility tests failed!');
  }
  
  return result.success;
}

function runE2ETests() {
  logHeader('Running E2E Tests');
  
  logInfo('Starting headless browser tests...');
  
  const result = execCommand('npm run test:e2e');
  
  if (result.success) {
    logSuccess('E2E tests passed!');
  } else {
    logError('E2E tests failed!');
  }
  
  return result.success;
}

function generateCoverageReport() {
  logHeader('Generating Coverage Report');
  
  const result = execCommand('npm run test:coverage');
  
  if (result.success) {
    logSuccess('Coverage report generated!');
    logInfo('Open coverage/lcov-report/index.html to view detailed coverage.');
  } else {
    logError('Failed to generate coverage report!');
  }
  
  return result.success;
}

function runLinting() {
  logHeader('Running Code Linting');
  
  const result = execCommand('npm run lint');
  
  if (result.success) {
    logSuccess('Code passes linting checks!');
  } else {
    logWarning('Linting issues found. Run "npm run lint:fix" to auto-fix.');
  }
  
  return result.success;
}

function runAllTests() {
  logHeader('Running Complete Test Suite');
  
  const results = {
    dependencies: checkDependencies(),
    linting: false,
    unit: false,
    integration: false,
    accessibility: false,
    e2e: false,
    coverage: false
  };
  
  if (!results.dependencies) {
    logError('Dependency check failed. Aborting test run.');
    return false;
  }
  
  // Run linting
  results.linting = runLinting();
  
  // Run test suites
  results.unit = runUnitTests();
  results.integration = runIntegrationTests();
  results.accessibility = runAccessibilityTests();
  results.e2e = runE2ETests();
  
  // Generate coverage report
  results.coverage = generateCoverageReport();
  
  // Summary
  logHeader('Test Results Summary');
  
  Object.entries(results).forEach(([testType, passed]) => {
    const status = passed ? 'PASSED' : 'FAILED';
    const color = passed ? 'green' : 'red';
    const icon = passed ? '✅' : '❌';
    
    log(`${icon} ${testType.toUpperCase()}: ${status}`, color);
  });
  
  const allPassed = Object.values(results).every(result => result);
  
  if (allPassed) {
    log('\n🎉 All tests passed! Widget is ready for production.', 'bold');
  } else {
    log('\n🚨 Some tests failed. Please review and fix issues before deployment.', 'bold');
  }
  
  return allPassed;
}

function showHelp() {
  log('\n🧪 ChatBot Widget Test Runner', 'bold');
  log('\nUsage: node run-tests.js [command]\n');
  
  log('Commands:', 'cyan');
  log('  all           Run all test suites (default)');
  log('  unit          Run unit tests only');
  log('  integration   Run integration tests only');
  log('  accessibility Run accessibility tests only');
  log('  e2e           Run end-to-end tests only');
  log('  coverage      Generate coverage report');
  log('  lint          Run code linting');
  log('  deps          Check/install dependencies');
  log('  help          Show this help message');
  
  log('\nExamples:', 'yellow');
  log('  node run-tests.js');
  log('  node run-tests.js unit');
  log('  node run-tests.js coverage');
  
  log('\nEnvironment Variables:', 'magenta');
  log('  CI=true           Run in CI mode (headless)');
  log('  DEBUG=true        Enable debug output');
  log('  COVERAGE=true     Include coverage in all runs');
}

function main() {
  const command = process.argv[2] || 'all';
  
  switch (command.toLowerCase()) {
    case 'all':
    case 'test':
      runAllTests();
      break;
      
    case 'unit':
      if (checkDependencies()) {
        runUnitTests();
      }
      break;
      
    case 'integration':
      if (checkDependencies()) {
        runIntegrationTests();
      }
      break;
      
    case 'accessibility':
    case 'a11y':
      if (checkDependencies()) {
        runAccessibilityTests();
      }
      break;
      
    case 'e2e':
    case 'end-to-end':
      if (checkDependencies()) {
        runE2ETests();
      }
      break;
      
    case 'coverage':
      if (checkDependencies()) {
        generateCoverageReport();
      }
      break;
      
    case 'lint':
      if (checkDependencies()) {
        runLinting();
      }
      break;
      
    case 'deps':
    case 'dependencies':
      checkDependencies();
      break;
      
    case 'help':
    case '--help':
    case '-h':
      showHelp();
      break;
      
    default:
      logError(`Unknown command: ${command}`);
      showHelp();
      process.exit(1);
  }
}

// Handle process termination gracefully
process.on('SIGINT', () => {
  log('\n\n🛑 Test run interrupted by user.', 'yellow');
  process.exit(130);
});

process.on('SIGTERM', () => {
  log('\n\n🛑 Test run terminated.', 'yellow');
  process.exit(143);
});

// Run main function
if (require.main === module) {
  main();
}

module.exports = {
  runAllTests,
  runUnitTests,
  runIntegrationTests,
  runAccessibilityTests,
  runE2ETests,
  generateCoverageReport,
  runLinting,
  checkDependencies
};
