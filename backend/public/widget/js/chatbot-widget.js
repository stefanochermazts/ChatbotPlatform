/**
 * 🤖 Chatbot Widget - Core JavaScript
 * 
 * Vanilla JavaScript implementation
 * No external dependencies
 * WCAG 2.1 AA accessible
 * Conversation-aware RAG integration
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 🔌 CONFIGURATION & CONSTANTS
  // =================================================================

  const CONFIG = {
    // API Configuration
    apiEndpoint: '/api/v1/chat/completions',
    analyticsEndpoint: '/api/v1/widget/events',
    maxRetries: 3,
    retryDelay: 1000,
    requestTimeout: 30000,
    
    // Widget behavior
    maxMessageLength: 2000,
    typingIndicatorDelay: 500,
    autoScrollDelay: 100,
    
    // Analytics
    enableAnalytics: true,
    sessionDuration: 30 * 60 * 1000, // 30 minutes
    batchAnalytics: false, // Send events immediately for now
    
    // Accessibility
    focusTrapElements: ['input', 'button', 'textarea', 'a[href]', '[tabindex]:not([tabindex="-1"])'],
    
    // Storage keys
    storagePrefix: 'chatbot_widget_',
    conversationKey: 'conversation_history',
    preferencesKey: 'user_preferences',
    sessionKey: 'session_id',
    
    // Events
    events: {
      WIDGET_OPENED: 'chatbot:widget:opened',
      WIDGET_CLOSED: 'chatbot:widget:closed',
      MESSAGE_SENT: 'chatbot:message:sent',
      MESSAGE_RECEIVED: 'chatbot:message:received',
      ERROR_OCCURRED: 'chatbot:error',
      TYPING_START: 'chatbot:typing:start',
      TYPING_END: 'chatbot:typing:end'
    },
    
    // Analytics event types
    analyticsEvents: {
      WIDGET_LOADED: 'widget_loaded',
      CHATBOT_OPENED: 'chatbot_opened',
      CHATBOT_CLOSED: 'chatbot_closed',
      MESSAGE_SENT: 'message_sent',
      MESSAGE_RECEIVED: 'message_received',
      MESSAGE_ERROR: 'message_error',
      WIDGET_ERROR: 'widget_error'
    }
  };

  // =================================================================
  // 📋 STATE MANAGEMENT
  // =================================================================

  class ChatbotState {
    constructor() {
      this.isOpen = false;
      this.isLoading = false;
      this.isTyping = false;
      this.conversation = [];
      this.apiKey = null;
      this.tenantId = null;
      this.config = {};
      this.retryCount = 0;
      this.lastError = null;
      
      // Load persisted state
      this.loadFromStorage();
    }

    loadFromStorage() {
      try {
        const stored = localStorage.getItem(CONFIG.storagePrefix + CONFIG.conversationKey);
        if (stored) {
          this.conversation = JSON.parse(stored);
        }
      } catch (error) {
        console.warn('Could not load conversation from storage:', error);
        this.conversation = [];
      }
    }

    saveToStorage() {
      try {
        localStorage.setItem(
          CONFIG.storagePrefix + CONFIG.conversationKey,
          JSON.stringify(this.conversation.slice(-20)) // Keep last 20 messages
        );
      } catch (error) {
        console.warn('Could not save conversation to storage:', error);
      }
    }

    addMessage(message) {
      this.conversation.push({
        ...message,
        id: this.generateId(),
        timestamp: new Date().toISOString()
      });
      this.saveToStorage();
    }

    generateId() {
      return Date.now().toString(36) + Math.random().toString(36).substr(2);
    }

    reset() {
      this.conversation = [];
      this.isLoading = false;
      this.isTyping = false;
      this.retryCount = 0;
      this.lastError = null;
      this.saveToStorage();
    }
  }

  // =================================================================
  // 🚀 API CLIENT
  // =================================================================

  class ChatbotAPI {
    constructor(apiKey, baseURL = '') {
      this.apiKey = apiKey;
      this.baseURL = baseURL;
    }

    async sendMessage(messages, options = {}) {
      const url = this.baseURL + CONFIG.apiEndpoint;
      
      const payload = {
        model: options.model || 'gpt-4o-mini',
        messages: messages,
        temperature: options.temperature || 0.7,
        max_tokens: options.maxTokens || 1000,
        stream: false,
        ...options.additionalParams
      };

      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), CONFIG.requestTimeout);

      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.apiKey}`,
            'Accept': 'application/json', // 🔧 FIX: Header necessario per evitare redirect 302
            'X-Requested-With': 'ChatbotWidget'
          },
          body: JSON.stringify(payload),
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          throw new APIError(
            errorData.error?.message || `HTTP ${response.status}`,
            response.status,
            errorData
          );
        }

        const data = await response.json();
        return this.processResponse(data);

      } catch (error) {
        clearTimeout(timeoutId);
        
        if (error.name === 'AbortError') {
          throw new APIError('Request timeout', 408);
        }
        
        if (error instanceof APIError) {
          throw error;
        }
        
        throw new APIError('Network error', 0, { originalError: error });
      }
    }

    processResponse(data) {
      const message = data.choices?.[0]?.message;
      if (!message) {
        throw new APIError('Invalid response format');
      }



      return {
        content: message.content,
        role: message.role,
        citations: data.citations || [],
        usage: data.usage || {},
        conversationDebug: data.conversation_debug || null
      };
    }
  }

  // Custom Error class
  class APIError extends Error {
    constructor(message, statusCode = 0, details = {}) {
      super(message);
      this.name = 'APIError';
      this.statusCode = statusCode;
      this.details = details;
    }
  }

  // =================================================================
  // 📝 MARKDOWN PARSER
  // =================================================================

  class MarkdownParser {
    static parse(text) {
      if (!text || typeof text !== 'string') {
        return text;
      }

      let html = text;

      // Escape HTML di base per sicurezza
      html = html.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

      // 1. Code blocks (``` ... ```)
      html = html.replace(/```(\w+)?\n([\s\S]*?)\n?```/g, (match, lang, code) => {
        const language = lang ? ` class="language-${lang}"` : '';
        return `<pre class="chatbot-code-block"><code${language}>${code.trim()}</code></pre>`;
      });

      // 2. Inline code (`...`)
      html = html.replace(/`([^`\n]+)`/g, '<code class="chatbot-inline-code">$1</code>');

      // 3. MASK URLs temporaneamente per proteggerli dal processamento markdown
      const urlPlaceholders = [];
      let urlCounter = 0;
      
      // Maschera https:// URLs - uso formato immune alle regex bold/italic
      html = html.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]}])/g, (match) => {
        const placeholder = `###URLMASK${urlCounter++}###`;
        urlPlaceholders.push({ placeholder, url: match });
        return placeholder;
      });
      
      // Maschera www. URLs - uso formato immune alle regex bold/italic
      html = html.replace(/(?<!["\[>])(www\.[^\s<]+[^\s<.,;:!?)\]}])/g, (match) => {
        const placeholder = `###URLMASK${urlCounter++}###`;
        urlPlaceholders.push({ placeholder, url: match });
        return placeholder;
      });

      // 4. Bold (**text** o __text__) - ora sicuro dagli URL con nuovo formato placeholder
      html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong class="chatbot-bold">$1</strong>')
                .replace(/__([^_\n]+)__/g, '<strong class="chatbot-bold">$1</strong>');

      // 5. Italic (*text* o _text_) - ora sicuro dagli URL con nuovo formato placeholder  
      html = html.replace(/\*([^*\n]+)\*/g, '<em class="chatbot-italic">$1</em>')
                .replace(/_([^_\n]+)_/g, '<em class="chatbot-italic">$1</em>');

      // 6. Links markdown [text](url)
      html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="chatbot-link">$1</a>');

      // 7. RESTORE URLs e linkificali
      urlPlaceholders.forEach(({ placeholder, url }) => {
        const linkedUrl = url.startsWith('www.') 
          ? `<a href="http://${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${url}</a>`
          : `<a href="${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${url}</a>`;
        
        html = html.replace(placeholder, linkedUrl);
      });
      
      // 8. Auto-link Email  
      html = html.replace(/(?<!["\[>]|href="|>)(?![^<]*<\/a>)([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g, '<a href="mailto:$1" class="chatbot-link">$1</a>');
      
      // 9. Auto-link Telefoni italiani
      html = html.replace(/(?<!["\[>]|href="|>)(?![^<]*<\/a>)(\+39\s?\d{3}\s?\d{3}\s?\d{3,4})/g, (match, phone) => {
        const cleanPhone = phone.replace(/\s/g, ''); // Rimuovi spazi da href
        return `<a href="tel:${cleanPhone}" class="chatbot-link">${phone}</a>`;
      });
      html = html.replace(/(?<!["\[>]|href="|>)(?![^<]*<\/a>)(0\d{1,3}[\s\-]?\d{6,8})/g, (match, phone) => {
        const cleanPhone = phone.replace(/[\s\-]/g, ''); // Rimuovi spazi e trattini da href
        return `<a href="tel:${cleanPhone}" class="chatbot-link">${phone}</a>`;
      });

      // 10. Headings (# ## ###)
      html = html.replace(/^### (.*$)/gm, '<h3 class="chatbot-heading-3">$1</h3>')
                .replace(/^## (.*$)/gm, '<h2 class="chatbot-heading-2">$1</h2>')
                .replace(/^# (.*$)/gm, '<h1 class="chatbot-heading-1">$1</h1>');

      // 11. Lists (* item o - item)
      html = html.replace(/^\s*[\*\-]\s+(.+)/gm, '<li class="chatbot-list-item">$1</li>');
      
      // Wrap consecutive list items in <ul>
      html = html.replace(/(<li class="chatbot-list-item">.*?<\/li>)(\s*<li class="chatbot-list-item">.*?<\/li>)*/g, (match) => {
        return '<ul class="chatbot-list">' + match + '</ul>';
      });

      // 12. Line breaks (doppio spazio + newline o doppio newline)
      html = html.replace(/  \n/g, '<br>')
                .replace(/\n\n/g, '<br><br>')
                .replace(/\n/g, '<br>');

      // 13. Strikethrough (~~text~~)
      html = html.replace(/~~([^~\n]+)~~/g, '<del class="chatbot-strikethrough">$1</del>');

      return this.sanitize(html);
    }

    static sanitize(html) {
      // Rimuove script tags e attributi pericolosi per sicurezza
      return html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                .replace(/on\w+="[^"]*"/g, '')
                .replace(/javascript:/gi, 'blocked:');
    }
  }

  // =================================================================
  // 🎨 UI MANAGER
  // =================================================================

  class ChatbotUI {
    constructor(state, eventEmitter) {
      this.state = state;
      this.events = eventEmitter;
      this.elements = {};
      this.templates = {};
      this.isInitialized = false;
      
      this.init();
    }

    init() {
      this.cacheElements();
      this.cacheTemplates();
      this.setupEventListeners();
      this.setupAccessibility();
      this.markAsLoaded();
      this.isInitialized = true;
    }

    cacheElements() {
      this.elements = {
        widget: document.getElementById('chatbot-widget'),
        toggleBtn: document.getElementById('chatbot-toggle-btn'),
        closeBtn: document.getElementById('chatbot-close-btn'),
        messages: document.getElementById('chatbot-messages'),
        form: document.getElementById('chatbot-form'),
        input: document.getElementById('chatbot-input'),
        sendBtn: document.getElementById('chatbot-send-btn'),
        charCounter: document.getElementById('chatbot-char-counter'),
        charCount: document.getElementById('chatbot-char-count'),
        status: document.getElementById('chatbot-status')
      };
    }

    cacheTemplates() {
      this.templates = {
        userMessage: document.getElementById('chatbot-user-message-template') || this.createUserMessageTemplate(),
        botMessage: document.getElementById('chatbot-bot-message-template') || this.createBotMessageTemplate(),
        typing: document.getElementById('chatbot-typing-template') || this.createTypingTemplate(),
        error: document.getElementById('chatbot-error-template') || this.createErrorTemplate()
      };
    }

    createUserMessageTemplate() {
      const template = document.createElement('template');
      template.innerHTML = `
        <div class="chatbot-message user" role="article" aria-label="Tuo messaggio">
          <div class="chatbot-message-avatar" role="img" aria-label="Tu">👤</div>
          <div class="chatbot-message-content">
            <div class="chatbot-message-bubble"></div>
            <time class="chatbot-message-time"></time>
          </div>
        </div>
      `;
      return template;
    }

    createBotMessageTemplate() {
      const template = document.createElement('template');
      template.innerHTML = `
        <div class="chatbot-message bot" role="article" aria-label="Messaggio assistente">
          <div class="chatbot-message-avatar" role="img" aria-label="Assistente">🤖</div>
          <div class="chatbot-message-content">
            <div class="chatbot-message-bubble"></div>
            <div class="chatbot-message-citations"></div>
            <time class="chatbot-message-time"></time>
          </div>
        </div>
      `;
      return template;
    }

    createTypingTemplate() {
      const template = document.createElement('template');
      template.innerHTML = `
        <div class="chatbot-message bot" role="article" aria-label="L'assistente sta scrivendo">
          <div class="chatbot-message-avatar" role="img" aria-label="Assistente">🤖</div>
          <div class="chatbot-message-content">
            <div class="chatbot-message-bubble">
              <div class="chatbot-typing-dots">
                <span></span><span></span><span></span>
              </div>
            </div>
          </div>
        </div>
      `;
      return template;
    }

    createErrorTemplate() {
      const template = document.createElement('template');
      template.innerHTML = `
        <div class="chatbot-error" role="alert" aria-live="assertive">
          <strong>Ops! Si è verificato un errore.</strong>
          <p class="chatbot-error-message"></p>
          <button class="chatbot-retry-button" type="button">🔄 Riprova</button>
        </div>
      `;
      return template;
    }

    setupEventListeners() {
      // Toggle widget
      this.elements.toggleBtn?.addEventListener('click', () => this.openWidget());
      this.elements.closeBtn?.addEventListener('click', () => this.closeWidget());

      // Form submission
      this.elements.form?.addEventListener('submit', (e) => this.handleSubmit(e));

      // Input handling
      this.elements.input?.addEventListener('input', () => this.handleInputChange());
      this.elements.input?.addEventListener('keydown', (e) => this.handleKeyDown(e));

      // Global keyboard shortcuts
      document.addEventListener('keydown', (e) => this.handleGlobalKeyDown(e));

      // Click outside to close (optional)
      document.addEventListener('click', (e) => this.handleOutsideClick(e));
    }

    setupAccessibility() {
      // Focus trap setup
      this.setupFocusTrap();
      
      // ARIA live regions
      this.setupLiveRegions();
      
      // Reduced motion support
      if (this.prefersReducedMotion()) {
        this.elements.widget?.classList.add('reduce-motion');
      }
    }

    setupFocusTrap() {
      this.focusableElements = null;
      this.firstFocusableElement = null;
      this.lastFocusableElement = null;
    }

    updateFocusTrap() {
      if (!this.state.isOpen) return;

      const focusable = this.elements.widget?.querySelectorAll(
        CONFIG.focusTrapElements.join(', ')
      );
      
      this.focusableElements = Array.from(focusable || []);
      this.firstFocusableElement = this.focusableElements[0];
      this.lastFocusableElement = this.focusableElements[this.focusableElements.length - 1];
    }

    setupLiveRegions() {
      // Already set up in HTML with aria-live attributes
    }

    markAsLoaded() {
      this.elements.widget?.classList.add('js-loaded');
      this.elements.toggleBtn?.classList.add('js-loaded');
    }

    // Widget state management
    openWidget() {
      if (this.state.isOpen) return;
      
      this.state.isOpen = true;
      this.elements.widget?.classList.add('is-open');
      this.elements.toggleBtn?.setAttribute('aria-expanded', 'true');
      
      // Focus management
      setTimeout(() => {
        this.updateFocusTrap();
        this.elements.input?.focus();
      }, 100);
      
      // Accessibility announcement
      if (window.chatbotAnnounce) {
        window.chatbotAnnounce(true);
      }
      
      this.events.emit(CONFIG.events.WIDGET_OPENED);
    }

    closeWidget() {
      if (!this.state.isOpen) return;
      
      this.state.isOpen = false;
      this.elements.widget?.classList.remove('is-open');
      this.elements.toggleBtn?.setAttribute('aria-expanded', 'false');
      
      // Return focus to toggle button
      setTimeout(() => {
        this.elements.toggleBtn?.focus();
      }, 100);
      
      // Accessibility announcement
      if (window.chatbotAnnounce) {
        window.chatbotAnnounce(false);
      }
      
      this.events.emit(CONFIG.events.WIDGET_CLOSED);
    }

    // Message rendering
    addUserMessage(content) {
      const messageEl = this.createMessageElement('user', content);
      if (!messageEl) {
        console.error('Failed to create user message element');
        return;
      }
      
      this.appendMessage(messageEl);
      
      // Enhance accessibility
      if (window.chatbotWidget?.accessibility) {
        window.chatbotWidget.accessibility.enhanceMessage(messageEl, true);
      }
      
      this.events.emit(CONFIG.events.MESSAGE_SENT, { content, role: 'user' });
    }

    addBotMessage(content, citations = []) {
      const messageEl = this.createMessageElement('bot', content, citations);
      if (!messageEl) {
        console.error('Failed to create bot message element');
        return;
      }
      
      this.appendMessage(messageEl);
      
      // Enhance accessibility
      if (window.chatbotWidget?.accessibility) {
        window.chatbotWidget.accessibility.enhanceMessage(messageEl, false);
      }
      
      // Setup citation click handlers
      if (window.chatbotWidget?.citations && citations && citations.length > 0) {
        this.setupCitationHandlers(messageEl);
      }
      
      this.events.emit(CONFIG.events.MESSAGE_RECEIVED, { content, role: 'assistant', citations });
    }

    createMessageElement(role, content, citations = []) {
      const template = role === 'user' 
        ? this.templates.userMessage 
        : this.templates.botMessage;
      
      if (!template) {
        console.error(`Template not found for role: ${role}`);
        return null;
      }

      const fragment = template.content.cloneNode(true);
      const messageEl = fragment.firstElementChild;
      const bubble = messageEl?.querySelector('.chatbot-message-bubble');
      const timeEl = messageEl?.querySelector('time');
      
      if (bubble) {
        // Parse markdown e applica al contenuto
        const parsedContent = MarkdownParser.parse(content);
        bubble.innerHTML = parsedContent;
      }
      
      if (timeEl) {
        const now = new Date();
        timeEl.setAttribute('datetime', now.toISOString());
        timeEl.textContent = now.toLocaleTimeString('it-IT', {
          hour: '2-digit',
          minute: '2-digit'
        });
      }

      // Add citations if present (disabilitato per nascondere le fonti)
      // if (citations && citations.length > 0 && role === 'bot') {
      //   this.addCitations(messageEl, citations);
      // }

      return messageEl;
    }

    addCitations(messageEl, citations) {
      if (!citations || citations.length === 0) return;
      
      // Use CitationsManager to render interactive citations if available
      if (this.citations && typeof this.citations.renderCitations === 'function') {
        const citationsHtml = this.citations.renderCitations(citations);
        
        // Find the message bubble and append citations
        const bubble = messageEl.querySelector('.chatbot-message-bubble');
        if (bubble) {
          bubble.insertAdjacentHTML('afterend', citationsHtml);
        }
      } else {
        // Fallback to simple citations if CitationsManager not available
        this.addSimpleCitations(messageEl, citations);
      }
    }

    addSimpleCitations(messageEl, citations) {
      const citationsContainer = messageEl.querySelector('.chatbot-citations');
      const citationsList = messageEl.querySelector('.chatbot-citations-list');
      
      if (!citationsContainer || !citationsList) {
        // Create simple citations container if not exists
        const bubble = messageEl.querySelector('.chatbot-message-bubble');
        if (bubble) {
          const citationsHtml = `
            <div class="chatbot-citations-simple">
              <div class="chatbot-citations-header">
                📎 ${citations.length === 1 ? 'Fonte' : 'Fonti'} (${citations.length})
              </div>
              <div class="chatbot-citations-list">
                ${citations.map((citation, index) => `
                  <a href="${citation.url || '#'}" target="_blank" rel="noopener noreferrer" 
                     class="chatbot-citation-simple" title="${citation.title || 'Documento'}">
                    ${index + 1}. ${citation.title || 'Documento'}
                  </a>
                `).join('')}
              </div>
            </div>
          `;
          bubble.insertAdjacentHTML('afterend', citationsHtml);
        }
        return;
      }

      citationsContainer.style.display = 'block';
      
      citations.forEach((citation, index) => {
        const citationEl = document.createElement('a');
        citationEl.className = 'chatbot-citation';
        citationEl.href = citation.url || '#';
        citationEl.target = '_blank';
        citationEl.rel = 'noopener noreferrer';
        citationEl.role = 'listitem';
        citationEl.textContent = `${index + 1}. ${citation.title || 'Documento'}`;
        citationEl.title = citation.description || citation.title || 'Apri documento';
        
        citationsList.appendChild(citationEl);
      });
    }

    setupCitationHandlers(messageEl) {
      if (!messageEl || !this.citations) return;
      
      // Find all citation title buttons and add click handlers
      const citationButtons = messageEl.querySelectorAll('.chatbot-citation-title');
      
      citationButtons.forEach(button => {
        button.addEventListener('click', (e) => {
          e.preventDefault();
          
          const documentId = button.dataset.documentId;
          const chunkIndex = button.dataset.chunkIndex;
          const chunkText = button.dataset.chunkText;
          
          if (documentId && this.citations.handleCitationClick) {
            this.citations.handleCitationClick({
              dataset: {
                documentId: documentId,
                chunkIndex: chunkIndex || '0',
                chunkText: chunkText || ''
              }
            });
          }
        });
        
        // Add keyboard support
        button.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            button.click();
          }
        });
      });
    }

    appendMessage(messageEl) {
      if (!messageEl) return;
      
      this.elements.messages?.appendChild(messageEl);
      this.scrollToBottom();
    }

    // Loading states
    showTyping() {
      if (this.state.isTyping) return;
      
      this.state.isTyping = true;
      const typingEl = this.templates.typing?.content.cloneNode(true);
      
      if (typingEl) {
        const messageEl = typingEl.querySelector('.chatbot-message');
        messageEl?.setAttribute('id', 'chatbot-typing-indicator');
        messageEl?.setAttribute('aria-label', 'L\'assistente sta scrivendo');
        messageEl?.setAttribute('aria-live', 'polite');
        
        this.elements.messages?.appendChild(typingEl);
        this.scrollToBottom();
      }
      
      // Announce loading for accessibility
      if (window.chatbotWidget?.accessibility) {
        window.chatbotWidget.accessibility.announceLoading();
      }
      
      this.events.emit(CONFIG.events.TYPING_START);
    }

    hideTyping() {
      if (!this.state.isTyping) return;
      
      this.state.isTyping = false;
      const typingEl = document.getElementById('chatbot-typing-indicator');
      typingEl?.remove();
      
      // Announce loading complete for accessibility
      if (window.chatbotWidget?.accessibility) {
        window.chatbotWidget.accessibility.announceLoadingComplete();
      }
      
      this.events.emit(CONFIG.events.TYPING_END);
    }

    // Error handling
    showError(error, canRetry = true) {
      this.hideTyping();
      
      const errorEl = this.templates.error?.content.cloneNode(true);
      if (!errorEl) return;

      const messageEl = errorEl.querySelector('.chatbot-error-message');
      const retryBtn = errorEl.querySelector('.chatbot-retry-button');
      
      if (messageEl) {
        const errorMessage = this.getErrorMessage(error);
        messageEl.textContent = errorMessage;
        
        // Enhance accessibility for error
        messageEl.setAttribute('role', 'alert');
        messageEl.setAttribute('aria-live', 'assertive');
        messageEl.setAttribute('aria-atomic', 'true');
        messageEl.setAttribute('aria-label', `Errore: ${errorMessage}`);
      }
      
      if (retryBtn) {
        if (canRetry) {
          retryBtn.setAttribute('aria-label', 'Riprova a inviare il messaggio');
          retryBtn.addEventListener('click', () => {
            this.events.emit('retry-last-message');
            errorEl.remove();
          });
        } else {
          retryBtn.style.display = 'none';
          retryBtn.setAttribute('aria-hidden', 'true');
        }
      }
      
      this.elements.messages?.appendChild(errorEl);
      this.scrollToBottom();
      
      this.events.emit(CONFIG.events.ERROR_OCCURRED, error);
    }

    getErrorMessage(error) {
      if (error instanceof APIError) {
        switch (error.statusCode) {
          case 401:
            return 'Sessione scaduta. Aggiorna la pagina.';
          case 429:
            return 'Troppe richieste. Riprova tra qualche minuto.';
          case 408:
            return 'Richiesta scaduta. Controlla la connessione.';
          case 500:
            return 'Errore del server. Riprova tra poco.';
          default:
            return error.message || 'Si è verificato un errore imprevisto.';
        }
      }
      
      return 'Errore di connessione. Controlla la tua connessione internet.';
    }

    // Input handling
    handleSubmit(e) {
      e.preventDefault();
      
      const message = this.elements.input?.value.trim();
      if (!message || this.state.isLoading) return;
      
      this.events.emit('send-message', message);
      this.clearInput();
    }

    handleInputChange() {
      const input = this.elements.input;
      if (!input) return;
      
      const length = input.value.length;
      const isEmpty = length === 0;
      
      // Update send button state
      if (this.elements.sendBtn) {
        this.elements.sendBtn.disabled = isEmpty || this.state.isLoading;
      }
      
      // Update character counter
      this.updateCharacterCounter(length);
      
      // Auto-resize textarea
      this.autoResizeTextarea(input);
    }

    updateCharacterCounter(length) {
      if (this.elements.charCount) {
        this.elements.charCount.textContent = length;
      }
      
      // Show counter when approaching limit
      const showCounter = length > CONFIG.maxMessageLength * 0.8;
      if (this.elements.charCounter) {
        this.elements.charCounter.style.display = showCounter ? 'block' : 'none';
      }
      
      // Warning state
      const isNearLimit = length > CONFIG.maxMessageLength * 0.9;
      this.elements.charCounter?.classList.toggle('warning', isNearLimit);
    }

    autoResizeTextarea(textarea) {
      textarea.style.height = 'auto';
      const maxHeight = parseInt(getComputedStyle(textarea).getPropertyValue('max-height'));
      const newHeight = Math.min(textarea.scrollHeight, maxHeight);
      textarea.style.height = newHeight + 'px';
    }

    handleKeyDown(e) {
      // Submit on Enter (but not Shift+Enter)
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.elements.form?.dispatchEvent(new Event('submit'));
      }
    }

    handleGlobalKeyDown(e) {
      // Close widget with Escape
      if (e.key === 'Escape' && this.state.isOpen) {
        this.closeWidget();
        return;
      }
      
      // Focus trap
      if (this.state.isOpen && e.key === 'Tab') {
        this.handleFocusTrap(e);
      }
    }

    handleFocusTrap(e) {
      if (!this.firstFocusableElement || !this.lastFocusableElement) {
        return;
      }
      
      if (e.shiftKey) {
        // Shift + Tab
        if (document.activeElement === this.firstFocusableElement) {
          e.preventDefault();
          this.lastFocusableElement.focus();
        }
      } else {
        // Tab
        if (document.activeElement === this.lastFocusableElement) {
          e.preventDefault();
          this.firstFocusableElement.focus();
        }
      }
    }

    handleOutsideClick(e) {
      if (!this.state.isOpen) return;
      
      const isInsideWidget = this.elements.widget?.contains(e.target);
      const isToggleButton = this.elements.toggleBtn?.contains(e.target);
      
      if (!isInsideWidget && !isToggleButton) {
        // Optional: close on outside click (can be disabled)
        // this.closeWidget();
      }
    }

    clearInput() {
      if (this.elements.input) {
        this.elements.input.value = '';
        this.elements.input.style.height = 'auto';
        this.handleInputChange();
      }
    }

    scrollToBottom() {
      setTimeout(() => {
        if (this.elements.messages) {
          this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
        }
      }, CONFIG.autoScrollDelay);
    }

    // Utility methods
    prefersReducedMotion() {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    updateStatus(status, text) {
      if (this.elements.status) {
        this.elements.status.innerHTML = `
          <span class="chatbot-status-indicator" aria-label="${status}"></span>
          ${text}
        `;
      }
    }
  }

  // =================================================================
  // 📡 EVENT EMITTER
  // =================================================================

  class EventEmitter {
    constructor() {
      this.events = {};
    }

    on(event, callback) {
      if (!this.events[event]) {
        this.events[event] = [];
      }
      this.events[event].push(callback);
    }

    off(event, callback) {
      if (!this.events[event]) return;
      
      const index = this.events[event].indexOf(callback);
      if (index > -1) {
        this.events[event].splice(index, 1);
      }
    }

    emit(event, data) {
      if (!this.events[event]) return;
      
      this.events[event].forEach(callback => {
        try {
          callback(data);
        } catch (error) {
          console.error(`Error in event listener for ${event}:`, error);
        }
      });
      
      // Also dispatch as DOM event for external listeners
      window.dispatchEvent(new CustomEvent(event, { detail: data }));
    }
  }

  // =================================================================
  // 📊 ANALYTICS CLASS
  // =================================================================

  class Analytics {
    constructor(apiKey, tenantId, baseURL = '') {
      this.apiKey = apiKey;
      this.tenantId = tenantId;
      this.baseURL = baseURL;
      this.sessionId = this.getOrCreateSessionId();
      this.enabled = CONFIG.enableAnalytics && apiKey && tenantId;
      this.eventQueue = [];
      this.lastEventTime = null;
      
      if (this.enabled) {
        this.trackEvent(CONFIG.analyticsEvents.WIDGET_LOADED, {
          page_url: window.location.href,
          user_agent: navigator.userAgent,
          screen_resolution: `${screen.width}x${screen.height}`,
          viewport_size: `${window.innerWidth}x${window.innerHeight}`,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          language: navigator.language
        });
      }
    }

    getOrCreateSessionId() {
      const storageKey = CONFIG.storagePrefix + CONFIG.sessionKey;
      let sessionId = sessionStorage.getItem(storageKey);
      
      if (!sessionId) {
        sessionId = this.generateSessionId();
        sessionStorage.setItem(storageKey, sessionId);
      }
      
      return sessionId;
    }

    generateSessionId() {
      return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    trackEvent(eventType, eventData = {}) {
      if (!this.enabled) return;

      const event = {
        event_type: eventType,
        session_id: this.sessionId,
        event_data: {
          ...eventData,
          timestamp: new Date().toISOString(),
          page_url: window.location.href
        }
      };

      this.lastEventTime = Date.now();

      if (CONFIG.batchAnalytics) {
        this.eventQueue.push(event);
        this.debouncedSend();
      } else {
        this.sendEvent(event);
      }
    }

    async sendEvent(event) {
      if (!this.enabled) return;

      try {
        const response = await fetch(`${this.baseURL}${CONFIG.analyticsEndpoint}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.apiKey}`,
            'X-Tenant-Id': this.tenantId.toString()
          },
          body: JSON.stringify(event)
        });

        if (!response.ok) {
          // Only warn in development mode (when console is open or specific debug flag)
          if (window.location.hostname === 'localhost' || window.chatbotDebug) {
            console.warn('Analytics event failed:', response.status, response.statusText);
          }
        }
      } catch (error) {
        // Only warn in development mode
        if (window.location.hostname === 'localhost' || window.chatbotDebug) {
          console.warn('Analytics error:', error);
        }
      }
    }

    debouncedSend() {
      clearTimeout(this.sendTimeout);
      this.sendTimeout = setTimeout(() => {
        this.sendBatch();
      }, 1000);
    }

    async sendBatch() {
      if (!this.enabled || this.eventQueue.length === 0) return;

      const events = [...this.eventQueue];
      this.eventQueue = [];

      try {
        for (const event of events) {
          await this.sendEvent(event);
        }
      } catch (error) {
        console.warn('Batch analytics error:', error);
        // Re-queue failed events
        this.eventQueue.unshift(...events);
      }
    }

    // Track specific events
    trackWidgetOpened() {
      this.trackEvent(CONFIG.analyticsEvents.CHATBOT_OPENED);
    }

    trackWidgetClosed() {
      this.trackEvent(CONFIG.analyticsEvents.CHATBOT_CLOSED);
    }

    trackMessageSent(query) {
      this.trackEvent(CONFIG.analyticsEvents.MESSAGE_SENT, {
        query: query,
        query_length: query.length
      });
    }

    trackMessageReceived(response, responseTime, citations = 0, confidence = null, tokensUsed = null) {
      this.trackEvent(CONFIG.analyticsEvents.MESSAGE_RECEIVED, {
        response: response,
        response_time: responseTime,
        citations: citations,
        confidence: confidence,
        tokens_used: tokensUsed,
        response_length: response.length
      });
    }

    trackError(error, context = '') {
      this.trackEvent(CONFIG.analyticsEvents.MESSAGE_ERROR, {
        error: error.message || error.toString(),
        context: context,
        stack: error.stack || ''
      });
    }

    trackWidgetError(error, context = '') {
      this.trackEvent(CONFIG.analyticsEvents.WIDGET_ERROR, {
        error: error.message || error.toString(),
        context: context,
        stack: error.stack || ''
      });
    }

    // Clean up before page unload
    flush() {
      if (this.eventQueue.length > 0) {
        // Try to send remaining events synchronously
        navigator.sendBeacon && this.eventQueue.forEach(event => {
          const blob = new Blob([JSON.stringify(event)], { type: 'application/json' });
          navigator.sendBeacon(`${this.baseURL}${CONFIG.analyticsEndpoint}`, blob);
        });
      }
    }
  }

  // =================================================================
  // 🤖 MAIN CHATBOT CLASS
  // =================================================================

  class ChatbotWidget {
    constructor(options = {}) {
      this.options = {
        apiKey: null,
        tenantId: null,
        baseURL: '',
        model: 'gpt-4o-mini',
        temperature: 0.7,
        maxTokens: 1000,
        enableConversationContext: true,
        enableDynamicForms: true,
        autoOpen: false,
        theme: 'default',
        formCheckCooldown: 5000, // 5 secondi tra check form
        maxFormChecks: 10, // Max 10 check per sessione
        ...options
      };

      // Form state tracking
      this.formState = {
        isActive: false,
        currentForm: null,
        submissionId: null,
        lastCheckTime: 0,
        checkCount: 0,
        cooldownActive: false
      };

      // Generate session ID for this widget instance
      this.sessionId = this.generateSessionId();

      // Find the widget container in the DOM
      this.container = document.getElementById('chatbot-widget-container');
      if (!this.container) {
        console.warn('ChatbotWidget: Container not found, widget may not work correctly');
      }

      this.state = new ChatbotState();
      
      // Initialize form renderer if enabled and available
      this.formRenderer = null;
      if (this.options.enableDynamicForms && window.ChatbotFormRenderer) {
        this.formRenderer = new ChatbotFormRenderer(this);
        // Impostalo globalmente per onclick handlers
        window.chatbotFormRenderer = this.formRenderer;
      }
      this.events = new EventEmitter();
      this.ui = new ChatbotUI(this.state, this.events);
      this.api = new ChatbotAPI(this.options.apiKey, this.options.baseURL);
      this.analytics = new Analytics(this.options.apiKey, this.options.tenantId, this.options.baseURL);
      
      // Initialize dark mode manager
      this.darkMode = null;
      if (window.ChatbotDarkModeManager) {
        this.darkMode = new window.ChatbotDarkModeManager(this);
      }
      
              // Initialize error handler
        this.errorHandler = null;
        if (window.ChatbotErrorHandler) {
            this.errorHandler = new window.ChatbotErrorHandler(this);
        }
        
        // Initialize quick actions
        this.quickActions = null;
        if (window.ChatbotQuickActions) {
            this.quickActions = new window.ChatbotQuickActions(this);
        }
        
        // Initialize fallback manager
        this.fallbackManager = null;
        if (window.ChatbotFallbackManager) {
            this.fallbackManager = new window.ChatbotFallbackManager(this);
        }
      
      // Initialize accessibility manager if available
      this.accessibility = null;
      if (window.ChatbotAccessibilityManager) {
        this.accessibility = new window.ChatbotAccessibilityManager(this);
      }
      
      // Initialize citations manager if available
      this.citations = null;
      if (window.ChatbotCitationsManager) {
        this.citations = new window.ChatbotCitationsManager(this);
      }
      
      // Initialize responsive manager if available
      this.responsive = null;
      if (window.ChatbotResponsiveManager) {
        this.responsive = new window.ChatbotResponsiveManager(this);
      }
      
      this.init();
    }

    init() {
      this.setupEventHandlers();
      this.applyConfiguration();
      
      // Attach managers to widget container if available
      if (this.fallbackManager && this.container) {
        this.fallbackManager.attachToWidget();
      }
      
      if (this.options.autoOpen) {
        setTimeout(() => this.open(), 1000);
      }
      
      // Expose global interface
      window.chatbotWidget = this;
    }

    setupEventHandlers() {
      this.events.on('send-message', (message) => this.sendMessage(message));
      this.events.on('retry-last-message', () => this.retryLastMessage());
      
      // Manual retry handler
      document.addEventListener('chatbot:manual:retry', () => {
        this.retryLastMessage();
      });
      
      // Analytics event tracking
      this.events.on(CONFIG.events.WIDGET_OPENED, () => {
        this.analytics.trackWidgetOpened();
      });
      
      this.events.on(CONFIG.events.WIDGET_CLOSED, () => {
        this.analytics.trackWidgetClosed();
      });
      
      this.events.on(CONFIG.events.MESSAGE_SENT, (data) => {
        this.analytics.trackMessageSent(data.content);
      });
      
      this.events.on(CONFIG.events.MESSAGE_RECEIVED, (data) => {
        this.analytics.trackMessageReceived(
          data.content,
          data.responseTime || null,
          data.citations?.length || 0,
          data.confidence || null,
          data.tokensUsed || null
        );
      });
      
      this.events.on(CONFIG.events.ERROR_OCCURRED, (error) => {
        this.analytics.trackError(error, 'general_error');
      });
      
      // Flush analytics on page unload
      window.addEventListener('beforeunload', () => {
        this.analytics.flush();
      });
    }

    applyConfiguration() {
      // Apply tenant configuration
      if (this.options.tenantId) {
        this.ui.elements.widget?.setAttribute('data-tenant', this.options.tenantId);
      }
      
      if (this.options.theme) {
        this.ui.elements.widget?.setAttribute('data-theme', this.options.theme);
      }
    }

    async sendMessage(content) {
      if (!content.trim() || this.state.isLoading) return;

      // Check if fallback manager should queue the message
      if (this.fallbackManager && this.fallbackManager.queueMessage(content)) {
        return; // Message was queued for later
      }

      this.state.isLoading = true;
      this.state.retryCount = 0;
      
      // Add user message to UI and state
      this.ui.addUserMessage(content);
      this.state.addMessage({ role: 'user', content });
      
      // Show typing indicator
      setTimeout(() => this.ui.showTyping(), CONFIG.typingIndicatorDelay);
      
      try {
        await this.processMessage(content);
        
        // Store successful request timestamp
        this.lastSuccessfulRequest = Date.now();
      } catch (error) {
        console.error('Error processing message:', error);
        
        // Store failed action for retry
        this.lastFailedAction = {
          retry: () => this.processMessage(content),
          content: content,
          timestamp: Date.now()
        };
        
        await this.handleMessageError(error, content);
      } finally {
        this.state.isLoading = false;
        this.ui.hideTyping();
      }
    }

    async handleMessageError(error, originalContent) {
      // Use error handler if available, otherwise fallback to default behavior
      if (this.errorHandler) {
        const shouldRetry = await this.errorHandler.handleError(error, {
          action: 'send_message',
          content: originalContent,
          attempt: this.state.retryCount
        }, error.response);
        
        if (shouldRetry && this.state.retryCount < CONFIG.maxRetries) {
          this.state.retryCount++;
          await this.processMessage(originalContent);
        } else if (!shouldRetry) {
          // Error handler showed appropriate message, don't show UI error
          return;
        }
      } else {
        // Fallback to default error handling
        this.ui.showError(error, this.state.retryCount < CONFIG.maxRetries);
      }
    }

    async processMessage(content) {
      // 🎯 PRIMA controlla se ci sono trigger per form PRIMA di inviare all'AI
      const shouldShowForm = await this.checkFormTriggersSync(content);
      if (shouldShowForm) {
        console.log('📝 Form triggered, skipping AI response');
        return; // Form mostrato, non inviare all'AI
      }
      
      const startTime = performance.now();
      
      // Prepare messages for API (conversation context)
      const messages = this.prepareMessagesForAPI();
      
      // Send to API
      const response = await this.api.sendMessage(messages, {
        model: this.options.model,
        temperature: this.options.temperature,
        maxTokens: this.options.maxTokens
      });
      
      const responseTime = performance.now() - startTime;
      
      // Add bot response to UI and state
      this.ui.addBotMessage(response.content, response.citations);
      this.state.addMessage({ 
        role: 'assistant', 
        content: response.content,
        citations: response.citations
      });
      
      // Check for form triggers after bot response (per trigger non keyword-based)
      await this.checkFormTriggers(content);
      
      // Emit event with analytics data for tracking
      this.events.emit(CONFIG.events.MESSAGE_RECEIVED, {
        content: response.content,
        citations: response.citations,
        responseTime: responseTime,
        confidence: response.confidence,
        tokensUsed: response.usage?.total_tokens
      });
    }

    prepareMessagesForAPI() {
      if (!this.options.enableConversationContext) {
        // Only send last user message
        const lastMessage = this.state.conversation[this.state.conversation.length - 1];
        return lastMessage ? [lastMessage] : [];
      }
      
      // Send full conversation for context-aware RAG
      return this.state.conversation.map(msg => ({
        role: msg.role,
        content: msg.content
      }));
    }

    async retryLastMessage() {
      if (this.state.retryCount >= CONFIG.maxRetries) {
        this.ui.showError(new Error('Maximum retry attempts reached'), false);
        return;
      }
      
      this.state.retryCount++;
      this.state.isLoading = true;
      
      // Get last user message
      const lastUserMessage = this.state.conversation
        .slice()
        .reverse()
        .find(msg => msg.role === 'user');
      
      if (lastUserMessage) {
        this.ui.showTyping();
        
        try {
          await this.processMessage(lastUserMessage.content);
        } catch (error) {
          console.error('Retry failed:', error);
          const canRetry = this.state.retryCount < CONFIG.maxRetries;
          this.ui.showError(error, canRetry);
        } finally {
          this.state.isLoading = false;
          this.ui.hideTyping();
        }
      }
    }

    // Public API methods
    open() {
      this.ui.openWidget();
    }

    close() {
      this.ui.closeWidget();
    }

    toggle() {
      if (this.state.isOpen) {
        this.close();
      } else {
        this.open();
      }
    }

    reset() {
      this.state.reset();
      if (this.ui.elements.messages) {
        // Keep welcome message, remove others
        const messages = this.ui.elements.messages.children;
        for (let i = messages.length - 1; i > 0; i--) {
          messages[i].remove();
        }
      }
    }

    updateConfig(newConfig) {
      Object.assign(this.options, newConfig);
      this.applyConfiguration();
      
      if (newConfig.apiKey) {
        this.api = new ChatbotAPI(newConfig.apiKey, this.options.baseURL);
      }
    }

    // Event system for external integration
    on(event, callback) {
      this.events.on(event, callback);
    }

    off(event, callback) {
      this.events.off(event, callback);
    }

    // =================================================================
    // 📝 FORM SYSTEM INTEGRATION
    // =================================================================

    /**
     * Controlla IMMEDIATAMENTE se bisogna attivare un form per keywords trigger
     * @param {string} userMessage - Messaggio dell'utente
     * @returns {boolean} - True se un form è stato mostrato
     */
    async checkFormTriggersSync(userMessage) {
      // Skip se form disabled o non disponibile
      if (!this.options.enableDynamicForms || !this.formRenderer) {
        return false;
      }

      // Skip se c'è già un form attivo
      if (this.formState.isActive || this.formRenderer.hasActiveForm()) {
        console.log('📝 Form già attivo, skip trigger detection');
        return false;
      }

      // Rate limiting - check cooldown
      const now = Date.now();
      if (this.formState.cooldownActive || now - this.formState.lastCheckTime < this.options.formCheckCooldown) {
        console.log('📝 Form trigger check in cooldown, skipping');
        return false;
      }

      // Max check limit per sessione
      if (this.formState.checkCount >= this.options.maxFormChecks) {
        console.log('📝 Max form checks reached for this session');
        return false;
      }

      try {
        console.log('📝 Checking IMMEDIATE form triggers for message:', userMessage);
        
        // Aggiorna counters
        this.formState.lastCheckTime = now;
        this.formState.checkCount++;
        
        // Prepara dati per trigger check
        const triggerData = {
          tenant_id: this.options.tenantId,
          message: userMessage,
          session_id: this.sessionId,
          conversation_history: this.getConversationHistoryForForms()
        };

        // Chiama API per trigger detection
        const response = await fetch(`${this.options.baseURL}/api/v1/forms/check-triggers`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.options.apiKey}`,
            'Accept': 'application/json',
            'X-Requested-With': 'ChatbotWidget'
          },
          body: JSON.stringify(triggerData)
        });

        if (!response.ok) {
          throw new Error(`Form trigger check failed: ${response.status}`);
        }

        const result = await response.json();
        
        if (result.triggered && result.form) {
          console.log('📝 IMMEDIATE Form triggered:', result.form.form_name);
          
          // Aggiorna form state
          this.formState.isActive = true;
          this.formState.currentForm = result.form;
          
          // Mostra il form IMMEDIATAMENTE
          this.formRenderer.showForm(result.form);
          
          // Track evento analytics
          this.analytics?.trackEvent('form_triggered_immediate', {
            form_id: result.form.form_id,
            form_name: result.form.form_name,
            trigger_type: result.form.trigger_type,
            trigger_value: result.form.trigger_value,
            session_id: this.sessionId,
            check_count: this.formState.checkCount
          });

          // Attiva cooldown temporaneo per evitare spam
          this.formState.cooldownActive = true;
          setTimeout(() => {
            this.formState.cooldownActive = false;
          }, this.options.formCheckCooldown * 2);
          
          return true; // Form mostrato!
        }
        
        return false; // Nessun form trigger

      } catch (error) {
        console.error('📝 Error checking IMMEDIATE form triggers:', error);
        
        // Track errore per analytics
        this.analytics?.trackEvent('form_trigger_error_immediate', {
          error: error.message,
          session_id: this.sessionId,
          check_count: this.formState.checkCount
        });
        
        return false; // In caso di errore, non bloccare il flusso normale
      }
    }

    /**
     * Controlla se bisogna attivare un form dopo un messaggio utente (versione normale)
     * @param {string} userMessage - Messaggio dell'utente
     */
    async checkFormTriggers(userMessage) {
      // Skip se form disabled o non disponibile
      if (!this.options.enableDynamicForms || !this.formRenderer) {
        return;
      }

      // Skip se c'è già un form attivo
      if (this.formState.isActive || this.formRenderer.hasActiveForm()) {
        console.log('📝 Form già attivo, skip trigger detection');
        return;
      }

      // Rate limiting - check cooldown
      const now = Date.now();
      if (this.formState.cooldownActive || now - this.formState.lastCheckTime < this.options.formCheckCooldown) {
        console.log('📝 Form trigger check in cooldown, skipping');
        return;
      }

      // Max check limit per sessione
      if (this.formState.checkCount >= this.options.maxFormChecks) {
        console.log('📝 Max form checks reached for this session');
        return;
      }

      try {
        console.log('📝 Checking form triggers for message:', userMessage);
        
        // Aggiorna counters
        this.formState.lastCheckTime = now;
        this.formState.checkCount++;
        
        // Prepara dati per trigger check
        const triggerData = {
          tenant_id: this.options.tenantId,
          message: userMessage,
          session_id: this.sessionId,
          conversation_history: this.getConversationHistoryForForms()
        };

        // Chiama API per trigger detection
        const response = await fetch(`${this.options.baseURL}/api/v1/forms/check-triggers`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.options.apiKey}`,
            'Accept': 'application/json',
            'X-Requested-With': 'ChatbotWidget'
          },
          body: JSON.stringify(triggerData)
        });

        if (!response.ok) {
          throw new Error(`Form trigger check failed: ${response.status}`);
        }

        const result = await response.json();
        
        if (result.triggered && result.form) {
          console.log('📝 Form triggered:', result.form.form_name);
          
          // Aggiorna form state
          this.formState.isActive = true;
          this.formState.currentForm = result.form;
          
          // Mostra il form
          this.formRenderer.showForm(result.form);
          
          // Track evento analytics
          this.analytics?.trackEvent('form_triggered', {
            form_id: result.form.form_id,
            form_name: result.form.form_name,
            trigger_type: result.form.trigger_type,
            trigger_value: result.form.trigger_value,
            session_id: this.sessionId,
            check_count: this.formState.checkCount
          });

          // Attiva cooldown temporaneo per evitare spam
          this.formState.cooldownActive = true;
          setTimeout(() => {
            this.formState.cooldownActive = false;
          }, this.options.formCheckCooldown * 2);
        }

      } catch (error) {
        console.error('📝 Error checking form triggers:', error);
        
        // Track errore per analytics
        this.analytics?.trackEvent('form_trigger_error', {
          error: error.message,
          session_id: this.sessionId,
          check_count: this.formState.checkCount
        });
        
        // Non bloccare il flusso normale per errori dei form
      }
    }

    /**
     * Ottieni cronologia conversazione per form triggers
     * @returns {Array} History formattata per trigger detection
     */
    getConversationHistoryForForms() {
      // Usa this.state.conversation invece di getMessages()
      const messages = this.state.conversation || [];
      return messages.slice(-5).map(msg => {
        // Converte timestamp in formato Laravel Y-m-d H:i:s
        let formattedTimestamp;
        if (msg.timestamp) {
          const date = new Date(msg.timestamp);
          formattedTimestamp = date.toISOString().slice(0, 19).replace('T', ' ');
        } else {
          const now = new Date();
          formattedTimestamp = now.toISOString().slice(0, 19).replace('T', ' ');
        }
        
        return {
          role: msg.role,
          content: msg.content,
          timestamp: formattedTimestamp
        };
      });
    }

    /**
     * Aggiungi messaggio custom (per form o altri componenti)
     * @param {HTMLElement} element - Elemento da aggiungere
     */
    addCustomMessage(element) {
      if (!element) return;
      
      const messagesContainer = document.querySelector('.chatbot-messages');
      if (messagesContainer) {
        messagesContainer.appendChild(element);
        this.scrollToBottom();
      }
    }

    /**
     * Ottieni cronologia conversazione
     * @returns {Array} Messaggi conversazione
     */
    getConversationHistory() {
      return this.state.conversation || [];
    }

    /**
     * Scroll al bottom del widget
     */
    scrollToBottom() {
      const messagesContainer = document.querySelector('.chatbot-messages');
      if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
      }
    }

    /**
     * Callback quando form viene inviato con successo
     * @param {Object} submission - Dati submission
     */
    onFormSubmitted(submission) {
      console.log('📝 Form submitted successfully:', submission);
      
      // Aggiorna form state
      this.formState.isActive = false;
      this.formState.submissionId = submission.submission_id;
      this.formState.currentForm = null;
      
      // Track analytics
      this.analytics?.trackEvent('form_submitted', {
        form_id: this.formState.currentForm?.form_id,
        submission_id: submission.submission_id,
        session_id: this.sessionId,
        success: true
      });
      
      // Reset form check counters e cooldown per permettere nuovi form immediati
      this.formState.checkCount = Math.max(0, this.formState.checkCount - 2);
      this.formState.lastCheckTime = 0; // Reset cooldown per permettere trigger immediati
      this.formState.cooldownActive = false;
    }

    /**
     * Callback quando form viene cancellato
     */
    onFormCancelled() {
      console.log('📝 Form cancelled');
      
      // Aggiorna form state
      this.formState.isActive = false;
      this.formState.currentForm = null;
      
      // Reset cooldown per permettere nuovi trigger immediati
      this.formState.lastCheckTime = 0;
      this.formState.cooldownActive = false;
      
      // Track analytics
      this.analytics?.trackEvent('form_cancelled', {
        form_id: this.formState.currentForm?.form_id,
        session_id: this.sessionId
      });
    }

    /**
     * Callback quando form ha errore
     * @param {Object} error - Errore
     */
    onFormError(error) {
      console.error('📝 Form error:', error);
      
      // Aggiorna form state
      this.formState.isActive = false;
      this.formState.currentForm = null;
      
      // Reset cooldown per permettere nuovi trigger immediati
      this.formState.lastCheckTime = 0;
      this.formState.cooldownActive = false;
      
      // Track analytics
      this.analytics?.trackEvent('form_error', {
        form_id: this.formState.currentForm?.form_id,
        session_id: this.sessionId,
        error: error.message || 'Unknown error'
      });
    }

    /**
     * Ottieni stato form corrente
     * @returns {Object} Form state
     */
    getFormState() {
      return { ...this.formState };
    }

    /**
     * Reset stato form (per debugging)
     */
    resetFormState() {
      this.formState = {
        isActive: false,
        currentForm: null,
        submissionId: null,
        lastCheckTime: 0,
        checkCount: 0,
        cooldownActive: false
      };
      console.log('📝 Form state reset');
    }

    /**
     * Genera un session ID univoco
     */
    generateSessionId() {
      const timestamp = Date.now();
      const random = Math.random().toString(36).substring(2, 15);
      return `session_${timestamp}_${random}`;
    }

    /**
     * Aggiungi messaggio custom (per form o altri componenti)
     * @param {HTMLElement} element - Elemento da aggiungere
     */
    addCustomMessage(element) {
      if (!element) {
        console.error('ChatbotWidget: Cannot add null/undefined custom message');
        return;
      }
      
      // Delega all'UI per aggiungere l'elemento custom
      if (this.ui && this.ui.appendMessage) {
        this.ui.appendMessage(element);
      } else {
        console.error('ChatbotWidget: UI not available for custom message');
      }
    }
  }

  // =================================================================
  // 🚀 INITIALIZATION
  // =================================================================

  // Auto-initialize if configuration is present
  document.addEventListener('DOMContentLoaded', function() {
    // Look for configuration in data attributes or global config
    const widgetElement = document.getElementById('chatbot-widget');
    if (!widgetElement) return;
    
    const config = {
      tenantId: widgetElement.dataset.tenant,
      theme: widgetElement.dataset.theme,
      // Add other config from data attributes or window.chatbotConfig
      ...(window.chatbotConfig || {})
    };
    
    // Only initialize if we have required config
    if (config.apiKey || window.chatbotConfig?.apiKey) {
      new ChatbotWidget(config);
    } else {
      console.warn('Chatbot widget: API key required for initialization');
    }
  });

  // Export for manual initialization
  window.ChatbotWidget = ChatbotWidget;
  
  // Global access per form renderer (needed for onclick handlers)
  window.chatbotFormRenderer = null;

})();
