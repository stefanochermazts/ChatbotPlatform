/**
 * ChatBot Fallback Manager
 * Handles fallback states for network errors, maintenance, rate limits, etc.
 */
class ChatbotFallbackManager {
    constructor(widget) {
        this.widget = widget;
        this.state = {
            isOffline: false,
            isMaintenanceMode: false,
            isRateLimited: false,
            lastError: null,
            retryCount: 0,
            maxRetries: 3,
            fallbackMessagesEnabled: true
        };
        
        this.fallbackMessages = {
            offline: {
                title: 'Connessione non disponibile',
                message: 'Sembra che tu sia offline. Le tue domande verranno inviate quando la connessione sarà ripristinata.',
                icon: '📶',
                actions: [
                    { label: 'Riprova', action: 'retry' },
                    { label: 'Modalità offline', action: 'offline_mode' }
                ]
            },
            maintenance: {
                title: 'Manutenzione in corso',
                message: 'Il servizio è temporaneamente non disponibile per manutenzione. Riprova tra qualche minuto.',
                icon: '🔧',
                actions: [
                    { label: 'Riprova tra 1 min', action: 'retry_delayed' }
                ]
            },
            rateLimit: {
                title: 'Troppi messaggi',
                message: 'Hai inviato troppi messaggi di recente. Riprova tra {retryAfter} secondi.',
                icon: '⏰',
                actions: [
                    { label: 'Riprova più tardi', action: 'wait' }
                ]
            },
            serverError: {
                title: 'Errore del servizio',
                message: 'Si è verificato un errore temporaneo. Il nostro team è stato notificato.',
                icon: '⚠️',
                actions: [
                    { label: 'Riprova', action: 'retry' },
                    { label: 'Contatta supporto', action: 'contact_support' }
                ]
            },
            apiKeyInvalid: {
                title: 'Configurazione non valida',
                message: 'Si è verificato un problema di configurazione. Contatta l\'amministratore del sito.',
                icon: '🔑',
                actions: [
                    { label: 'Contatta supporto', action: 'contact_support' }
                ]
            },
            timeout: {
                title: 'Timeout della richiesta',
                message: 'La richiesta sta impiegando più tempo del previsto. Vuoi continuare ad aspettare?',
                icon: '⏱️',
                actions: [
                    { label: 'Continua ad aspettare', action: 'wait_longer' },
                    { label: 'Riprova', action: 'retry' }
                ]
            },
            degraded: {
                title: 'Modalità ridotta',
                message: 'Alcune funzionalità potrebbero non essere disponibili. Puoi comunque inviare messaggi.',
                icon: '⚡',
                actions: [
                    { label: 'Continua', action: 'continue' }
                ]
            }
        };
        
        this.fallbackContainer = null;
        this.offlineQueue = [];
        this.retryTimers = new Map();
        
        this.init();
    }
    
    init() {
        // Check if widget is properly configured
        if (!this.widget.options) {
            console.warn('FallbackManager: Widget not properly configured, delaying initialization');
            // Retry initialization after a short delay (max 3 attempts)
            if (!this.initAttempts) this.initAttempts = 0;
            if (this.initAttempts < 3) {
                this.initAttempts++;
                setTimeout(() => this.init(), 100);
            }
            return;
        }
        
        this.setupNetworkMonitoring();
        this.setupErrorHandling();
        this.setupRetryMechanism();
        this.createFallbackUI();
    }
    
    setupNetworkMonitoring() {
        // Monitor online/offline status
        window.addEventListener('online', () => {
            this.handleOnline();
        });
        
        window.addEventListener('offline', () => {
            this.handleOffline();
        });
        
        // Check initial state
        this.state.isOffline = !navigator.onLine;
        
        // Periodic connectivity check
        this.connectivityCheckInterval = setInterval(() => {
            this.checkConnectivity();
        }, 30000); // Check every 30 seconds
    }
    
    setupErrorHandling() {
        // Listen to widget errors
        this.widget.on('error', (error) => {
            this.handleError(error);
        });
        
        // Listen to network errors
        this.widget.on('network:error', (error) => {
            this.handleNetworkError(error);
        });
        
        // Listen to API errors
        this.widget.on('api:error', (error) => {
            this.handleApiError(error);
        });
    }
    
    setupRetryMechanism() {
        // Setup exponential backoff for retries
        this.retryDelays = [1000, 2000, 5000, 10000]; // 1s, 2s, 5s, 10s
    }
    
