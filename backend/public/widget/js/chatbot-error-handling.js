/**
 * 🚨 Chatbot Widget - Error Handling & Rate Limiting Manager
 * 
 * Gestisce tutti gli stati di errore e rate limiting:
 * - Rate limiting detection e UI feedback
 * - Network errors e retry mechanisms
 * - Server errors e fallback states
 * - Authentication errors
 * - Graceful degradation
 * - User-friendly error messages
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 🚨 ERROR TYPES & CONFIGURATIONS
  // =================================================================

  const ERROR_TYPES = {
    RATE_LIMIT: 'rate_limit',
    NETWORK: 'network',
    SERVER: 'server',
    AUTHENTICATION: 'authentication',
    VALIDATION: 'validation',
    TIMEOUT: 'timeout',
    MAINTENANCE: 'maintenance',
    QUOTA_EXCEEDED: 'quota_exceeded',
    SERVICE_UNAVAILABLE: 'service_unavailable',
    UNKNOWN: 'unknown'
  };

  const ERROR_SEVERITIES = {
    LOW: 'low',           // Recoverable, automatic retry
    MEDIUM: 'medium',     // User action suggested
    HIGH: 'high',         // Service degraded
    CRITICAL: 'critical'  // Service unavailable
  };

  const RETRY_CONFIGS = {
    network: { attempts: 3, delay: 1000, backoff: 2 },
    server: { attempts: 2, delay: 2000, backoff: 1.5 },
    timeout: { attempts: 2, delay: 1500, backoff: 2 },
    rate_limit: { attempts: 1, delay: 60000, backoff: 1 },
    default: { attempts: 1, delay: 5000, backoff: 1 }
  };

  // =================================================================
  // 🛠️ ERROR HANDLING MANAGER
  // =================================================================

  class ChatbotErrorHandler {
    constructor(chatbotInstance) {
      this.chatbot = chatbotInstance;
      this.currentErrors = new Map();
      this.retryAttempts = new Map();
      this.rateLimitInfo = null;
      this.maintenanceMode = false;
      this.errorContainer = null;
      
      this.init();
    }

    init() {
      this.createErrorContainer();
      this.setupEventListeners();
      this.loadErrorMessages();
    }

    // =================================================================
    // 📋 ERROR CONTAINER & UI
    // =================================================================

    createErrorContainer() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      // Create error overlay container
      this.errorContainer = document.createElement('div');
      this.errorContainer.className = 'chatbot-error-overlay';
      this.errorContainer.setAttribute('role', 'alert');
      this.errorContainer.setAttribute('aria-live', 'assertive');
      this.errorContainer.setAttribute('aria-atomic', 'true');
      this.errorContainer.style.display = 'none';
      
      container.appendChild(this.errorContainer);
    }

    // =================================================================
    // 🔍 ERROR DETECTION & CLASSIFICATION
    // =================================================================

    classifyError(error, response = null) {
      // Rate limiting detection
      if (response?.status === 429 || error.message?.includes('rate limit')) {
        return {
          type: ERROR_TYPES.RATE_LIMIT,
          severity: ERROR_SEVERITIES.MEDIUM,
          retryAfter: this.extractRetryAfter(response),
          statusCode: 429
        };
      }

      // Network errors
      if (error.name === 'NetworkError' || error.message?.includes('fetch')) {
        return {
          type: ERROR_TYPES.NETWORK,
          severity: ERROR_SEVERITIES.MEDIUM,
          retryable: true
        };
      }

      // Timeout errors
      if (error.name === 'TimeoutError' || error.message?.includes('timeout')) {
        return {
          type: ERROR_TYPES.TIMEOUT,
          severity: ERROR_SEVERITIES.LOW,
          retryable: true
        };
      }

      // Authentication errors
      if (response?.status === 401 || response?.status === 403) {
        return {
          type: ERROR_TYPES.AUTHENTICATION,
          severity: ERROR_SEVERITIES.HIGH,
          statusCode: response.status,
          retryable: false
        };
      }

      // Server errors
      if (response?.status >= 500) {
        return {
          type: ERROR_TYPES.SERVER,
          severity: ERROR_SEVERITIES.HIGH,
          statusCode: response.status,
          retryable: true
        };
      }

      // Quota exceeded
      if (response?.status === 402 || error.message?.includes('quota')) {
        return {
          type: ERROR_TYPES.QUOTA_EXCEEDED,
          severity: ERROR_SEVERITIES.CRITICAL,
          retryable: false
        };
      }

      // Maintenance mode
      if (response?.status === 503 || error.message?.includes('maintenance')) {
        return {
          type: ERROR_TYPES.MAINTENANCE,
          severity: ERROR_SEVERITIES.CRITICAL,
          retryable: true
        };
      }

      // Validation errors
      if (response?.status === 400) {
        return {
          type: ERROR_TYPES.VALIDATION,
          severity: ERROR_SEVERITIES.MEDIUM,
          statusCode: 400,
          retryable: false
        };
      }

      // Default unknown error
      return {
        type: ERROR_TYPES.UNKNOWN,
        severity: ERROR_SEVERITIES.MEDIUM,
        retryable: true
      };
    }

    extractRetryAfter(response) {
      if (!response?.headers) return null;
      
      const retryAfter = response.headers.get('Retry-After');
      if (retryAfter) {
        // Can be seconds or HTTP date
        const seconds = parseInt(retryAfter);
        if (!isNaN(seconds)) {
          return seconds * 1000; // Convert to milliseconds
        }
        
        // Try to parse as date
        const date = new Date(retryAfter);
        if (!isNaN(date.getTime())) {
          return Math.max(0, date.getTime() - Date.now());
        }
      }
      
      return null;
    }

    // =================================================================
    // 🔄 RETRY LOGIC
    // =================================================================

    async handleError(error, context = {}, response = null) {
      const classification = this.classifyError(error, response);
      const errorId = this.generateErrorId(classification, context);
      
      // Store error info
      this.currentErrors.set(errorId, {
        ...classification,
        originalError: error,
        context,
        timestamp: Date.now(),
        attempts: this.getAttemptCount(errorId)
      });

      // Track for analytics
      if (this.chatbot.analytics) {
        this.chatbot.analytics.trackEvent('error_occurred', {
          errorType: classification.type,
          severity: classification.severity,
          statusCode: classification.statusCode,
          context: context.action || 'unknown',
          retryable: classification.retryable
        });
      }

      // Handle specific error types
      switch (classification.type) {
        case ERROR_TYPES.RATE_LIMIT:
          return this.handleRateLimit(errorId, classification);
        
        case ERROR_TYPES.NETWORK:
        case ERROR_TYPES.TIMEOUT:
        case ERROR_TYPES.SERVER:
          return this.handleRetryableError(errorId, classification);
        
        case ERROR_TYPES.AUTHENTICATION:
          return this.handleAuthError(errorId, classification);
        
        case ERROR_TYPES.QUOTA_EXCEEDED:
          return this.handleQuotaExceeded(errorId, classification);
        
        case ERROR_TYPES.MAINTENANCE:
          return this.handleMaintenanceMode(errorId, classification);
        
        default:
          return this.handleGenericError(errorId, classification);
      }
    }

    async handleRateLimit(errorId, classification) {
      const retryAfter = classification.retryAfter || 60000; // Default 1 minute
      
      this.showRateLimitMessage(retryAfter);
      
      // Auto-retry after delay
      setTimeout(() => {
        this.clearError(errorId);
        this.hideErrorMessage();
        if (this.chatbot.accessibility) {
          this.chatbot.accessibility.announce('Rate limit terminato, riprova ora', 'polite');
        }
      }, retryAfter);

      return false; // Don't continue with request
    }

    async handleRetryableError(errorId, classification) {
      const attempts = this.incrementAttempts(errorId);
      const config = RETRY_CONFIGS[classification.type] || RETRY_CONFIGS.default;
      
      if (attempts <= config.attempts) {
        const delay = config.delay * Math.pow(config.backoff, attempts - 1);
        
        this.showRetryMessage(classification, delay, attempts, config.attempts);
        
        // Auto-retry
        return new Promise((resolve) => {
          setTimeout(() => {
            this.hideErrorMessage();
            resolve(true); // Continue with retry
          }, delay);
        });
      } else {
        // Max attempts reached
        this.showMaxRetriesMessage(classification);
        return false;
      }
    }

    async handleAuthError(errorId, classification) {
      this.showAuthenticationError(classification);
      return false;
    }

    async handleQuotaExceeded(errorId, classification) {
      this.showQuotaExceededMessage(classification);
      return false;
    }

    async handleMaintenanceMode(errorId, classification) {
      this.maintenanceMode = true;
      this.showMaintenanceMessage();
      return false;
    }

    async handleGenericError(errorId, classification) {
      this.showGenericErrorMessage(classification);
      return false;
    }

    // =================================================================
    // 📊 RETRY TRACKING
    // =================================================================

    generateErrorId(classification, context) {
      return `${classification.type}_${context.action || 'unknown'}_${Date.now()}`;
    }

    getAttemptCount(errorId) {
      return this.retryAttempts.get(errorId) || 0;
    }

    incrementAttempts(errorId) {
      const current = this.getAttemptCount(errorId);
      const newCount = current + 1;
      this.retryAttempts.set(errorId, newCount);
      return newCount;
    }

    clearError(errorId) {
      this.currentErrors.delete(errorId);
      this.retryAttempts.delete(errorId);
    }

    // =================================================================
    // 💬 ERROR MESSAGES & UI
    // =================================================================

    loadErrorMessages() {
      this.messages = {
        rateLimit: {
          title: '⏱️ Troppo veloce!',
          message: 'Hai raggiunto il limite di messaggi. Riprova tra {time}.',
          action: 'Attendi'
        },
        network: {
          title: '🌐 Problemi di connessione',
          message: 'Verifica la tua connessione internet. Riprovo automaticamente...',
          action: 'Riprova ora'
        },
        server: {
          title: '⚠️ Errore del server',
          message: 'I nostri server stanno avendo problemi. Riprovo automaticamente...',
          action: 'Riprova ora'
        },
        authentication: {
          title: '🔐 Errore di autenticazione',
          message: 'Problema con le credenziali. Contatta l\'amministratore.',
          action: 'Contatta supporto'
        },
        quota: {
          title: '📊 Quota superata',
          message: 'Hai esaurito i messaggi disponibili. Aggiorna il piano.',
          action: 'Aggiorna piano'
        },
        maintenance: {
          title: '🔧 Manutenzione',
          message: 'Stiamo aggiornando il sistema. Torna tra poco.',
          action: 'Riprova più tardi'
        },
        timeout: {
          title: '⏰ Timeout',
          message: 'La richiesta sta impiegando troppo tempo. Riprovo...',
          action: 'Riprova ora'
        },
        maxRetries: {
          title: '❌ Errore persistente',
          message: 'Non riesco a completare l\'operazione. Riprova più tardi.',
          action: 'Riprova'
        },
        generic: {
          title: '❓ Errore imprevisto',
          message: 'Qualcosa è andato storto. Riprova tra poco.',
          action: 'Riprova'
        }
      };
    }

    showRateLimitMessage(retryAfter) {
      const timeString = this.formatTime(retryAfter);
      const config = this.messages.rateLimit;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message.replace('{time}', timeString),
        action: config.action,
        type: 'rate-limit',
        countdown: retryAfter,
        severity: ERROR_SEVERITIES.MEDIUM
      });
    }

    showRetryMessage(classification, delay, attempt, maxAttempts) {
      const config = this.messages[classification.type] || this.messages.generic;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: classification.type,
        countdown: delay,
        attempt: attempt,
        maxAttempts: maxAttempts,
        severity: classification.severity,
        showProgress: true
      });
    }

    showMaxRetriesMessage(classification) {
      const config = this.messages.maxRetries;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: 'max-retries',
        severity: ERROR_SEVERITIES.HIGH,
        allowManualRetry: true
      });
    }

    showAuthenticationError(classification) {
      const config = this.messages.authentication;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: 'authentication',
        severity: ERROR_SEVERITIES.HIGH,
        isPersistent: true
      });
    }

    showQuotaExceededMessage(classification) {
      const config = this.messages.quota;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: 'quota',
        severity: ERROR_SEVERITIES.CRITICAL,
        isPersistent: true
      });
    }

    showMaintenanceMessage() {
      const config = this.messages.maintenance;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: 'maintenance',
        severity: ERROR_SEVERITIES.CRITICAL,
        isPersistent: true
      });
    }

    showGenericErrorMessage(classification) {
      const config = this.messages.generic;
      
      this.showErrorMessage({
        title: config.title,
        message: config.message,
        action: config.action,
        type: 'generic',
        severity: classification.severity,
        allowManualRetry: true
      });
    }

    showErrorMessage(options) {
      if (!this.errorContainer) return;

      // Clear existing content
      this.errorContainer.innerHTML = '';
      
      // Create error content
      const errorContent = document.createElement('div');
      errorContent.className = `chatbot-error-content chatbot-error-${options.type} severity-${options.severity}`;
      
      // Error header
      const header = document.createElement('div');
      header.className = 'chatbot-error-header';
      header.innerHTML = `
        <h3 class="chatbot-error-title">${options.title}</h3>
        ${options.showProgress ? `<span class="chatbot-error-progress">${options.attempt}/${options.maxAttempts}</span>` : ''}
      `;
      
      // Error message
      const message = document.createElement('div');
      message.className = 'chatbot-error-message';
      message.textContent = options.message;
      
      // Action area
      const actions = document.createElement('div');
      actions.className = 'chatbot-error-actions';
      
      // Countdown timer
      if (options.countdown) {
        const countdown = document.createElement('div');
        countdown.className = 'chatbot-error-countdown';
        this.startCountdown(countdown, options.countdown);
        actions.appendChild(countdown);
      }
      
      // Action button
      if (options.allowManualRetry) {
        const retryButton = document.createElement('button');
        retryButton.className = 'chatbot-error-retry-button';
        retryButton.textContent = options.action;
        retryButton.addEventListener('click', () => {
          this.handleManualRetry();
        });
        actions.appendChild(retryButton);
      }
      
      // Close button (only for persistent errors)
      if (options.isPersistent) {
        const closeButton = document.createElement('button');
        closeButton.className = 'chatbot-error-close-button';
        closeButton.innerHTML = '×';
        closeButton.setAttribute('aria-label', 'Chiudi errore');
        closeButton.addEventListener('click', () => {
          this.hideErrorMessage();
        });
        header.appendChild(closeButton);
      }
      
      // Assemble error content
      errorContent.appendChild(header);
      errorContent.appendChild(message);
      errorContent.appendChild(actions);
      
      this.errorContainer.appendChild(errorContent);
      this.errorContainer.style.display = 'block';
      
      // Announce to screen readers
      if (this.chatbot.accessibility) {
        this.chatbot.accessibility.announce(`Errore: ${options.title}. ${options.message}`, 'assertive');
      }
    }

    hideErrorMessage() {
      if (this.errorContainer) {
        this.errorContainer.style.display = 'none';
        this.errorContainer.innerHTML = '';
      }
    }

    // =================================================================
    // ⏰ COUNTDOWN & TIMING
    // =================================================================

    startCountdown(element, duration) {
      let remaining = Math.floor(duration / 1000);
      
      const updateCountdown = () => {
        if (remaining > 0) {
          element.textContent = `Riprovo tra ${this.formatSeconds(remaining)}`;
          remaining--;
          setTimeout(updateCountdown, 1000);
        } else {
          element.textContent = 'Riprovo ora...';
        }
      };
      
      updateCountdown();
    }

    formatTime(ms) {
      const seconds = Math.floor(ms / 1000);
      if (seconds < 60) return `${seconds} secondi`;
      
      const minutes = Math.floor(seconds / 60);
      const remainingSeconds = seconds % 60;
      
      if (minutes < 60) {
        return remainingSeconds > 0 ? `${minutes}m ${remainingSeconds}s` : `${minutes} minuti`;
      }
      
      const hours = Math.floor(minutes / 60);
      const remainingMinutes = minutes % 60;
      return `${hours}h ${remainingMinutes}m`;
    }

    formatSeconds(seconds) {
      if (seconds < 60) return `${seconds}s`;
      const mins = Math.floor(seconds / 60);
      const secs = seconds % 60;
      return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`;
    }

    // =================================================================
    // 🔄 MANUAL RETRY
    // =================================================================

    handleManualRetry() {
      this.hideErrorMessage();
      
      // Clear retry attempts for fresh start
      this.retryAttempts.clear();
      
      // Dispatch retry event
      const event = new CustomEvent('chatbot:manual:retry', {
        detail: { timestamp: Date.now() }
      });
      document.dispatchEvent(event);
      
      // Announce to screen readers
      if (this.chatbot.accessibility) {
        this.chatbot.accessibility.announce('Riprovo...', 'polite');
      }
    }

    // =================================================================
    // 📡 EVENT LISTENERS
    // =================================================================

    setupEventListeners() {
      // Listen for manual retry requests
      document.addEventListener('chatbot:manual:retry', () => {
        // Handled by main widget, just track
        if (this.chatbot.analytics) {
          this.chatbot.analytics.trackEvent('manual_retry', {
            errorCount: this.currentErrors.size,
            timestamp: Date.now()
          });
        }
      });
    }

    // =================================================================
    // 📊 PUBLIC API
    // =================================================================

    // Check if there are active errors
    hasActiveErrors() {
      return this.currentErrors.size > 0;
    }

    // Get current error info
    getErrorInfo() {
      return Array.from(this.currentErrors.values());
    }

    // Check if in maintenance mode
    isMaintenanceMode() {
      return this.maintenanceMode;
    }

    // Clear all errors
    clearAllErrors() {
      this.currentErrors.clear();
      this.retryAttempts.clear();
      this.hideErrorMessage();
      this.maintenanceMode = false;
    }

    // Check if specific error type is active
    hasErrorType(errorType) {
      return Array.from(this.currentErrors.values())
        .some(error => error.type === errorType);
    }

    // Get rate limit info
    getRateLimitInfo() {
      return this.rateLimitInfo;
    }

    // Clean up
    destroy() {
      this.clearAllErrors();
      if (this.errorContainer) {
        this.errorContainer.remove();
      }
    }
  }

  // =================================================================
  // 🌐 GLOBAL EXPORT
  // =================================================================

  // Export to global scope for use by chatbot widget
  window.ChatbotErrorHandler = ChatbotErrorHandler;

})();
