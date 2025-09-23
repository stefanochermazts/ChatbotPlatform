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
    
        // Version per forzare cache refresh dopo aggiornamenti (UPDATED)
        version: '1.3.4.MARKDOWN_MASKING.' + Date.now() + Math.random().toString(36).substr(2, 9), // 🔧 FIXED: Markdown-aware URL masking
        
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
                '/widget/css/chatbot-forms.css',
                '/widget/css/chatbot-conversation-persistence.css'
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
                '/widget/js/chatbot-theme-toggle.js',
                '/widget/js/chatbot-widget.js?cb=' + Date.now() + Math.random().toString(36).substr(2, 9),
                '/widget/js/chatbot-theming.js'
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
      
      // Don't auto-init in constructor, let the caller decide when to init
    }

    init() {
      console.log('[ChatbotEmbed] init() called');
      
      // Don't load if already loaded or loading
      if (this.isLoaded || this.isLoading) {
        console.log('[ChatbotEmbed] Already loaded or loading, skipping');
        return;
      }
      
      console.log('[ChatbotEmbed] Starting initialization...');
      
      // Check browser compatibility
      if (!this.checkCompatibility()) {
        console.warn('[ChatbotEmbed] Browser compatibility check failed');
        this.showFallback();
        return;
      }
      
      console.log('[ChatbotEmbed] Browser compatible, loading widget...');
      
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
        apiUrl: null, // Sarà impostato come baseURL + '/api/v1' se non specificato
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

      // Initialize theme toggle (inline fallback)
      this.initThemeToggle();
      
      // 🔧 Esponi configurazione globale per il widget principale
      window.CHATBOT_CONFIG = {
        apiKey: this.config.apiKey,
        tenantId: this.config.tenantId,
        apiUrl: this.config.apiUrl || (EMBED_CONFIG.baseURL + '/api/v1'),
        theme: this.config.theme,
        primaryColor: this.config.primaryColor,
        debug: this.config.debug,
        version: EMBED_CONFIG.version,
        sessionId: this.config.sessionId || 'session_' + Date.now(),
        conversationId: this.config.conversationId || 'conv_' + Date.now()
      };
      
      // Log configurazione per debug
      if (this.config.debug) {
        console.log('🔧 CHATBOT_CONFIG esposto:', window.CHATBOT_CONFIG);
      }
       
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
        // 🔄 Aggiungi version parameter per forzare cache refresh
        link.href = url + (url.includes('?') ? '&' : '?') + 'v=' + EMBED_CONFIG.version;
        
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
          data-theme="${this.config.theme || 'default'}"
        >
          <!-- Skip Link per accessibilità WCAG 2.1 AA -->
          <a href="#chatbot-messages" class="chatbot-skip-link" onclick="document.getElementById('chatbot-messages').focus(); return false;">
            Salta alla conversazione
          </a>
          
          <header class="chatbot-header">
            <div class="chatbot-avatar">🤖</div>
            <div class="chatbot-header-content">
              <h1 id="chatbot-title" class="chatbot-title">Assistente Virtuale</h1>
              <p class="chatbot-subtitle">Online</p>
            </div>
            <div class="chatbot-header-controls">
              <button id="chatbot-theme-toggle-btn" class="chatbot-theme-toggle" type="button" aria-label="Cambia tema" title="Passa al tema scuro/chiaro">
                <svg class="chatbot-theme-icon chatbot-theme-light" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <circle cx="12" cy="12" r="5"></circle>
                  <line x1="12" y1="1" x2="12" y2="3"></line>
                  <line x1="12" y1="21" x2="12" y2="23"></line>
                  <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                  <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                  <line x1="1" y1="12" x2="3" y2="12"></line>
                  <line x1="21" y1="12" x2="23" y2="12"></line>
                  <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                  <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                <svg class="chatbot-theme-icon chatbot-theme-dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
              </button>
              <button id="chatbot-close-btn" class="chatbot-close-button" type="button" aria-label="Chiudi chat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            </div>
          </header>
          
          <main id="chatbot-messages" class="chatbot-messages" role="log" aria-live="polite">
            <!-- Messages will be added dynamically by JavaScript -->
          </main>
          
          <footer class="chatbot-input-container">
            <!-- 🎯 Handoff Status Bar -->
            <div id="chatbot-handoff-status" class="chatbot-handoff-status" style="display: none;">
              <div class="chatbot-handoff-content">
                <span id="chatbot-handoff-indicator" class="chatbot-handoff-indicator">🤝</span>
                <span id="chatbot-handoff-text" class="chatbot-handoff-text">Richiesta di assistenza in corso...</span>
              </div>
            </div>
            
            <form id="chatbot-form" class="chatbot-input-wrapper">
              <textarea
                id="chatbot-input"
                class="chatbot-input"
                placeholder="Scrivi un messaggio..."
                rows="1"
                maxlength="2000"
              ></textarea>
              
              <!-- 🎯 Agent Console Action Buttons -->
              <div class="chatbot-action-buttons">
                <button id="chatbot-handoff-btn" class="chatbot-action-button" type="button" title="Parla con un operatore">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                  </svg>
                </button>
                
                <button id="chatbot-send-btn" class="chatbot-send-button" type="submit" disabled>
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22,2 15,22 11,13 2,9"></polygon>
                  </svg>
                </button>
              </div>
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
          // Core configuration
          apiKey: this.config.apiKey,
          tenantId: this.config.tenantId,
          baseURL: this.config.baseURL,
          theme: this.config.theme,
          autoOpen: this.config.autoOpen,
          enableConversationContext: this.config.enableConversationContext,
          // 🔄 CONVERSATION PERSISTENCE: Pass all new config options
          enableConversationPersistence: this.config.enableConversationPersistence,
          enableQuickActions: this.config.enableQuickActions,
          enableThemeAPI: this.config.enableThemeAPI,
          // Widget branding and behavior
          welcomeMessage: this.config.welcomeMessage,
          widgetName: this.config.widgetName,
          // API configuration
          model: this.config.model,
          temperature: this.config.temperature,
          maxTokens: this.config.maxTokens,
          // Analytics and features
          enableAnalytics: this.config.enableAnalytics,
          // Pass through any other config options
          ...this.config
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

    // =================================================================
    // 🌙 THEME TOGGLE
    // =================================================================
    
    initThemeToggle() {
      console.log('🌙 Initializing theme toggle (inline)');
      
      let currentTheme = 'light';
      const STORAGE_KEY = 'chatbot_user_theme_mode';
      
      // Cleanup old conflicting keys
      try {
        // Remove old key that conflicts with theming system
        const oldKey = 'chatbot_theme_preference';
        if (localStorage.getItem(oldKey)) {
          const oldValue = localStorage.getItem(oldKey);
          localStorage.removeItem(oldKey);
          // Migrate to new key if valid
          if (oldValue === 'light' || oldValue === 'dark') {
            localStorage.setItem(STORAGE_KEY, oldValue);
            currentTheme = oldValue;
          }
        }
      } catch (e) {
        console.warn('Could not cleanup old theme keys:', e);
      }

      // Load saved theme
      try {
        const savedTheme = localStorage.getItem(STORAGE_KEY);
        if (savedTheme && (savedTheme === 'light' || savedTheme === 'dark')) {
          currentTheme = savedTheme;
        }
      } catch (e) {
        console.warn('Could not load saved theme:', e);
      }
      
      // Apply theme function
      const applyTheme = (theme) => {
        console.log('🌙 Applying theme:', theme);
        
        const containers = [
          document.documentElement,
          document.body,
          document.querySelector('#chatbot-container'),
          document.querySelector('.chatbot-widget')
        ].filter(el => el !== null);

        containers.forEach(container => {
          container.setAttribute('data-chatbot-theme', theme);
        });

        currentTheme = theme;
        
        try {
          localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {
          console.warn('Could not save theme:', e);
        }

        console.log('🌙 Theme applied to', containers.length, 'containers');
      };
      
      // Toggle function
      const toggleTheme = () => {
        console.log('🌙 Toggle clicked, current theme:', currentTheme);
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        applyTheme(newTheme);
        updateButton();
      };
      
      // Update button function
      const updateButton = () => {
        const button = document.querySelector('#chatbot-theme-toggle-btn');
        if (button) {
          const label = currentTheme === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro';
          button.setAttribute('aria-label', label);
          button.setAttribute('title', label);
          console.log('🌙 Button updated with label:', label);
        }
      };
      
      // Find and setup button
      const setupButton = () => {
        const button = document.querySelector('#chatbot-theme-toggle-btn');
        if (button) {
          console.log('🌙 Found toggle button, attaching listener');
          button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleTheme();
          });
          updateButton();
          
          // Apply initial theme
          applyTheme(currentTheme);
          
          console.log('🌙 Theme toggle initialized successfully');
          
          // Expose debug functions
          window.chatbotThemeDebug = {
            toggleTheme: toggleTheme,
            applyTheme: applyTheme,
            getCurrentTheme: () => currentTheme
          };
          
        } else {
          console.warn('🌙 Toggle button not found');
        }
      };
      
      // Setup immediately or retry
      setupButton();
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