    createFallbackUI() {
        this.fallbackContainer = document.createElement('div');
        this.fallbackContainer.className = 'chatbot-fallback-container';
        this.fallbackContainer.style.display = 'none';
        this.fallbackContainer.setAttribute('role', 'alert');
        this.fallbackContainer.setAttribute('aria-live', 'polite');
        
        // Insert after widget header (only if container exists)
        if (this.widget.container) {
            const widgetContent = this.widget.container.querySelector('.chatbot-content, .widget-content');
            if (widgetContent) {
                widgetContent.appendChild(this.fallbackContainer);
            }
        }
        // If container doesn't exist yet, we'll try to attach it later
    }
    
    async checkConnectivity() {
        try {
            // Try to fetch a small resource to test connectivity
            const response = await fetch((this.widget.options?.baseURL || '') + '/api/health', {
                method: 'HEAD',
                timeout: 5000
            });
            
            if (response.ok) {
                if (this.state.isOffline) {
                    this.handleOnline();
                }
            } else {
                this.handleOffline();
            }
        } catch (error) {
            this.handleOffline();
        }
    }
    
    handleOnline() {
        if (this.state.isOffline) {
            this.state.isOffline = false;
            this.hideFallbackState();
            this.processOfflineQueue();
            
            // Show reconnection message
            this.showTemporaryMessage('✅ Connessione ripristinata', 'success');
            
            // Track analytics
            if (this.widget.analytics) {
                this.widget.analytics.trackEvent('connection_restored', {
                    offline_duration: Date.now() - this.state.offlineStartTime,
                    queued_messages: this.offlineQueue.length
                });
            }
        }
    }
    
    handleOffline() {
        if (!this.state.isOffline) {
            this.state.isOffline = true;
            this.state.offlineStartTime = Date.now();
            this.showFallbackState('offline');
            
            // Track analytics
            if (this.widget.analytics) {
                this.widget.analytics.trackEvent('connection_lost', {
                    last_successful_request: this.widget.lastSuccessfulRequest
                });
            }
        }
    }
    
    handleError(error) {
        this.state.lastError = error;
        
        switch (error.type) {
            case 'network':
                this.handleNetworkError(error);
                break;
            case 'api':
                this.handleApiError(error);
                break;
            case 'timeout':
                this.showFallbackState('timeout');
                break;
            case 'rate_limit':
                this.handleRateLimit(error);
                break;
            default:
                this.showFallbackState('serverError');
        }
    }
    
    handleNetworkError(error) {
        if (error.message && error.message.includes('Failed to fetch')) {
            this.handleOffline();
        } else {
            this.showFallbackState('serverError');
        }
    }
    
    handleApiError(error) {
        switch (error.status) {
            case 401:
            case 403:
                this.showFallbackState('apiKeyInvalid');
                break;
            case 429:
                this.handleRateLimit(error);
                break;
            case 503:
                this.showFallbackState('maintenance');
                break;
            case 500:
            case 502:
            case 503:
            case 504:
                this.showFallbackState('serverError');
                break;
            default:
                this.showFallbackState('serverError');
        }
    }
    
    handleRateLimit(error) {
        const retryAfter = error.retryAfter || 60;
        this.state.isRateLimited = true;
        this.state.rateLimitRetryAfter = retryAfter;
        
        this.showFallbackState('rateLimit', { retryAfter });
        
        // Auto-clear rate limit after specified time
        setTimeout(() => {
            this.state.isRateLimited = false;
            this.hideFallbackState();
        }, retryAfter * 1000);
    }
    
    showFallbackState(type, params = {}) {
        const config = this.fallbackMessages[type];
        if (!config) return;
        
        // Clear any existing retry timers
        this.clearRetryTimers();
        
        // Format message with parameters
        let message = config.message;
        Object.keys(params).forEach(key => {
            message = message.replace(`{${key}}`, params[key]);
        });
        
        // Create fallback UI
        this.fallbackContainer.innerHTML = `
            <div class="fallback-content fallback-${type}">
                <div class="fallback-icon">${config.icon}</div>
                <div class="fallback-text">
                    <h4 class="fallback-title">${config.title}</h4>
                    <p class="fallback-message">${message}</p>
                </div>
                <div class="fallback-actions">
                    ${config.actions.map((action, index) => `
                        <button 
                            class="fallback-action-btn ${action.action === 'retry' ? 'primary' : 'secondary'}"
                            data-action="${action.action}"
                            data-index="${index}"
                        >
                            ${action.label}
                        </button>
                    `).join('')}
                </div>
            </div>
        `;
        
        // Show container
        this.fallbackContainer.style.display = 'block';
        
        // Setup action handlers
        this.setupFallbackActions();
        
        // Disable main input if appropriate
        if (['offline', 'maintenance', 'apiKeyInvalid'].includes(type)) {
            this.disableWidgetInput();
        }
        
        // Track analytics
        if (this.widget.analytics) {
            this.widget.analytics.trackEvent('fallback_state_shown', {
                type,
                error_context: this.state.lastError
            });
        }
    }
    
