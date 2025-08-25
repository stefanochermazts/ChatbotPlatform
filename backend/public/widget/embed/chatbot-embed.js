/**
 * 📦 Chatbot Widget - Embedding Script
 * 
 * Script di embedding per integrare facilmente il widget sui siti clienti
 * Caricamento asincrono, configurazione flessibile, isolamento CSS/JS
 * 
 * Usage:
 * <script>
 *   window.chatbotConfig = {
 *     apiKey: 'your-api-key',
 *     tenantId: 'your-tenant-id',
 *     theme: 'corporate'
 *   };
 * </script>
 * <script src="https://your-domain.com/widget/embed/chatbot-embed.js" async></script>
 * 
 * @version 1.0.0
 */

(function() {
  'use strict';

  // =================================================================
  // 🔌 CONFIGURATION
  // =================================================================

  const EMBED_CONFIG = {
    // Widget resources
    baseURL: (function() {
      const script = document.currentScript;
      if (script && script.src) {
        // Extract the base domain without /widget path
        const url = new URL(script.src);
        return url.origin;
      }
      return ''; // Fallback - use relative paths
    })(),
    
    // Files to load
                  files: {
            css: [
                '/widget/css/chatbot-design-system.css',
                '/widget/css/chatbot-widget.css',
                '/widget/css/chatbot-accessibility.css',
                '/widget/css/chatbot-citations.css',
                '/widget/css/chatbot-responsive.css',
                '/widget/css/chatbot-dark-mode.css',
                '/widget/css/chatbot-error-handling.css',
                '/widget/css/chatbot-quick-actions.css',
                '/widget/css/chatbot-fallback-states.css',
                '/widget/css/chatbot-forms.css'
            ],
            js: [
                '/widget/js/chatbot-accessibility.js',
                '/widget/js/chatbot-citations.js',
                '/widget/js/chatbot-responsive.js',
                '/widget/js/chatbot-dark-mode.js',
                '/widget/js/chatbot-error-handling.js',
                '/widget/js/chatbot-quick-actions.js',
                '/widget/js/chatbot-fallback-manager.js',
                '/widget/js/chatbot-form-renderer.js',
                '/widget/js/chatbot-widget.js',
                '/widget/js/chatbot-theming.js',
                '/widget/js/chatbot-theme-toggle.js'
            ]
        },
    
    // Loading options
    loadTimeout: 10000,
    retryAttempts: 3,
    retryDelay: 1000,
    
    // Widget container ID
    containerId: 'chatbot-widget-container',
    
    // Events
    events: {
      EMBED_LOADED: 'chatbot:embed:loaded',
      EMBED_ERROR: 'chatbot:embed:error',
      WIDGET_READY: 'chatbot:widget:ready'
    }
  };

  // =================================================================
  // 📦 EMBEDDING ENGINE
  // =================================================================

  class ChatbotEmbedder {
    constructor() {
      this.isLoaded = false;
      this.isLoading = false;
      this.loadPromise = null;
      this.config = this.getConfiguration();
      this.resources = {
        css: [],
        js: []
      };
      
      this.init();
    }

    init() {
      // Don't load if already loaded or loading
      if (this.isLoaded || this.isLoading) {
        return;
      }
      
      // Check browser compatibility
      if (!this.checkCompatibility()) {
        this.showFallback();
        return;
      }
      
      // Start loading process
      this.loadWidget();
    }

    // =================================================================
    // ⚙️ CONFIGURATION
    // =================================================================

    getConfiguration() {
      // Start with defaults
      const config = {
        apiKey: null,
        tenantId: null,
        baseURL: EMBED_CONFIG.baseURL,
        theme: 'default',
        autoOpen: false,
        position: 'bottom-right',
        enableConversationContext: true,
        enableAnalytics: true,
        customCSS: null,
        debug: false
      };
      
      // Merge with global config
      if (window.chatbotConfig) {
        Object.assign(config, window.chatbotConfig);
      }
      
      // Merge with script data attributes
      const script = this.getEmbedScript();
      if (script) {
        const dataConfig = this.extractDataAttributes(script);
        Object.assign(config, dataConfig);
      }
      
      return config;
    }

    getEmbedScript() {
      // Find the embed script
      const scripts = document.querySelectorAll('script');
      for (const script of scripts) {
        if (script.src && script.src.includes('chatbot-embed.js')) {
          return script;
        }
      }
      return document.currentScript;
    }

    extractDataAttributes(script) {
      const config = {};
      const dataset = script.dataset;
      
      // Map data attributes to config
      const mapping = {
        'apiKey': 'apiKey',
        'tenantId': 'tenantId',
        'theme': 'theme',
        'autoOpen': 'autoOpen',
        'position': 'position',
        'baseUrl': 'baseURL',
        'debug': 'debug'
      };
      
      for (const [dataAttr, configKey] of Object.entries(mapping)) {
        if (dataset[dataAttr]) {
          let value = dataset[dataAttr];
          
          // Convert boolean strings
          if (value === 'true') value = true;
          if (value === 'false') value = false;
          
          config[configKey] = value;
        }
      }
      
      return config;
    }

    // =================================================================
    // 🔄 LOADING SYSTEM
    // =================================================================

    async loadWidget() {
      if (this.loadPromise) {
        return this.loadPromise;
      }
      
      this.isLoading = true;
      
      this.loadPromise = this.performLoad();
      
      try {
        await this.loadPromise;
        this.onLoadSuccess();
      } catch (error) {
        this.onLoadError(error);
      }
      
      return this.loadPromise;
    }

    async performLoad() {
      this.log('Starting widget load...');
      
      // Load CSS first (non-blocking)
      const cssPromises = EMBED_CONFIG.files.css.map(file => 
        this.loadCSS(EMBED_CONFIG.baseURL + file)
      );
      
      // Load JavaScript (blocking)
      for (const file of EMBED_CONFIG.files.js) {
        await this.loadJS(EMBED_CONFIG.baseURL + file);
      }
      
      // Wait for CSS to complete
      await Promise.all(cssPromises);
      
      // Create widget container
      this.createWidgetContainer();
      // Notifica che il widget DOM è pronto (container e #chatbot-widget esistono)
      this.dispatchEvent(EMBED_CONFIG.events.WIDGET_READY, {
        containerId: EMBED_CONFIG.containerId,
        tenantId: this.config.tenantId,
        theme: this.config.theme
      });
       
      // Initialize widget
      await this.initializeWidget();
      
      this.log('Widget loaded successfully');
    }

    loadCSS(url) {
      return new Promise((resolve, reject) => {
        // Check if already loaded
        if (this.resources.css.includes(url)) {
          resolve();
          return;
        }
        
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.type = 'text/css';
        link.href = url;
        
        link.onload = () => {
          this.resources.css.push(url);
          this.log(`CSS loaded: ${url}`);
          resolve();
        };
        
        link.onerror = () => {
          const error = new Error(`Failed to load CSS: ${url}`);
          this.log(`CSS error: ${url}`, error);
          reject(error);
        };
        
        // Add timeout
        setTimeout(() => {
          if (!this.resources.css.includes(url)) {
            reject(new Error(`CSS load timeout: ${url}`));
          }
        }, EMBED_CONFIG.loadTimeout);
        
        document.head.appendChild(link);
      });
    }

    loadJS(url) {
      return new Promise((resolve, reject) => {
        // Check if already loaded
        if (this.resources.js.includes(url)) {
          resolve();
          return;
        }
        
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.async = false; // Maintain order
        
        script.onload = () => {
          this.resources.js.push(url);
          this.log(`JS loaded: ${url}`);
          resolve();
        };
        
        script.onerror = () => {
          const error = new Error(`Failed to load JS: ${url}`);
          this.log(`JS error: ${url}`, error);
          reject(error);
        };
        
        // Add timeout
        setTimeout(() => {
          if (!this.resources.js.includes(url)) {
            reject(new Error(`JS load timeout: ${url}`));
          }
        }, EMBED_CONFIG.loadTimeout);
        
        document.head.appendChild(script);
      });
    }

    // =================================================================
    // 📱 WIDGET INITIALIZATION
    // =================================================================

    createWidgetContainer() {
      // Check if container already exists
      let container = document.getElementById(EMBED_CONFIG.containerId);
      if (container) {
        return container;
      }
      
      // Create container
      container = document.createElement('div');
      container.id = EMBED_CONFIG.containerId;
      container.innerHTML = this.getWidgetHTML();
      
      // Apply position styles
      this.applyPositionStyles(container);
      
      // Add to document
      document.body.appendChild(container);
      
      return container;
    }

    getWidgetHTML() {
      // Return the complete widget HTML structure
      return `
        <!-- Chatbot Widget -->
        <button 
          id="chatbot-toggle-btn"
          class="chatbot-toggle-button"
          type="button"
          aria-label="Apri chat assistente"
          aria-haspopup="dialog"
          aria-expanded="false"
        >
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
          </svg>
        </button>
        
        <div 
          id="chatbot-widget"
          class="chatbot-widget"
          role="dialog"
          aria-modal="true"
          aria-labelledby="chatbot-title"
          data-tenant="${this.config.tenantId || 'default'}"
          data-theme="light"
        >
          <header class="chatbot-header">
            <div class="chatbot-avatar">🤖</div>
            <div class="chatbot-header-content">
              <h1 id="chatbot-title" class="chatbot-title">Assistente Virtuale</h1>
              <p class="chatbot-subtitle">Online</p>
            </div>
            
            <!-- Theme Toggle Button -->
            <button 
              id="chatbot-theme-toggle" 
              class="chatbot-theme-toggle" 
              type="button" 
              aria-label="Cambia tema (attuale: chiaro)"
              title="Cambia tra tema chiaro e scuro"
            >
              <svg class="chatbot-theme-icon chatbot-theme-light" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/>
                <line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/>
                <line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
              </svg>
              <svg class="chatbot-theme-icon chatbot-theme-dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
              </svg>
            </button>
            
            <button id="chatbot-close-btn" class="chatbot-close-button" type="button" aria-label="Chiudi chat">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </header>
          
          <main id="chatbot-messages" class="chatbot-messages" role="log" aria-live="polite">
            <div class="chatbot-message bot">
              <div class="chatbot-message-avatar">🤖</div>
              <div class="chatbot-message-content">
                <div class="chatbot-message-bubble">
                  Ciao! 👋 Sono il tuo assistente virtuale. Come posso aiutarti oggi?
                </div>
                <div class="chatbot-message-time">
                  <time datetime="${new Date().toISOString()}">${new Date().toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'})}</time>
                </div>
              </div>
            </div>
          </main>
          
          <footer class="chatbot-input-container">
            <form id="chatbot-form" class="chatbot-input-wrapper">
              <textarea
                id="chatbot-input"
                class="chatbot-input"
                placeholder="Scrivi un messaggio..."
                rows="1"
                maxlength="2000"
              ></textarea>
              <button id="chatbot-send-btn" class="chatbot-send-button" type="submit" disabled>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="22" y1="2" x2="11" y2="13"></line>
                  <polygon points="22,2 15,22 11,13 2,9"></polygon>
                </svg>
              </button>
            </form>
          </footer>
        </div>
        
        <!-- Templates -->
        <template id="chatbot-user-message-template">
          <div class="chatbot-message user">
            <div class="chatbot-message-avatar">👤</div>
            <div class="chatbot-message-content">
              <div class="chatbot-message-bubble"></div>
              <div class="chatbot-message-time"><time datetime=""></time></div>
            </div>
          </div>
        </template>
        
        <template id="chatbot-bot-message-template">
          <div class="chatbot-message bot">
            <div class="chatbot-message-avatar">🤖</div>
            <div class="chatbot-message-content">
              <div class="chatbot-message-bubble"></div>
              <div class="chatbot-message-time"><time datetime=""></time></div>
              <div class="chatbot-citations" style="display: none;">
                <div class="chatbot-citations-title">🔗 Fonti consultate:</div>
                <div class="chatbot-citations-list"></div>
              </div>
            </div>
          </div>
        </template>
        
        <template id="chatbot-typing-template">
          <div class="chatbot-message bot">
            <div class="chatbot-message-avatar">🤖</div>
            <div class="chatbot-message-content">
              <div class="chatbot-typing">
                <div class="chatbot-typing-indicator">
                  <div class="chatbot-typing-dot"></div>
                  <div class="chatbot-typing-dot"></div>
                  <div class="chatbot-typing-dot"></div>
                </div>
              </div>
            </div>
          </div>
        </template>
        
        <template id="chatbot-error-template">
          <div class="chatbot-error">
            <strong>Ops! Si è verificato un errore.</strong>
            <p class="chatbot-error-message"></p>
            <button class="chatbot-retry-button" type="button">🔄 Riprova</button>
          </div>
        </template>
      `;
    }

    applyPositionStyles(container) {
      const styles = {
        position: 'fixed',
        zIndex: '2147483647',
        pointerEvents: 'none'
      };
      
      // Position-specific styles
      switch (this.config.position) {
        case 'bottom-left':
          styles.bottom = '0';
          styles.left = '0';
          break;
        case 'bottom-right':
        default:
          styles.bottom = '0';
          styles.right = '0';
          break;
        case 'top-left':
          styles.top = '0';
          styles.left = '0';
          break;
        case 'top-right':
          styles.top = '0';
          styles.right = '0';
          break;
        case 'center':
          styles.top = '50%';
          styles.left = '50%';
          styles.transform = 'translate(-50%, -50%)';
          break;
      }
      
      // Apply styles
      Object.assign(container.style, styles);
      
      // Make widget elements interactive
      container.querySelectorAll('.chatbot-toggle-button, .chatbot-widget').forEach(el => {
        el.style.pointerEvents = 'auto';
      });
    }

    async initializeWidget() {
      // Wait for widget classes to be available
      await this.waitForWidget();
      
      // Initialize widget with configuration
      if (window.ChatbotWidget) {
        this.widget = new window.ChatbotWidget({
          apiKey: this.config.apiKey,
          tenantId: this.config.tenantId,
          baseURL: this.config.baseURL,
          theme: this.config.theme,
          autoOpen: this.config.autoOpen,
          enableConversationContext: this.config.enableConversationContext
        });
        
        this.setupWidgetEvents();
      } else {
        throw new Error('ChatbotWidget class not available');
      }
    }

    waitForWidget(maxAttempts = 50) {
      return new Promise((resolve, reject) => {
        let attempts = 0;
        
        const check = () => {
          attempts++;
          
          if (window.ChatbotWidget) {
            resolve();
          } else if (attempts >= maxAttempts) {
            reject(new Error('Widget class not loaded after maximum attempts'));
          } else {
            setTimeout(check, 100);
          }
        };
        
        check();
      });
    }

    setupWidgetEvents() {
      if (!this.widget) return;
      
      // Forward widget events
      this.widget.on('chatbot:widget:opened', () => {
        this.trackEvent('widget_opened');
      });
      
      this.widget.on('chatbot:widget:closed', () => {
        this.trackEvent('widget_closed');
      });
      
      this.widget.on('chatbot:message:sent', (data) => {
        this.trackEvent('message_sent', { length: data.content.length });
      });
      
      this.widget.on('chatbot:message:received', (data) => {
        this.trackEvent('message_received', { 
          length: data.content.length,
          citations: data.citations ? data.citations.length : 0
        });
      });
    }

    // =================================================================
    // 🔍 COMPATIBILITY & FALLBACK
    // =================================================================

    checkCompatibility() {
      // Check required browser features
      const required = [
        'Promise',
        'fetch',
        'CustomEvent',
        'MutationObserver',
        'localStorage'
      ];
      
      for (const feature of required) {
        if (!(feature in window)) {
          this.log(`Missing required feature: ${feature}`);
          return false;
        }
      }
      
      // Check CSS support
      if (!CSS.supports || !CSS.supports('display', 'flex')) {
        this.log('Missing required CSS support');
        return false;
      }
      
      return true;
    }

    showFallback() {
      const fallback = document.createElement('div');
      fallback.id = 'chatbot-fallback';
      fallback.innerHTML = `
        <div style="
          position: fixed;
          bottom: 20px;
          right: 20px;
          max-width: 300px;
          padding: 15px;
          background: #fee;
          border: 1px solid #fcc;
          border-radius: 8px;
          font-family: system-ui, sans-serif;
          font-size: 14px;
          color: #c53030;
          z-index: 999999;
        ">
          <strong>Chat non disponibile</strong><br>
          Il tuo browser non supporta tutte le funzionalità richieste.
          <br><br>
          <strong>Contatti alternativi:</strong><br>
          Email: <a href="mailto:support@example.com" style="color: #c53030;">support@example.com</a>
        </div>
      `;
      
      document.body.appendChild(fallback);
      
      // Auto-hide after 10 seconds
      setTimeout(() => {
        fallback.remove();
      }, 10000);
    }

    // =================================================================
    // 📋 EVENT HANDLING
    // =================================================================

    onLoadSuccess() {
      this.isLoaded = true;
      this.isLoading = false;
      
      this.log('Widget embedded successfully');
      
      this.dispatchEvent(EMBED_CONFIG.events.EMBED_LOADED, {
        config: this.config,
        resources: this.resources
      });
      
      this.trackEvent('widget_embedded');
    }

    onLoadError(error) {
      this.isLoading = false;
      
      this.log('Widget embedding failed', error);
      
      this.dispatchEvent(EMBED_CONFIG.events.EMBED_ERROR, {
        error: error.message,
        config: this.config
      });
      
      this.trackEvent('embed_error', { error: error.message });
      
      // Show fallback on critical errors
      if (!this.config.debug) {
        this.showFallback();
      }
    }

    dispatchEvent(eventName, detail) {
      const event = new CustomEvent(eventName, { detail });
      window.dispatchEvent(event);
    }

    // =================================================================
    // 📊 ANALYTICS
    // =================================================================

    trackEvent(eventName, properties = {}) {
      if (!this.config.enableAnalytics) return;
      
      const eventData = {
        event: eventName,
        properties: {
          ...properties,
          tenant_id: this.config.tenantId ? parseInt(this.config.tenantId) : null,
          theme: this.config.theme,
          timestamp: Math.floor(Date.now() / 1000), // Unix timestamp
          user_agent: navigator.userAgent,
          page_url: window.location.href,
          referrer: document.referrer
        }
      };
      
      // Send to analytics endpoint
      if (navigator.sendBeacon) {
        const blob = new Blob([JSON.stringify(eventData)], {
          type: 'application/json'
        });
        navigator.sendBeacon('/api/v1/widget/events/public', blob);
      }
      
      this.log('Event tracked:', eventData);
    }

    // =================================================================
    // 🛠️ UTILITIES
    // =================================================================

    log(message, ...args) {
      if (this.config.debug) {
        console.log(`[ChatbotEmbed] ${message}`, ...args);
      }
    }

    // =================================================================
    // 💻 PUBLIC API
    // =================================================================

    getWidget() {
      return this.widget;
    }

    getConfig() {
      return { ...this.config };
    }

    updateConfig(newConfig) {
      Object.assign(this.config, newConfig);
      
      if (this.widget) {
        this.widget.updateConfig(newConfig);
      }
    }

    reload() {
      // Remove existing widget
      const container = document.getElementById(EMBED_CONFIG.containerId);
      if (container) {
        container.remove();
      }
      
      // Reset state
      this.isLoaded = false;
      this.isLoading = false;
      this.loadPromise = null;
      this.widget = null;
      
      // Reload
      this.init();
    }

    destroy() {
      // Remove widget
      const container = document.getElementById(EMBED_CONFIG.containerId);
      if (container) {
        container.remove();
      }
      
      // Remove resources (optional)
      this.resources.css.forEach(url => {
        const link = document.querySelector(`link[href="${url}"]`);
        if (link) link.remove();
      });
      
      // Clean up
      this.widget = null;
      this.isLoaded = false;
      this.isLoading = false;
    }
  }

  // =================================================================
  // 🚀 AUTO-INITIALIZATION
  // =================================================================

  // Prevent multiple initializations
  if (window.chatbotEmbedder) {
    console.warn('[ChatbotEmbed] Already initialized');
    return;
  }

  // Create embedder instance
  const embedder = new ChatbotEmbedder();
  
  // Expose global API
  window.chatbotEmbedder = embedder;
  window.ChatbotEmbedder = ChatbotEmbedder;

  // Auto-embed when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      embedder.init();
    });
  } else {
    // DOM already ready
    embedder.init();
  }

})();