    hideFallbackState() {
        if (this.fallbackContainer) {
            this.fallbackContainer.style.display = 'none';
            this.fallbackContainer.innerHTML = '';
        }
        
        this.enableWidgetInput();
        this.clearRetryTimers();
    }
    
    setupFallbackActions() {
        const actionButtons = this.fallbackContainer.querySelectorAll('.fallback-action-btn');
        
        actionButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const action = e.target.getAttribute('data-action');
                this.handleFallbackAction(action);
            });
        });
    }
    
    handleFallbackAction(action) {
        switch (action) {
            case 'retry':
                this.retryLastAction();
                break;
            case 'retry_delayed':
                this.scheduleRetry(60000); // 1 minute
                break;
            case 'offline_mode':
                this.enableOfflineMode();
                break;
            case 'contact_support':
                this.triggerSupportContact();
                break;
            case 'wait':
                this.hideFallbackState();
                break;
            case 'wait_longer':
                this.extendTimeout();
                break;
            case 'continue':
                this.enableDegradedMode();
                break;
        }
        
        // Track action
        if (this.widget.analytics) {
            this.widget.analytics.trackEvent('fallback_action_taken', {
                action,
                fallback_type: this.getCurrentFallbackType()
            });
        }
    }
    
    retryLastAction() {
        if (this.state.retryCount < this.state.maxRetries) {
            this.state.retryCount++;
            this.hideFallbackState();
            
            // Show retry indicator
            this.showTemporaryMessage('🔄 Nuovo tentativo in corso...', 'info');
            
            // Retry the last action
            if (this.widget.lastFailedAction) {
                const delay = this.retryDelays[Math.min(this.state.retryCount - 1, this.retryDelays.length - 1)];
                
                setTimeout(() => {
                    this.widget.lastFailedAction.retry();
                }, delay);
            }
        } else {
            this.showFallbackState('serverError');
        }
    }
    
    scheduleRetry(delay) {
        this.hideFallbackState();
        this.showTemporaryMessage(`⏰ Nuovo tentativo previsto tra ${Math.round(delay / 1000)} secondi`, 'info');
        
        const timerId = setTimeout(() => {
            this.retryLastAction();
        }, delay);
        
        this.retryTimers.set('scheduled_retry', timerId);
    }
    
    enableOfflineMode() {
        this.hideFallbackState();
        this.showTemporaryMessage('📱 Modalità offline attivata. I messaggi verranno inviati quando tornerai online.', 'info');
        
        // Enable message queuing
        this.state.offlineModeEnabled = true;
        this.enableWidgetInput();
    }
    
    enableDegradedMode() {
        this.hideFallbackState();
        this.showTemporaryMessage('⚡ Modalità ridotta attivata. Alcune funzionalità potrebbero non essere disponibili.', 'warning');
        
        this.state.degradedModeEnabled = true;
        this.enableWidgetInput();
    }
    
    triggerSupportContact() {
        // Try to trigger support quick action
        if (this.widget.quickActions) {
            const supportAction = this.widget.quickActions.actions.find(a => a.type === 'contact_support');
            if (supportAction) {
                this.widget.quickActions.handleActionClick(supportAction);
                return;
            }
        }
        
        // Fallback: show contact information
        this.showContactInfo();
    }
    
    showContactInfo() {
        const contactModal = document.createElement('div');
        contactModal.className = 'fallback-contact-modal';
        contactModal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Contatta il Supporto</h3>
                    <button class="modal-close" aria-label="Chiudi">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Puoi contattarci attraverso questi canali:</p>
                    <ul>
                        <li>📧 Email: supporto@${window.location.hostname}</li>
                        <li>📞 Telefono: Controlla i nostri orari sul sito</li>
                        <li>💬 Chat: Riprova più tardi quando il servizio sarà disponibile</li>
                    </ul>
                </div>
                <div class="modal-actions">
                    <button class="btn-primary modal-close">Chiudi</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(contactModal);
        
        // Handle close
        contactModal.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', () => {
                document.body.removeChild(contactModal);
            });
        });
    }
    
    extendTimeout() {
        this.hideFallbackState();
        this.showTemporaryMessage('⏳ Tempo di attesa prolungato...', 'info');
        
        // Extend the current request timeout if possible
        if (this.widget.currentRequest) {
            this.widget.currentRequest.timeout *= 2;
        }
    }
    
    disableWidgetInput() {
        const input = this.widget.container.querySelector('input, textarea');
        const sendButton = this.widget.container.querySelector('.send-button, [type="submit"]');
        
        if (input) {
            input.disabled = true;
            input.placeholder = 'Servizio temporaneamente non disponibile';
        }
        
        if (sendButton) {
            sendButton.disabled = true;
        }
    }
    
    enableWidgetInput() {
        const input = this.widget.container.querySelector('input, textarea');
        const sendButton = this.widget.container.querySelector('.send-button, [type="submit"]');
        
        if (input) {
            input.disabled = false;
            input.placeholder = this.widget.config.placeholder || 'Scrivi un messaggio...';
        }
        
        if (sendButton) {
            sendButton.disabled = false;
        }
    }
    
    queueMessage(message) {
        if (this.state.isOffline || this.state.offlineModeEnabled) {
            this.offlineQueue.push({
                content: message,
                timestamp: Date.now(),
                id: 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)
            });
            
            this.showTemporaryMessage(`💾 Messaggio salvato (${this.offlineQueue.length} in coda)`, 'info');
            return true;
        }
        
        return false;
    }
    
    async processOfflineQueue() {
        if (this.offlineQueue.length === 0) return;
        
        this.showTemporaryMessage(`📤 Invio di ${this.offlineQueue.length} messaggi in coda...`, 'info');
        
        const queue = [...this.offlineQueue];
        this.offlineQueue = [];
        
        for (const message of queue) {
            try {
                await this.widget.sendMessage(message.content);
                await new Promise(resolve => setTimeout(resolve, 1000)); // Delay between messages
            } catch (error) {
                // Re-queue failed messages
                this.offlineQueue.push(message);
            }
        }
        
        if (this.offlineQueue.length > 0) {
            this.showTemporaryMessage(`⚠️ ${this.offlineQueue.length} messaggi non inviati, riproverò più tardi`, 'warning');
        } else {
            this.showTemporaryMessage('✅ Tutti i messaggi sono stati inviati', 'success');
        }
    }
    
    showTemporaryMessage(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `fallback-notification notification-${type}`;
        notification.textContent = message;
        notification.setAttribute('role', 'status');
        notification.setAttribute('aria-live', 'polite');
        
        this.widget.container.appendChild(notification);
        
        // Auto-remove after delay
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }
    
    getCurrentFallbackType() {
        const fallbackContent = this.fallbackContainer.querySelector('[class*="fallback-"]');
        if (fallbackContent) {
            const classList = Array.from(fallbackContent.classList);
            const typeClass = classList.find(cls => cls.startsWith('fallback-'));
            return typeClass ? typeClass.replace('fallback-', '') : 'unknown';
        }
        return null;
    }
    
    clearRetryTimers() {
        this.retryTimers.forEach(timerId => {
            clearTimeout(timerId);
        });
        this.retryTimers.clear();
    }
    
    // Public methods
    attachToWidget() {
        // Try to attach fallback container to widget if not already attached
        if (this.fallbackContainer && this.widget.container && !this.fallbackContainer.parentNode) {
            const widgetContent = this.widget.container.querySelector('.chatbot-content, .widget-content, .chatbot-widget');
            if (widgetContent) {
                widgetContent.appendChild(this.fallbackContainer);
            } else {
                // Fallback: attach directly to widget container
                this.widget.container.appendChild(this.fallbackContainer);
            }
        }
    }
    
    reset() {
        this.state.retryCount = 0;
        this.state.lastError = null;
        this.hideFallbackState();
        this.clearRetryTimers();
    }
    
    forceOfflineMode() {
        this.state.isOffline = true;
        this.showFallbackState('offline');
    }
    
    forceMaintenanceMode() {
        this.state.isMaintenanceMode = true;
        this.showFallbackState('maintenance');
    }
    
    isInFallbackState() {
        return this.fallbackContainer && this.fallbackContainer.style.display !== 'none';
    }
    
    destroy() {
        if (this.connectivityCheckInterval) {
            clearInterval(this.connectivityCheckInterval);
        }
        
        this.clearRetryTimers();
        
        if (this.fallbackContainer && this.fallbackContainer.parentNode) {
            this.fallbackContainer.parentNode.removeChild(this.fallbackContainer);
        }
    }
}

// Export for use in main widget
window.ChatbotFallbackManager = ChatbotFallbackManager;
