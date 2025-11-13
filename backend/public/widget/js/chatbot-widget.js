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
  
  // Version check log
  console.log('🤖 Chatbot Widget Loading v1.3.4.MARKDOWN_MASKING...', new Date().toISOString());
console.warn('🔧 MARKDOWN FIX: Should see "🔧 Markdown URL masking" + "🔧 Converting markdown link"');

  // =================================================================
  // 🔌 CONFIGURATION & CONSTANTS
  // =================================================================

  const CONFIG = {
    // API Configuration
    apiEndpoint: '/api/v1/chat/completions',
    analyticsEndpoint: '/api/v1/widget/events/public',
    // 🎯 Agent Console API endpoints
    conversationEndpoint: '/api/v1/conversations',
    messageEndpoint: '/api/v1/conversations/messages',
    handoffEndpoint: '/api/v1/handoffs',
    version: '1.0.1-agent-console', // Updated version
    maxRetries: 3,
    retryDelay: 1000,
    requestTimeout: 45000,
    
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
    // 🎯 Agent Console storage keys
    agentSessionKey: 'agent_session_id',
    handoffStatusKey: 'handoff_status',
    
    // Events
    events: {
      WIDGET_OPENED: 'chatbot:widget:opened',
      WIDGET_CLOSED: 'chatbot:widget:closed',
      MESSAGE_SENT: 'chatbot:message:sent',
      MESSAGE_RECEIVED: 'chatbot:message:received',
      ERROR_OCCURRED: 'chatbot:error',
      TYPING_START: 'chatbot:typing:start',
      TYPING_END: 'chatbot:typing:end',
      // 🎯 Agent Console events
      HANDOFF_REQUESTED: 'chatbot:handoff:requested',
      HANDOFF_ACCEPTED: 'chatbot:handoff:accepted',
      OPERATOR_JOINED: 'chatbot:operator:joined',
      OPERATOR_TYPING: 'chatbot:operator:typing'
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
      this.conversationMetadata = {
        createdAt: null,
        lastMessageAt: null,
        messageCount: 0,
        version: '1.1.0'
      };
      
      // Load persisted state
      this.loadFromStorage();
    }

    loadFromStorage() {
      try {
        // Load conversation
        const stored = localStorage.getItem(CONFIG.storagePrefix + CONFIG.conversationKey);
        
        if (stored) {
          const data = JSON.parse(stored);
          
          // Support legacy format (array) and new format (object)
          if (Array.isArray(data)) {
            this.conversation = data;
            this.conversationMetadata = {
              createdAt: data.length > 0 ? (data[0].timestamp || new Date().toISOString()) : null,
              lastMessageAt: data.length > 0 ? (data[data.length - 1].timestamp || new Date().toISOString()) : null,
              messageCount: data.length,
              version: '1.0.0' // Legacy
            };
          } else if (data && data.conversation && data.metadata) {
            this.conversation = data.conversation;
            this.conversationMetadata = { ...this.conversationMetadata, ...data.metadata };
          }
        }
        
        // Clean old conversations (older than 7 days)
        this.cleanOldConversations();
        
        if (this.conversation.length > 0) {
          console.log('💾 Loaded conversation from storage:', this.conversation.length, 'messages');
        }
        
      } catch (error) {
        console.warn('Could not load conversation from storage:', error);
        this.conversation = [];
        this.conversationMetadata = {
          createdAt: null,
          lastMessageAt: null,
          messageCount: 0,
          version: '1.1.0'
        };
      }
    }

    saveToStorage() {
      try {
        // Update metadata
        this.conversationMetadata.messageCount = this.conversation.length;
        if (this.conversation.length > 0) {
          if (!this.conversationMetadata.createdAt) {
            this.conversationMetadata.createdAt = this.conversation[0].timestamp;
          }
          this.conversationMetadata.lastMessageAt = this.conversation[this.conversation.length - 1].timestamp;
        }
        
        // Save in new format with metadata
        const dataToSave = {
          conversation: this.conversation.slice(-50), // Keep last 50 messages (increased limit)
          metadata: this.conversationMetadata,
          savedAt: new Date().toISOString()
        };
        
        localStorage.setItem(
          CONFIG.storagePrefix + CONFIG.conversationKey,
          JSON.stringify(dataToSave)
        );
        
        console.log('💾 Conversation saved to storage:', this.conversationMetadata.messageCount, 'messages');
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
      this.conversationMetadata = {
        createdAt: null,
        lastMessageAt: null,
        messageCount: 0,
        version: '1.1.0'
      };
      this.saveToStorage();
    }

    cleanOldConversations() {
      if (!this.conversationMetadata.lastMessageAt) return;
      
      const lastMessage = new Date(this.conversationMetadata.lastMessageAt);
      const now = new Date();
      const daysDiff = (now - lastMessage) / (1000 * 60 * 60 * 24);
      
      // Auto-clear conversations older than 7 days
      if (daysDiff > 7) {
        console.log('🧹 Clearing old conversation (', Math.round(daysDiff), 'days old)');
        this.reset();
      }
    }

    hasStoredConversation() {
      return this.conversation.length > 0;
    }

    getConversationAge() {
      if (!this.conversationMetadata.lastMessageAt) return null;
      
      const lastMessage = new Date(this.conversationMetadata.lastMessageAt);
      const now = new Date();
      const diffMs = now - lastMessage;
      
      // Return human-readable age
      const minutes = Math.floor(diffMs / (1000 * 60));
      const hours = Math.floor(minutes / 60);
      const days = Math.floor(hours / 24);
      
      if (days > 0) return `${days} giorno${days > 1 ? 'i' : ''} fa`;
      if (hours > 0) return `${hours} ora${hours > 1 ? 'e' : ''} fa`;
      if (minutes > 0) return `${minutes} minuto${minutes > 1 ? 'i' : ''} fa`;
      return 'Ora';
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
      
      // 🚀 Enable streaming by default for better UX
      const enableStreaming = options.stream !== false;
      
      const payload = {
        model: options.model || 'gpt-4o-mini',
        messages: messages,
        temperature: options.temperature || 0.7,
        max_tokens: options.maxTokens || 1000,
        stream: enableStreaming,
        ...options.additionalParams
      };

      // 🔍 Debug session ID and context
      console.log('🔍 sendMessage context debug:', {
        thisType: typeof this,
        hasConversationTracker: !!this.conversationTracker,
        conversationTracker: this.conversationTracker,
        thisKeys: this ? Object.keys(this).filter(k => !k.startsWith('_')) : 'null',
        thisKeysAll: this ? Object.keys(this) : 'null',
        constructor: this?.constructor?.name || 'unknown'
      });
      
      // 🎯 Use session ID from options (passed by ChatbotWidget)
      const sessionId = options.sessionId || '';
      console.log('🔍 Sending chat request with session ID:', {
        sessionId: sessionId,
        sessionIdSource: options.sessionId ? 'from_options' : 'empty',
        hasOptions: !!options
      });

      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), CONFIG.requestTimeout);

      try {
        // 🚀 STREAMING: Handle SSE if enabled
        if (enableStreaming && options.onChunk) {
          return await this.handleStreamingResponse(url, payload, sessionId, options.onChunk, controller);
        }

        // Non-streaming fallback
        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.apiKey}`,
            'Accept': 'application/json',
            'X-Requested-With': 'ChatbotWidget',
            'X-Session-ID': sessionId
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

    async handleStreamingResponse(url, payload, sessionId, onChunk, controller) {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${this.apiKey}`,
          'Accept': 'text/event-stream',
          'X-Requested-With': 'ChatbotWidget',
          'X-Session-ID': sessionId
        },
        body: JSON.stringify(payload),
        signal: controller.signal
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new APIError(
          errorData.error?.message || `HTTP ${response.status}`,
          response.status,
          errorData
        );
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let accumulated = '';
      let buffer = '';

      try {
        while (true) {
          const { done, value } = await reader.read();
          
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop(); // Keep incomplete line in buffer

          for (const line of lines) {
            if (!line.trim() || !line.startsWith('data: ')) continue;

            const data = line.slice(6).trim();
            
            if (data === '[DONE]') {
              break;
            }

            try {
              const chunk = JSON.parse(data);
              
              if (chunk.error) {
                throw new APIError(chunk.error.message || 'Streaming error', 500, chunk.error);
              }

              const delta = chunk.choices?.[0]?.delta?.content || '';
              
              if (delta) {
                accumulated += delta;
                onChunk(delta, accumulated);
              }
            } catch (e) {
              if (e instanceof APIError) throw e;
              console.warn('Failed to parse SSE chunk:', e);
            }
          }
        }
      } finally {
        reader.releaseLock();
      }

      // Return final response in standard format
      return {
        choices: [{
          message: {
            role: 'assistant',
            content: accumulated
          },
          finish_reason: 'stop'
        }]
      };
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

      // 🔍 DETECTA SE IL CONTENUTO CONTIENE GIÀ HTML VALIDO (link, tag, ecc.)
      const containsHtml = /<[^>]+>/g.test(text) || /&[a-zA-Z0-9#]+;/.test(text);
      
      if (containsHtml) {
        // Se contiene già HTML, NON fare escape e NON riprocessare i link
        console.log('[ChatbotUI] Content contains HTML, applying fixes only');
        
        // 🔧 FIX: Ripara link malformati che causano problemi di rendering
        
        // Pattern 1: Fix attributi HTML che finiscono nel testo
        // Es: 'testo" target="_blank" rel="noopener noreferrer" class="chatbot-link">Altri testo'
        html = html.replace(/([^<>"]+)" target="_blank" rel="noopener noreferrer" class="chatbot-link">([^<]+)/g, 
          (match, beforeText, afterText) => {
            console.log('🔧 Fixing malformed HTML attributes:', match.substring(0, 100));
            return beforeText + ' ' + afterText;
          });
        
        // Pattern 2: Link annidati malformati
        html = html.replace(/<a href="[^"]*<a href="([^"]+)"[^>]*>([^<]*)<\/a>[^"]*"[^>]*>([^<]+)<\/a>/g, 
          '<a href="$1" target="_blank" rel="noopener noreferrer" class="chatbot-link">$3</a>');
        
        // Pattern 3: Link con attributi duplicati
        html = html.replace(/<a href="([^"]+)"[^>]*><a href="[^"]*"[^>]*>([^<]+)<\/a><\/a>/g,
          '<a href="$1" target="_blank" rel="noopener noreferrer" class="chatbot-link">$2</a>');
          
        // Pattern 4: Pulisci link vuoti rimasti
        html = html.replace(/<a href="[^"]*"[^>]*><\/a>/g, '');
        
        // Pattern 5: Fix tag HTML orfani nel testo
        html = html.replace(/([^<>]+)(<\/[^>]+>)/g, '$1');
        
        // Sanitizza solo caratteri pericolosi ma preserva HTML esistente
        html = html.replace(/&(?![a-zA-Z0-9#]+;)/g, '&amp;'); // Solo & non già escaped
        
        // Ritorna direttamente il contenuto HTML così com'è
        return html;
      }
      
      // Se non contiene HTML, procedi con il normale markdown processing
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
      
      // 🔧 DEBUG: Log contenuto grezzo per vedere i markdown con placeholder
      console.log('🔧 RAW CONTENT BEFORE PROCESSING:', html.substring(0, 1000));
      
      // Maschera https:// URLs - versione migliorata per evitare malformazioni
      // 🔧 CRITICAL FIX: Pattern specifico per markdown links per preservare parentesi
      html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, (match, text, url) => {
        console.log('🔧 Markdown URL masking:', match, '→', `[${text}](###URLMASK${urlCounter}###)`);
        const placeholder = `###URLMASK${urlCounter++}###`;
        urlPlaceholders.push({ placeholder, url });
        return `[${text}](${placeholder})`;
      });
      
      // Poi maschera URL standalone (non in markdown)
      html = html.replace(/(https?:\/\/[^\s<"']+?)(?=[\s<"']|$)/g, (match) => {
        // Skip se già processato come markdown
        if (html.includes(`](${match})`)) {
          return match;
        }
        
        // 🔧 CRITICAL FIX: Preserva parentesi per URL che finiscono con numeri (es. idtesto/20247)
        let cleanUrl = match;
        // Rimuovi solo caratteri di punteggiatura finali MA non ) se l'URL finisce con numeri
        if (/[.,;:!?"'>]$/.test(cleanUrl) && !/\/\d+$/.test(cleanUrl)) {
          cleanUrl = cleanUrl.replace(/[.,;:!?"'>]+$/, '');
        }
        console.log('🔧 Standalone URL masking preserving:', match, '→', cleanUrl);
        const placeholder = `###URLMASK${urlCounter++}###`;
        urlPlaceholders.push({ placeholder, url: cleanUrl });
        return placeholder;
      });
      
      // Maschera www. URLs - versione migliorata
      html = html.replace(/(?<!["\[>])(www\.[^\s<"']+?)(?=[\s<"']|$)/g, (match, capturedGroup, offset, string) => {
        // 🔧 CRITICAL FIX: NON mascherare www. URLs che sono già dentro un markdown link
        // Cerca se questo match è parte di un pattern [text](url) guardando indietro
        // ⚡ BUG FIX: Aggiunti tutti i parametri corretti (match, capturedGroup, offset, string)
        const beforeMatch = string.substring(Math.max(0, offset - 200), offset);
        const afterMatch = string.substring(offset, Math.min(string.length, offset + 10));
        
        // Se c'è un'apertura di markdown link [...] prima e ](url) dopo, skip
        if (beforeMatch.includes('[') && afterMatch.startsWith(match + '](')) {
          console.log('🔧 Skipping www. URL inside markdown link:', match);
          return match; // Non mascherare
        }
        
        // 🔧 CRITICAL FIX: Preserva parentesi per URL che finiscono con numeri (es. idtesto/20247)
        let cleanUrl = match;
        // Rimuovi solo caratteri di punteggiatura finali MA non ) se l'URL finisce con numeri
        if (/[.,;:!?"'>]$/.test(cleanUrl) && !/\/\d+$/.test(cleanUrl)) {
          cleanUrl = cleanUrl.replace(/[.,;:!?"'>]+$/, '');
        }
        const placeholder = `###URLMASK${urlCounter++}###`;
        urlPlaceholders.push({ placeholder, url: cleanUrl });
        return placeholder;
      });

      // 🔧 DEBUG: Log contenuto dopo URL masking per vedere placeholder
      console.log('🔧 CONTENT AFTER URL MASKING:', html.substring(0, 1000));
      
      // 4. Bold (**text** o __text__) - ora sicuro dagli URL con nuovo formato placeholder
      html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong class="chatbot-bold">$1</strong>')
                .replace(/__([^_\n]+)__/g, '<strong class="chatbot-bold">$1</strong>');

      // 5. Italic (*text* o _text_) - ora sicuro dagli URL con nuovo formato placeholder  
      html = html.replace(/\*([^*\n]+)\*/g, '<em class="chatbot-italic">$1</em>')
                .replace(/_([^_\n]+)_/g, '<em class="chatbot-italic">$1</em>');

      // 6a. CRITICAL FIX: Link markdown senza parentesi di chiusura (v1.2.7)
      // Questo è il fix principale per il problema dell'utente CIE
      html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s\n]+)(?=\s|$|\n)/g, (match, text, url) => {
        console.warn('🔧 CRITICAL FIX v1.2.7 APPLIED - Missing closing parenthesis:', match);
        console.log('🔧 Converting to HTML:', text, '→', url);
        // Convertiamo direttamente in HTML link funzionante
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
      });

      // 6b. Links markdown [text](url) - gestisce URL completi e troncati  
      // FIXED: Pattern specifico per URLMASK placeholders + URL normali
      
      // Prima gestisci URLMASK placeholders specificamente
      html = html.replace(/\[([^\]]+)\]\((###URLMASK\d+###)\)/g, (match, text, placeholder) => {
        console.log('🔧 URLMASK placeholder found:', match);
        console.log('🔧 Text:', text, 'Placeholder:', placeholder);
        return match; // Lascia intatto per step 7
      });
      
      // Poi gestisci URL normali (non URLMASK)
      html = html.replace(/\[([^\]]+)\]\(([^)\s]+(?:\s[^)]*)?)\)/g, (match, text, url) => {
        // Skip se è già un URLMASK (non dovrebbe succedere dopo il regex sopra)
        if (url.trim().startsWith('###URLMASK') && url.trim().endsWith('###')) {
          console.log('🔧 Skipping remaining URLMASK:', match);
          return match;
        }
        
        // 🔧 CRITICAL FIX: Pulisce l'URL ma preserva integrità del link
        let cleanUrl = url.trim();
        
        // Rimuovi solo caratteri di punteggiatura finali MA solo se l'URL non finisce con numeri/lettere valide
        // Questo evita di rimuovere parentesi da URL che finiscono con ID numerici come "idtesto/20247"
        if (/[.,;:!?"'>]$/.test(cleanUrl) && !/\/\d+$/.test(cleanUrl)) {
          cleanUrl = cleanUrl.replace(/[.,;:!?"'>]+$/, '');
        }
        
        // Validazione URL più robusta
        let finalUrl;
        if (cleanUrl.match(/^https?:\/\//)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.match(/^mailto:/)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.match(/^tel:/)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.startsWith('www.')) {
          finalUrl = `https://${cleanUrl}`;
        } else if (cleanUrl.startsWith('/')) {
          finalUrl = cleanUrl; // Relative URL
        } else {
          // Se non è un URL valido, non creare il link
          console.warn('🔍 Invalid URL in markdown link:', cleanUrl);
          return `${text} (${cleanUrl})`; // Fallback a testo normale
        }
        
        return `<a href="${finalUrl}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
      });

      // 7. RESTORE URLs e linkificali
      urlPlaceholders.forEach(({ placeholder, url }) => {
        // Assicurati che l'URL sia pulito e valido
        const cleanUrl = url.trim();
        const href = cleanUrl.startsWith('www.') ? `http://${cleanUrl}` : cleanUrl;
        
        // 🔧 CRITICAL FIX: Ripristina placeholder sia in markdown che in HTML
        // Il problema era che il markdown viene convertito in HTML PRIMA del ripristino,
        // quindi il pattern markdown non trova più il placeholder
        
        // 1. Cerca e sostituisci in tag HTML già convertiti: <a href="###URLMASK0###">
        const htmlPattern = new RegExp(`<a([^>]*?)href="${placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"([^>]*?)>`, 'g');
        if (html.match(htmlPattern)) {
          console.log('🔧 Restoring URL in HTML link:', placeholder, '→', href);
          html = html.replace(htmlPattern, `<a$1href="${href}"$2>`);
        }
        
        // 2. Cerca e sostituisci in markdown ancora non convertito: [text](###URLMASK0###)
        const markdownPattern = new RegExp(`\\[([^\\]]+)\\]\\(${placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\)`, 'g');
        const markdownMatches = html.match(markdownPattern);
        
        if (markdownMatches) {
          console.log('🔧 Converting markdown link with placeholder:', markdownMatches[0], '→', href);
          html = html.replace(markdownPattern, `<a href="${href}" target="_blank" rel="noopener noreferrer" class="chatbot-link">$1</a>`);
        }
        
        // 2.b Fix casi con testo senza "[" (es. "https://...](###URLMASK0###)")
        const orphanPattern = new RegExp(`([\\w\\-.:/?#%=&+@]+)\\]\\(${placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\)`, 'g');
        if (orphanPattern.test(html)) {
          html = html.replace(orphanPattern, (_match, linkText) => {
            const text = linkText.trim();
            return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
          });
        }

        // 3. Fallback: sostituisci placeholder standalone (non in link)
        if (html.includes(placeholder)) {
          console.log('🔧 Replacing standalone placeholder:', placeholder, '→', href);
          const linkedUrl = `<a href="${href}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${cleanUrl}</a>`;
          html = html.replaceAll ? html.replaceAll(placeholder, linkedUrl) : html.replace(new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), linkedUrl);
        }
      });
      
      // 7.5. FALLBACK FINALE: Converti qualsiasi link markdown rimasto non processato
      // Questo è un safety net per catturare link markdown che potrebbero essere sfuggiti al processing sopra
      html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, (match, text, url) => {
        console.warn('🚨 FALLBACK: Converting remaining markdown link:', match);
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
      });
      
      // 🔧 DEBUG: Log finale per vedere se ci sono ancora link markdown non convertiti
      const remainingMarkdownLinks = html.match(/\[([^\]]+)\]\(([^)]+)\)/g);
      if (remainingMarkdownLinks) {
        console.error('🚨 STILL REMAINING MARKDOWN LINKS:', remainingMarkdownLinks);
      } else {
        console.log('✅ All markdown links should be converted');
      }
      
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

      // 12. Tables (Markdown tables)
      html = this.parseTables(html);

      // 13. Line breaks (doppio spazio + newline o doppio newline)
      html = html.replace(/  \n/g, '<br>')
                .replace(/\n\n/g, '<br><br>')
                .replace(/\n/g, '<br>');

      // 14. Strikethrough (~~text~~)
      html = html.replace(/~~([^~\n]+)~~/g, '<del class="chatbot-strikethrough">$1</del>');

      return this.sanitize(html);
    }

    static sanitize(html) {
      if (typeof DOMParser === 'undefined') {
        return html
          .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
          .replace(/on\w+="[^"]*"/g, '')
          .replace(/javascript:/gi, 'blocked:');
      }

      try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(`<div>${html}</div>`, 'text/html');
        const container = doc.querySelector('div') || doc.body;

        const ALLOWED_TAGS = new Set([
          'a', 'p', 'br', 'strong', 'em', 'code', 'pre', 'span', 'div', 'ul',
          'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
          'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'del', 'blockquote', 'hr'
        ]);

        const ALLOWED_ATTRS = {
          a: ['href', 'target', 'rel', 'title', 'aria-label', 'class'],
          div: ['class', 'role'],
          span: ['class'],
          p: ['class'],
          strong: ['class'],
          em: ['class'],
          code: ['class'],
          pre: ['class'],
          ul: ['class'],
          ol: ['class'],
          li: ['class'],
          table: ['class', 'role'],
          thead: ['class'],
          tbody: ['class'],
          tfoot: ['class'],
          tr: ['class'],
          th: ['class', 'scope', 'colspan', 'rowspan'],
          td: ['class', 'colspan', 'rowspan'],
          h1: ['class'],
          h2: ['class'],
          h3: ['class'],
          h4: ['class'],
          h5: ['class'],
          h6: ['class'],
          blockquote: ['class'],
          del: ['class']
        };

        const sanitizeNode = (node) => {
          const children = Array.from(node.childNodes);

          children.forEach((child) => {
            if (child.nodeType === 1) { // Element
              const tag = child.tagName.toLowerCase();

              if (!ALLOWED_TAGS.has(tag)) {
                const fragment = doc.createDocumentFragment();
                while (child.firstChild) {
                  fragment.appendChild(child.firstChild);
                }
                child.replaceWith(fragment);
                return;
              }

              const allowed = ALLOWED_ATTRS[tag] || [];
              Array.from(child.attributes).forEach((attr) => {
                const name = attr.name.toLowerCase();
                if (!allowed.includes(name) || attr.value.includes('javascript:')) {
                  child.removeAttribute(attr.name);
                }
              });

              if (tag === 'a') {
                const href = child.getAttribute('href') || '';
                const safeHref = href.trim();
                const isSafeProtocol = safeHref === '' ||
                  safeHref.startsWith('#') ||
                  safeHref.startsWith('/') ||
                  safeHref.startsWith('mailto:') ||
                  safeHref.startsWith('tel:') ||
                  safeHref.startsWith('http://') ||
                  safeHref.startsWith('https://');

                if (!isSafeProtocol) {
                  child.removeAttribute('href');
                }

                if (child.getAttribute('target') === '_blank') {
                  child.setAttribute('rel', 'noopener noreferrer');
                }
              }

              sanitizeNode(child);
            } else if (child.nodeType === 8) { // Comment
              child.remove();
            }
          });
        };

        sanitizeNode(container);
        return container.innerHTML;
      } catch (error) {
        console.warn('Markdown sanitization fallback due to error:', error);
        return html
          .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
          .replace(/on\w+="[^"]*"/g, '')
          .replace(/javascript:/gi, 'blocked:');
      }
    }

    static parseTables(html) {
      console.log('🔍 parseTables called with content length:', html.length);
      console.log('🔍 Content contains pipes:', html.includes('|'));
      
      // 🔧 ENHANCED: Regex per rilevare tabelle Markdown con o senza newline
      const tableRegex = /(\|[^\n]+\|\n\|[\s\-:|]+\|\n(?:\|[^\n]+\|\n?)+)/g;
      
      // 🚀 NUOVO: Regex più aggressivo per tabelle compatte senza newline
      // Cerca pattern: | header | header | |----| | data | data | | data | data |
      // Versione più permissiva che cattura anche tabelle malformate
      const compactTableRegex = /(\|[^|]*\|[^|]*\|[^|]*\|[\s\-:|]+\|[^|]*(?:\|[^|]*\|[^|]*\|[^|]*)+)/g;
      
      // Prima prova il regex standard (con newline)
      let result = html.replace(tableRegex, (match) => {
        console.log('🔍 Standard table regex matched:', match.substring(0, 100) + '...');
        return this.processTableMatch(match, true);
      });
      
      // Poi prova il regex per tabelle compatte (senza newline) - più aggressivo
      result = result.replace(compactTableRegex, (match) => {
        console.log('🔍 Compact table regex matched:', match.substring(0, 100) + '...');
        
        // Verifica che non sia già stata processata
        if (match.includes('<table') || match.includes('&lt;table')) {
          console.log('🔍 Table already processed, skipping');
          return match;
        }
        
        // Verifica che abbia almeno 3 | per riga (minimo per essere una tabella)
        const pipeCount = (match.match(/\|/g) || []).length;
        console.log('🔍 Pipe count:', pipeCount);
        if (pipeCount < 9) {
          console.log('🔍 Not enough pipes for table, skipping');
          return match; // Almeno header (3) + separator (3) + 1 riga (3)
        }
        
        return this.processTableMatch(match, false);
      });
      
      // 🚀 FALLBACK FINALE: Se non ha trovato tabelle ma ci sono molti pipe, prova parsing manuale
      if (!result.includes('<table>') && html.includes('|')) {
        const pipeCount = (html.match(/\|/g) || []).length;
        console.log('🔍 No tables found but', pipeCount, 'pipes detected, trying manual parsing');
        
        if (pipeCount >= 12) { // Almeno 4 colonne x 3 righe
          result = this.parseTableManually(result);
        }
      }
      
      return result;
    }

    static processTableMatch(match, hasNewlines) {
      let lines;
      
      if (hasNewlines) {
        lines = match.trim().split('\n');
      } else {
        // 🔧 NUOVO APPROCCIO: Parser più intelligente per tabelle compatte
        // Esempio input: "| A | B | |---| | C | D | | E | F |"
        
        // Step 1: Trova tutte le sezioni che iniziano e finiscono con |
        const segments = match.match(/\|[^|]*(?:\|[^|]*)*\|/g) || [];
        lines = [];
        
        for (let segment of segments) {
          const trimmed = segment.trim();
          if (trimmed.length > 2) { // Almeno "| |"
            lines.push(trimmed);
          }
        }
        
        // Step 2: Se non funziona, prova approccio alternativo
        if (lines.length < 3) {
          // Cerca pattern più specifici per header, separator, data
          const headerMatch = match.match(/\|\s*[A-Za-z][^|]*(?:\|[^|]*)*\|/);
          const separatorMatch = match.match(/\|[\s\-:|]+(?:\|[\s\-:|]*)*\|/);
          
          if (headerMatch && separatorMatch) {
            lines = [headerMatch[0]];
            lines.push(separatorMatch[0]);
            
            // Trova tutte le righe dati dopo il separator
            const afterSeparator = match.substring(match.indexOf(separatorMatch[0]) + separatorMatch[0].length);
            const dataRows = afterSeparator.match(/\|[^|]*(?:\|[^|]*)*\|/g) || [];
            lines.push(...dataRows);
          }
        }
        
        // Step 3: Fallback finale - split manuale intelligente
        if (lines.length < 3) {
          // Cerca pattern: qualsiasi cosa tra | e |, ripetuta
          const manualSplit = [];
          let current = '';
          let pipeCount = 0;
          
          for (let char of match) {
            current += char;
            if (char === '|') {
              pipeCount++;
              // Se abbiamo almeno 3 pipe, potrebbe essere una riga completa
              if (pipeCount >= 3 && current.trim().endsWith('|')) {
                manualSplit.push(current.trim());
                current = '';
                pipeCount = 0;
              }
            }
          }
          
          if (manualSplit.length >= 3) {
            lines = manualSplit;
          }
        }
      }
      
      if (!lines || lines.length < 3) return match; // Almeno header, separator e una riga
      
      // Trova la riga separator (quella con solo -, |, :, spazi)
      let separatorIndex = -1;
      for (let i = 0; i < lines.length; i++) {
        if (/^\|[\s\-:|]+\|$/.test(lines[i].trim())) {
          separatorIndex = i;
          break;
        }
      }
      
      if (separatorIndex === -1) return match; // Nessun separator trovato
      
      // Rimuovi la riga separator
      const dataLines = lines.filter((line, index) => index !== separatorIndex);
      
      // Parse delle righe
      const rows = dataLines.map(line => {
        // Rimuovi | iniziale e finale, poi split per |
        const cells = line.replace(/^\||\|$/g, '').split('|').map(cell => cell.trim());
        return cells;
      });
      
      if (rows.length === 0) return match;
      
      // Crea HTML della tabella
      let tableHtml = '<div class="chatbot-table-container" role="region">';
      tableHtml += '<table class="chatbot-table" role="table">';
      
      // Header
      if (rows.length > 0) {
        tableHtml += '<thead><tr>';
        rows[0].forEach(cell => {
          tableHtml += `<th class="chatbot-table-header" scope="col">${this.escapeHtml(cell)}</th>`;
        });
        tableHtml += '</tr></thead>';
      }
      
      // Body
      if (rows.length > 1) {
        tableHtml += '<tbody>';
        for (let i = 1; i < rows.length; i++) {
          tableHtml += '<tr>';
          rows[i].forEach(cell => {
            tableHtml += `<td class="chatbot-table-cell">${this.escapeHtml(cell)}</td>`;
          });
          tableHtml += '</tr>';
        }
        tableHtml += '</tbody>';
      }
      
      tableHtml += '</table></div>';
      
      return tableHtml;
    }

    static parseTableManually(html) {
      console.log('🔍 parseTableManually: Attempting manual table parsing');
      
      // Cerca pattern di tabelle compatte: testo con molti |
      // Esempio: "| A | B | C | |---| | D | E | F | | G | H | I |"
      
      // Trova tutte le sezioni che contengono pipe
      const segments = html.split(/(?=\|[^|]*\|[^|]*\|)/);
      
      for (let segment of segments) {
        const pipeCount = (segment.match(/\|/g) || []).length;
        
        if (pipeCount >= 12) { // Potenziale tabella
          console.log('🔍 Found potential table segment:', segment.substring(0, 100) + '...');
          
          // Prova a estrarre righe dalla sequenza di pipe
          const rows = this.extractRowsFromPipeSequence(segment);
          
          if (rows.length >= 3) { // Header + separator + almeno 1 riga dati
            console.log('🔍 Successfully extracted', rows.length, 'rows');
            
            // Costruisci HTML tabella
            const tableHtml = this.buildTableFromRows(rows);
            
            // Sostituisci nel testo originale
            html = html.replace(segment, tableHtml);
            break; // Una tabella alla volta
          }
        }
      }
      
      return html;
    }

    static extractRowsFromPipeSequence(text) {
      const rows = [];
      
      // Pattern per identificare potenziali righe
      // Cerca sequenze di | contenuto | contenuto | contenuto |
      const potentialRows = text.match(/\|[^|]*\|[^|]*\|[^|]*\|[^|]*/g) || [];
      
      for (let row of potentialRows) {
        // Pulisci e verifica se è una riga valida
        const cleaned = row.trim();
        if (cleaned.startsWith('|') && cleaned.includes('|')) {
          
          // Controlla se è una riga separatore (solo -, |, :, spazi)
          if (/^\|[\s\-:|]+\|/.test(cleaned)) {
            rows.push({ type: 'separator', content: cleaned });
          } else {
            // Riga normale
            const cells = cleaned.split('|').map(cell => cell.trim()).filter(cell => cell.length > 0);
            if (cells.length >= 2) { // Almeno 2 colonne
              rows.push({ type: 'data', content: cleaned, cells });
            }
          }
        }
      }
      
      return rows;
    }

    static buildTableFromRows(rows) {
      if (rows.length < 2) return '';
      
      let tableHtml = '<div class="chatbot-table-container" role="region">';
      tableHtml += '<table class="chatbot-table" role="table">';
      
      let headerProcessed = false;
      let inBody = false;
      
      for (let row of rows) {
        if (row.type === 'separator') {
          // Inizia il body dopo il separator
          if (!inBody && headerProcessed) {
            tableHtml += '<tbody>';
            inBody = true;
          }
          continue;
        }
        
        if (row.type === 'data' && row.cells) {
          if (!headerProcessed) {
            // Prima riga = header
            tableHtml += '<thead><tr>';
            for (let cell of row.cells) {
              tableHtml += `<th class="chatbot-table-header" scope="col">${this.escapeHtml(cell)}</th>`;
            }
            tableHtml += '</tr></thead>';
            headerProcessed = true;
          } else {
            // Righe dati
            if (!inBody) {
              tableHtml += '<tbody>';
              inBody = true;
            }
            tableHtml += '<tr>';
            for (let cell of row.cells) {
              tableHtml += `<td class="chatbot-table-cell">${this.escapeHtml(cell)}</td>`;
            }
            tableHtml += '</tr>';
          }
        }
      }
      
      if (inBody) {
        tableHtml += '</tbody>';
      }
      tableHtml += '</table></div>';
      
      console.log('🔍 Built table HTML:', tableHtml.substring(0, 200) + '...');
      return tableHtml;
    }

    static escapeHtml(text) {
      // Escape HTML per sicurezza
      return text.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
    }
  }

  // =================================================================
  // 🎨 UI MANAGER
  // =================================================================

  class ChatbotUI {
    constructor(state, eventEmitter, options = {}) {
      this.state = state;
      this.events = eventEmitter;
      this.options = options;
      this.elements = {};
      this.templates = {};
      this.isInitialized = false;
      
      // Don't init in constructor - wait for DOM elements to be ready
    }

    init() {
      this.cacheElements();
      this.cacheTemplates();
      this.setupEventListeners();
      this.setupAccessibility();
      this.markAsLoaded();
      
      // Update toggle button with conversation indicator
      this.updateToggleButton();
      
      this.isInitialized = true;
    }

    cacheElements() {
      console.log('[ChatbotUI] Caching elements...');
      
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
        status: document.getElementById('chatbot-status'),
        // 🎯 Agent Console elements
        handoffBtn: document.getElementById('chatbot-handoff-btn'),
        handoffStatus: document.getElementById('chatbot-handoff-status'),
        handoffIndicator: document.getElementById('chatbot-handoff-indicator'),
        handoffText: document.getElementById('chatbot-handoff-text')
      };
      
      // Debug log for missing elements
      Object.keys(this.elements).forEach(key => {
        if (!this.elements[key]) {
          console.warn(`[ChatbotUI] Element not found: ${key} (#chatbot-${key.replace(/([A-Z])/g, '-$1').toLowerCase()})`);
        }
      });
      
      console.log('[ChatbotUI] Elements cached:', Object.keys(this.elements).filter(k => this.elements[k]).length, 'found');
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

      // 🎯 Agent Console handoff button
      this.elements.handoffBtn?.addEventListener('click', () => this.handleHandoffRequest());

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
      
      // 🔄 RESTORE CONVERSATION: Load and display stored messages
      this.restoreConversation();
      
      // Show conversation controls if there's stored conversation
      this.updateConversationControls();
      
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

    // 🔧 CONFIGURATION APPLICATION
    applyConfiguration() {
      console.log('🔧 Applying widget configuration...', this.options);
      
      // Apply layout configuration
      this.applyLayoutConfiguration();
      
      // Apply behavior configuration  
      this.applyBehaviorConfiguration();
      
      // Apply branding configuration
      this.applyBrandingConfiguration();
      
      console.log('✅ Widget configuration applied');
    }
    
    applyLayoutConfiguration() {
      if (!this.options.layout) return;
      
      const widget = document.getElementById('chatbot-widget');
      const toggleBtn = document.getElementById('chatbot-toggle-btn');
      
      if (widget && this.options.layout.widget) {
        const { width, height, borderRadius } = this.options.layout.widget;
        widget.style.setProperty('--chatbot-widget-width', width);
        widget.style.setProperty('--chatbot-widget-height', height);
        widget.style.setProperty('--chatbot-widget-border-radius', borderRadius);
      }
      
      if (toggleBtn && this.options.layout.button) {
        const { size } = this.options.layout.button;
        toggleBtn.style.setProperty('--chatbot-button-size', size);
      }
    }
    
    applyBehaviorConfiguration() {
      if (!this.options.behavior) return;
      
      const widget = document.getElementById('chatbot-widget');
      if (!widget) return;
      
      const { showHeader, showAvatar, showCloseButton, enableAnimations, enableDarkMode } = this.options.behavior;
      
      // Apply behavior classes
      widget.classList.toggle('hide-header', !showHeader);
      widget.classList.toggle('hide-avatar', !showAvatar);
      widget.classList.toggle('hide-close-button', !showCloseButton);
      widget.classList.toggle('disable-animations', !enableAnimations);
      widget.classList.toggle('dark-mode', enableDarkMode);
    }
    
    applyBrandingConfiguration() {
      if (!this.options.branding) return;
      
      const { fontFamily, customColors, logoUrl } = this.options.branding;
      
      // Apply font family
      if (fontFamily) {
        document.documentElement.style.setProperty('--chatbot-font-family', fontFamily);
      }
      
      // Apply custom colors
      if (customColors && customColors.primary) {
        Object.entries(customColors.primary).forEach(([shade, color]) => {
          document.documentElement.style.setProperty(`--chatbot-primary-${shade}`, color);
        });
      }
      
      // Apply logo
      if (logoUrl) {
        const logo = document.querySelector('.chatbot-header-logo');
        if (logo) {
          logo.src = logoUrl;
          logo.style.display = 'block';
        }
      }
    }

    // 🔄 CONVERSATION RESTORATION & MANAGEMENT
    restoreConversation() {      
      console.log('🔄 Checking conversation restoration...', {
        hasStored: this.state.hasStoredConversation(),
        conversationLength: this.state.conversation.length,
        messageCount: this.state.conversationMetadata?.messageCount || 0
      });
      
      if (!this.state.hasStoredConversation()) {
        console.log('🔄 No stored conversation, showing welcome message');
        this.showWelcomeMessage();
        return;
      }

      console.log('🔄 Restoring conversation...', this.state.conversation.length, 'messages');
      
      // Clear current messages
      if (this.elements.messages) {
        this.elements.messages.innerHTML = '';
      }
      
      // Show restoration indicator
      this.showConversationRestoreIndicator();
      
      // Restore messages with a small delay for better UX
      setTimeout(() => {
        this.state.conversation.forEach((message) => {
          if (message.role === 'user') {
            this.displayUserMessage(message.content, message.timestamp);
          } else if (message.role === 'assistant') {
            this.displayBotMessage(message.content, message.citations || [], message.timestamp);
          }
        });
        
        // Scroll to bottom and remove restore indicator
        setTimeout(() => {
          this.scrollToBottom();
          this.hideConversationRestoreIndicator();
          console.log('✅ Conversation restored');
        }, 100);
        
      }, 300);
    }

    showWelcomeMessage() {
      // Only show welcome message if no stored conversation exists
      if (this.state.hasStoredConversation()) {
        console.log('[ChatbotUI] Stored conversation exists, skipping welcome message');
        return;
      }
      
      const welcomeMessage = this.options?.welcomeMessage || 'Ciao! Come posso aiutarti?';
      console.log('[ChatbotUI] Showing welcome message:', welcomeMessage);
      
      if (welcomeMessage) {
        setTimeout(() => {
          this.addBotMessage(welcomeMessage, [], true); // true = isWelcomeMessage
        }, 500);
      }
    }

    showConversationRestoreIndicator() {
      const age = this.state.getConversationAge();
      const messageCount = this.state.conversationMetadata.messageCount;
      
      const indicator = document.createElement('div');
      indicator.className = 'chatbot-restore-indicator';
      indicator.innerHTML = `
        <div class="chatbot-restore-content">
          <div class="chatbot-restore-icon">🔄</div>
          <div class="chatbot-restore-text">
            Ripristinando conversazione<br>
            <small>${messageCount} messaggi • ${age}</small>
          </div>
        </div>
      `;
      
      if (this.elements.messages) {
        this.elements.messages.appendChild(indicator);
      }
    }

    hideConversationRestoreIndicator() {
      const indicator = document.querySelector('.chatbot-restore-indicator');
      if (indicator) {
        indicator.remove();
      }
    }

    updateConversationControls() {
      // Update widget header with conversation info and controls
      this.updateWidgetHeader();
      
      // Update toggle button with indicator
      this.updateToggleButton();
    }

    updateWidgetHeader() {
      const header = this.elements.widget?.querySelector('.chatbot-header');
      if (!header) return;
      
      // Remove existing conversation controls
      const existingControls = header.querySelector('.chatbot-conversation-controls');
      if (existingControls) {
        existingControls.remove();
      }
      
      if (this.state.hasStoredConversation()) {
        const age = this.state.getConversationAge();
        const messageCount = this.state.conversationMetadata.messageCount;
        
        const controls = document.createElement('div');
        controls.className = 'chatbot-conversation-controls';
        controls.innerHTML = `
          <div class="chatbot-conversation-info">
            <span class="chatbot-conversation-indicator">💬</span>
            <span class="chatbot-conversation-meta">${messageCount} messaggi • ${age}</span>
          </div>
          <button type="button" class="chatbot-new-conversation-btn" title="Inizia nuova conversazione">
            <span class="chatbot-btn-icon">🆕</span>
            <span class="chatbot-btn-text">Nuova</span>
          </button>
        `;
        
        // Add event listener for new conversation button
        const newConvBtn = controls.querySelector('.chatbot-new-conversation-btn');
        newConvBtn?.addEventListener('click', () => this.startNewConversation());
        
        header.appendChild(controls);
      }
    }

    updateToggleButton() {
      if (!this.elements.toggleBtn) {
        return;
      }
      
      // Remove existing indicator
      const existingIndicator = this.elements.toggleBtn.querySelector('.chatbot-conversation-badge');
      if (existingIndicator) {
        existingIndicator.remove();
      }
      
      if (this.state.hasStoredConversation()) {
        const badge = document.createElement('span');
        badge.className = 'chatbot-conversation-badge';
        badge.textContent = this.state.conversationMetadata.messageCount;
        badge.title = `${this.state.conversationMetadata.messageCount} messaggi salvati`;
        this.elements.toggleBtn.appendChild(badge);
      }
    }

    startNewConversation() {
      if (confirm('Vuoi iniziare una nuova conversazione? La conversazione attuale verrà salvata.')) {
        console.log('🆕 Starting new conversation');
        
        // Clear current conversation
        this.state.reset();
        
        // Clear UI
        if (this.elements.messages) {
          this.elements.messages.innerHTML = '';
        }
        
        // Update controls
        this.updateConversationControls();
        
        // Show welcome message
        this.showWelcomeMessage();
        
        // Analytics
        if (window.chatbotAnalytics) {
          window.chatbotAnalytics.track('new_conversation_started');
        }
      }
    }

    // Enhanced message display methods with timestamps
    displayUserMessage(content, timestamp = null) {
      const messageEl = this.createMessageElement('user', content);
      if (!messageEl) return;
      
      // Add timestamp if provided
      if (timestamp) {
        const timeEl = messageEl.querySelector('time');
        if (timeEl) {
          const date = new Date(timestamp);
          timeEl.textContent = date.toLocaleTimeString('it-IT', { 
            hour: '2-digit', 
            minute: '2-digit' 
          });
          timeEl.dateTime = date.toISOString();
        }
      }
      
      this.appendMessage(messageEl);
      return messageEl;
    }

    displayBotMessage(content, citations = [], timestamp = null) {
      const messageEl = this.createMessageElement('bot', content, citations);
      if (!messageEl) return;
      
      // Add timestamp if provided
      if (timestamp) {
        const timeEl = messageEl.querySelector('time');
        if (timeEl) {
          const date = new Date(timestamp);
          timeEl.textContent = date.toLocaleTimeString('it-IT', { 
            hour: '2-digit', 
            minute: '2-digit' 
          });
          timeEl.dateTime = date.toISOString();
        }
      }
      
      this.appendMessage(messageEl);
      
      // Setup citation handlers
      if (window.chatbotWidget?.citations && citations && citations.length > 0) {
        this.setupCitationHandlers(messageEl);
      }
      
      return messageEl;
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

    addBotMessage(content, citations = [], isWelcomeMessage = false) {
      console.log('🤖 addBotMessage called with:', content.substring(0, 50) + '...', 'isWelcome:', isWelcomeMessage);
      
      const messageEl = this.createMessageElement('bot', content, citations, isWelcomeMessage);
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

    createMessageElement(role, content, citations = [], isWelcomeMessage = false) {
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
        // Debug: log del contenuto prima del parsing
        console.log('🔍 Content before markdown parsing:', content.substring(0, 200) + '...');
        console.log('🔍 Content contains table:', content.includes('|'));
        console.log('🔍 Content contains link:', content.includes('['));
        
        // Parse markdown e applica al contenuto
        const parsedContent = MarkdownParser.parse(content);
        
        // Debug: log del contenuto dopo il parsing
        console.log('🔍 Content after markdown parsing:', parsedContent.substring(0, 200) + '...');
        console.log('🔍 Parsed content contains <table>:', parsedContent.includes('<table>'));
        console.log('🔍 Parsed content contains <a href>:', parsedContent.includes('<a href>'));
        
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

      // 👍👎 Aggiungi sistema di feedback SOLO per risposte effettive, NON per messaggi di benvenuto
      if (role === 'bot' && !isWelcomeMessage) {
        console.log('📝 Adding feedback buttons for bot response (not welcome message)');
        this.addFeedbackButtons(messageEl, content);
      } else if (role === 'bot' && isWelcomeMessage) {
        console.log('📝 Skipping feedback buttons for welcome message');
      }

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
                ${citations.map((citation, index) => {
                  const href = citation.page_url || citation.document_source_url || citation.url || '#';
                  const label = citation.document_title || citation.title || `Fonte ${index + 1}`;
                  const title = citation.description || label || 'Apri documento';
                  return `
                  <a href="${href}" target="_blank" rel="noopener noreferrer" 
                     class="chatbot-citation-simple" title="${title}">
                    ${index + 1}. ${label}
                  </a>
                `;
                }).join('')}
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
        citationEl.href = citation.page_url || citation.document_source_url || citation.url || '#';
        citationEl.target = '_blank';
        citationEl.rel = 'noopener noreferrer';
        citationEl.role = 'listitem';
        const label = citation.document_title || citation.title || `Fonte ${index + 1}`;
        citationEl.textContent = `${index + 1}. ${label}`;
        citationEl.title = citation.description || label || 'Apri documento';
        
        citationsList.appendChild(citationEl);
      });
    }

    /**
     * Aggiunge i pulsanti di feedback (faccine) sotto un messaggio bot
     */
    addFeedbackButtons(messageEl, botResponse) {
      if (!messageEl) return;

      // Genera un ID unico per questo messaggio
      const messageId = 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      messageEl.setAttribute('data-message-id', messageId);

      // HTML per i pulsanti di feedback
      const feedbackHtml = `
        <div class="chatbot-feedback" data-message-id="${messageId}">
          <div class="chatbot-feedback-title">Questa risposta ti è stata utile?</div>
          <div class="chatbot-feedback-buttons">
            <button class="chatbot-feedback-btn" data-rating="negative" title="Non utile">
              <span class="feedback-emoji">😡</span>
              <span class="feedback-text">No</span>
            </button>
            <button class="chatbot-feedback-btn" data-rating="neutral" title="Così così">
              <span class="feedback-emoji">😐</span>
              <span class="feedback-text">Così così</span>
            </button>
            <button class="chatbot-feedback-btn" data-rating="positive" title="Utile">
              <span class="feedback-emoji">😊</span>
              <span class="feedback-text">Sì</span>
            </button>
          </div>
          <div class="chatbot-feedback-thanks" style="display: none;">
            <span class="feedback-thanks-text">Grazie per il feedback!</span>
          </div>
        </div>
      `;

      // Trova il bubble del messaggio e aggiungi i feedback dopo
      const bubble = messageEl.querySelector('.chatbot-message-bubble');
      if (bubble) {
        bubble.insertAdjacentHTML('afterend', feedbackHtml);
        
        // Aggiungi event listeners per i pulsanti
        this.setupFeedbackHandlers(messageEl, messageId, botResponse);
      }
    }

    /**
     * Configura gli event handlers per i pulsanti di feedback
     */
    setupFeedbackHandlers(messageEl, messageId, botResponse) {
      const feedbackContainer = messageEl.querySelector('.chatbot-feedback');
      if (!feedbackContainer) return;

      const buttons = feedbackContainer.querySelectorAll('.chatbot-feedback-btn');
      const thanksContainer = feedbackContainer.querySelector('.chatbot-feedback-thanks');
      const buttonsContainer = feedbackContainer.querySelector('.chatbot-feedback-buttons');

      buttons.forEach(button => {
        button.addEventListener('click', async (e) => {
          e.preventDefault();
          
          const rating = button.getAttribute('data-rating');
          if (!rating) return;

          // Disabilita tutti i pulsanti per evitare click multipli
          buttons.forEach(btn => btn.disabled = true);

          try {
            // Ottieni il contenuto del messaggio bot dal DOM, escludendo le icone di feedback
            const messageContentEl = messageEl.querySelector('.chatbot-message-content, .chatbot-message-bubble');
            let actualBotResponse = botResponse; // fallback al parametro originale
            
            if (messageContentEl) {
              // Clona l'elemento per non modificare il DOM originale
              const tempEl = messageContentEl.cloneNode(true);
              
              // Rimuovi tutti gli elementi di feedback (pulsanti e contenitori)
              const feedbackElements = tempEl.querySelectorAll('.chatbot-feedback, .chatbot-feedback-container, .chatbot-feedback-buttons, .chatbot-feedback-thanks');
              feedbackElements.forEach(el => el.remove());
              
              // Ottieni solo il testo pulito
              actualBotResponse = tempEl.textContent.trim();
              
              // Rimuovi pattern di testo delle icone di feedback che potrebbero rimanere
              actualBotResponse = actualBotResponse
                .replace(/Questa risposta ti è stata utile\?/g, '')
                .replace(/😡\s*No/g, '')
                .replace(/😐\s*Così così/g, '')
                .replace(/😊\s*Sì/g, '')
                .replace(/Grazie per il feedback!/g, '')
                .replace(/\s+/g, ' ') // normalizza spazi multipli
                .trim();
            }
            
            // Log per debug: mostra contenuto pulito
            console.log('📝 Bot response pulita per feedback:', actualBotResponse.substring(0, 100) + '...');
            
            // Invia il feedback all'API
            await this.submitFeedback(messageId, actualBotResponse, rating);
            
            // Mostra messaggio di ringraziamento
            if (buttonsContainer && thanksContainer) {
              buttonsContainer.style.display = 'none';
              thanksContainer.style.display = 'block';
              
              // Aggiunge classe per evidenziare il rating selezionato
              button.classList.add('selected', `rating-${rating}`);
              thanksContainer.appendChild(button.cloneNode(true));
            }

          } catch (error) {
            console.error('Errore nell\'invio del feedback:', error);
            
            // Riabilita i pulsanti in caso di errore
            buttons.forEach(btn => btn.disabled = false);
            
            // Mostra messaggio di errore
            if (thanksContainer) {
              thanksContainer.innerHTML = '<span class="feedback-error">Errore nell\'invio del feedback. Riprova.</span>';
              thanksContainer.style.display = 'block';
              
              // Nascondi l'errore dopo 3 secondi
              setTimeout(() => {
                thanksContainer.style.display = 'none';
              }, 3000);
            }
          }
        });
      });
    }

    /**
     * Invia il feedback all'API del backend
     */
    async submitFeedback(messageId, botResponse, rating) {
      // 🔧 Controllo configurazione obbligatoria
      if (!window.CHATBOT_CONFIG || !window.CHATBOT_CONFIG.apiUrl || !window.CHATBOT_CONFIG.apiKey) {
        console.error('❌ CHATBOT_CONFIG non configurato correttamente:', {
          config_defined: !!window.CHATBOT_CONFIG,
          api_url: window.CHATBOT_CONFIG?.apiUrl,
          api_key: window.CHATBOT_CONFIG?.apiKey ? '[PRESENTE]' : '[MANCANTE]'
        });
        throw new Error('Configurazione widget non valida. Verifica apiUrl e apiKey.');
      }
      
      // Ottieni la domanda dell'utente dall'ultimo messaggio user
      const userQuestion = this.getLastUserQuestion();
      
      // Validazione dati richiesti
      if (!botResponse || botResponse.trim().length === 0) {
        console.error('❌ botResponse vuoto o mancante:', botResponse);
        throw new Error('Risposta del bot mancante per il feedback');
      }

      // Migliore gestione della user_question mancante
      const fallbackQuestion = userQuestion || `[Messaggio inviato da: ${window.location.href}]`;
      
      const feedbackData = {
        user_question: fallbackQuestion,
        bot_response: botResponse.trim(),
        rating: rating,
        message_id: messageId,
        session_id: this.getSessionId(),
        conversation_id: this.getConversationId(),
        page_url: window.location.href,
        response_metadata: {
          timestamp: new Date().toISOString(),
          user_agent: navigator.userAgent,
          widget_version: window.CHATBOT_CONFIG?.version || '1.0',
          question_source: userQuestion ? 'conversation' : 'fallback'
        }
      };

      console.log('📝 Inviando feedback:', { rating, messageId, userQuestion: userQuestion?.substring(0, 50), botResponse: botResponse?.substring(0, 100) });

      const response = await fetch(`${window.CHATBOT_CONFIG.apiUrl}/feedback`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${window.CHATBOT_CONFIG.apiKey}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify(feedbackData)
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      
      if (!result.success) {
        throw new Error(result.message || 'Errore nel salvare il feedback');
      }

      console.log('✅ Feedback inviato con successo:', result);
      return result;
    }

    /**
     * Ottiene l'ultima domanda dell'utente dalla cronologia
     */
    getLastUserQuestion() {
      // Prima prova a recuperare dalla memoria locale delle conversazioni
      const storedConversation = this.state?.conversation || [];
      if (storedConversation && storedConversation.length > 0) {
        // Trova l'ultimo messaggio utente nella conversazione memorizzata
        for (let i = storedConversation.length - 1; i >= 0; i--) {
          const msg = storedConversation[i];
          if (msg.role === 'user' && msg.content && msg.content.trim().length > 0) {
            console.log('📝 User question trovata dalla memoria:', msg.content.substring(0, 50));
            return msg.content.trim();
          }
        }
      }
      
      // Fallback: cerca nel DOM
      const messages = this.elements.messagesContainer?.querySelectorAll('.chatbot-message.user');
      if (messages && messages.length > 0) {
        const lastUserMessage = messages[messages.length - 1];
        const bubble = lastUserMessage.querySelector('.chatbot-message-bubble, .chatbot-message-content');
        const content = bubble?.textContent?.trim();
        if (content && content.length > 0) {
          console.log('📝 User question trovata dal DOM:', content.substring(0, 50));
          return content;
        }
      }
      
      console.warn('📝 Nessuna domanda utente trovata');
      return null;
    }

    /**
     * Ottiene l'ID della sessione corrente
     */
    getSessionId() {
      return window.CHATBOT_CONFIG?.sessionId || 'session_' + Date.now();
    }

    /**
     * Ottiene l'ID della conversazione corrente
     */
    getConversationId() {
      return window.CHATBOT_CONFIG?.conversationId || 'conv_' + Date.now();
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
      
      const messageCount = this.elements.messages?.children.length || 0;
      console.log('📝 appendMessage: Adding message to container with', messageCount, 'existing messages');
      
      this.elements.messages?.appendChild(messageEl);
      
      const newCount = this.elements.messages?.children.length || 0;
      console.log('📝 appendMessage: Container now has', newCount, 'messages');
      
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

    // 🎯 Agent Console Handoff Methods
    async handleHandoffRequest() {
      try {
        // Emit event to main widget to request handoff
        this.events.emit('handoff-requested', {
          reason: 'user_request',
          priority: 'normal'
        });
        
        // Show feedback to user (inline safe)
        try {
          if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
            this.ui.elements.handoffText.textContent = '🤝 Richiesta di assistenza in corso...';
            this.ui.elements.handoffStatus.className = 'chatbot-handoff-status pending';
            this.ui.elements.handoffStatus.style.display = 'block';
          }
        } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
        
        console.log('🎯 Handoff requested by user');
      } catch (error) {
        console.error('🚨 Error requesting handoff:', error);
        try {
          if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
            this.ui.elements.handoffText.textContent = '❌ Errore nella richiesta di assistenza';
            this.ui.elements.handoffStatus.className = 'chatbot-handoff-status error';
            this.ui.elements.handoffStatus.style.display = 'block';
          }
        } catch (e) { console.warn('⚠️ Cannot show error status:', e.message); }
      }
    }

    showHandoffStatus(status, message) {
      if (!this.ui || !this.ui.elements || !this.ui.elements.handoffStatus || !this.ui.elements.handoffText) return;
      
      this.ui.elements.handoffText.textContent = message;
      
      // Update indicator based on status
      switch (status) {
        case 'pending':
          if (this.ui.elements.handoffIndicator) this.ui.elements.handoffIndicator.textContent = '🤝';
          this.ui.elements.handoffStatus.className = 'chatbot-handoff-status pending';
          break;
        case 'accepted':
          if (this.ui.elements.handoffIndicator) this.ui.elements.handoffIndicator.textContent = '👨‍💼';
          this.ui.elements.handoffStatus.className = 'chatbot-handoff-status accepted';
          break;
        case 'error':
          if (this.ui.elements.handoffIndicator) this.ui.elements.handoffIndicator.textContent = '❌';
          this.ui.elements.handoffStatus.className = 'chatbot-handoff-status error';
          break;
        default:
          this.ui.elements.handoffStatus.className = 'chatbot-handoff-status';
      }
      
      // Show/hide status bar
      if (status === 'hidden') {
        this.ui.elements.handoffStatus.style.display = 'none';
      } else {
        this.ui.elements.handoffStatus.style.display = 'block';
      }
    }

    // 🎯 Agent Console: Hide handoff status bar
    hideHandoffStatus() {
      if (this.ui && this.ui.elements && this.ui.elements.handoffStatus) {
        this.ui.elements.handoffStatus.style.display = 'none';
      }
    }

    // 🎯 Agent Console: Enable handoff button
    enableHandoffButton() {
      if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
        this.ui.elements.handoffBtn.disabled = false;
        // Non impostare textContent, l'icona viene gestita da applyOperatorConfiguration
      }
    }

    // 🎯 Agent Console: Disable handoff button
    disableHandoffButton() {
      if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
        this.ui.elements.handoffBtn.disabled = true;
      }
    }

    // 🛡️ Safe wrapper methods that never throw errors
    safeEnableHandoffButton() {
      try {
        this.enableHandoffButton();
      } catch (error) {
        console.warn('⚠️ Cannot enable handoff button:', error.message);
      }
    }

    safeDisableHandoffButton() {
      try {
        this.disableHandoffButton();
      } catch (error) {
        console.warn('⚠️ Cannot disable handoff button:', error.message);
      }
    }

    safeShowHandoffStatus(status, message) {
      try {
        this.showHandoffStatus(status, message);
      } catch (error) {
        console.warn('⚠️ Cannot show handoff status:', error.message);
      }
    }

    safeHideHandoffStatus() {
      try {
        this.hideHandoffStatus();
      } catch (error) {
        console.warn('⚠️ Cannot hide handoff status:', error.message);
      }
    }

    updateHandoffButton(handoffStatus) {
      if (!this.elements.handoffBtn) return;
      
      switch (handoffStatus) {
        case 'bot_only':
          this.elements.handoffBtn.style.display = 'block';
          this.elements.handoffBtn.disabled = false;
          this.elements.handoffBtn.title = 'Parla con un operatore';
          break;
        case 'handoff_pending':
          this.elements.handoffBtn.disabled = true;
          this.elements.handoffBtn.title = 'Richiesta in corso...';
          break;
        case 'operator_active':
          this.elements.handoffBtn.style.display = 'none';
          break;
        default:
          this.elements.handoffBtn.style.display = 'block';
          this.elements.handoffBtn.disabled = false;
      }
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
        // Adapt event format for public endpoint
        const publicEvent = {
          event: event.event_type,
          properties: {
            tenant_id: this.tenantId,
            session_id: event.session_id,
            page_url: window.location.href,
            user_agent: navigator.userAgent,
            timestamp: Date.now(),
            ...(event.event_data || {})
          }
        };

        // 🔍 DEBUG: Log dei dati inviati (solo in debug mode)
        if (window.chatbotDebug) {
          console.log('🔍 WIDGET DEBUG - Sending event:', {
            url: `${this.baseURL}${CONFIG.analyticsEndpoint}`,
            event: JSON.stringify(publicEvent, null, 2),
            tenantId: this.tenantId,
            sessionId: event.session_id,
            eventType: event.event_type,
            eventData: event.event_data
          });
        }

        const response = await fetch(`${this.baseURL}${CONFIG.analyticsEndpoint}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(publicEvent)
        });

        if (!response.ok) {
          // 🔍 DEBUG: Log dettagliato dell'errore (solo in debug mode)
          if (window.chatbotDebug) {
            const responseText = await response.text();
            console.error('🔍 WIDGET DEBUG - Analytics event failed:', {
              status: response.status,
              statusText: response.statusText,
              url: response.url,
              event: JSON.stringify(publicEvent, null, 2),
              responseBody: responseText
            });
          }
          
          // Only warn in development mode (when console is open or specific debug flag)
          if (window.location.hostname === 'localhost' || window.chatbotDebug) {
            console.warn('Analytics event failed:', response.status, response.statusText);
          }
        } else {
          // 🔍 DEBUG: Log success (solo in debug mode)
          if (window.chatbotDebug) {
            console.log('🔍 WIDGET DEBUG - Analytics event success:', {
              status: response.status,
              event: publicEvent
            });
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
  // 🎯 AGENT CONSOLE CONVERSATION TRACKER
  // =================================================================

  class ConversationTracker {
    constructor(options = {}) {
      this.baseURL = options.baseURL || '';
      this.apiKey = options.apiKey || '';
      this.tenantId = options.tenantId || '';
      this.widgetConfigId = options.widgetConfigId || 1;
      
      // Session management
      this.agentSessionId = null;
      this.handoffStatus = 'bot_only'; // bot_only, handoff_pending, operator_active
      this.operatorInfo = null;
      this.sessionStartFailed = false; // 🔧 FIX: Track if session creation failed to avoid repeated attempts
      
      // Event emitter for real-time updates
      this.events = new EventEmitter();
      
      console.log('🎯 ConversationTracker initialized', {
        tenantId: this.tenantId,
        widgetConfigId: this.widgetConfigId
      });
    }

    async startSession() {
      try {
        const response = await fetch(`${this.baseURL}${CONFIG.conversationEndpoint}/start`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            tenant_id: parseInt(this.tenantId),
            widget_config_id: parseInt(this.widgetConfigId),
            channel: 'widget',
            user_agent: navigator.userAgent,
            referrer_url: document.referrer || window.location.href
          })
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();
        this.agentSessionId = data.session.session_id;
        this.handoffStatus = data.session.handoff_status || 'bot_only';
        
        // Store session in localStorage for persistence
        localStorage.setItem(CONFIG.storagePrefix + CONFIG.agentSessionKey, this.agentSessionId);
        localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, this.handoffStatus);
        
        console.log('🎯 Agent session started:', this.agentSessionId);
        
        return {
          sessionId: this.agentSessionId,
          status: this.handoffStatus
        };
      } catch (error) {
        console.error('🚨 Failed to start agent session:', error);
        // 🔧 FIX: Don't create fallback ID - it will cause 422 errors when sending messages
        // The widget will still work for chatbot, just not for agent console tracking
        this.agentSessionId = null;
        return null;
      }
    }

    async sendMessage(content, senderType = 'user') {
      // 🔧 FIX: Only try to start session if we don't have one AND it's not already failed
      if (!this.agentSessionId && !this.sessionStartFailed) {
        console.warn('🚨 No agent session active, starting one...');
        const result = await this.startSession();
        if (!result || !result.sessionId) {
          console.warn('🚨 Agent session start failed, skipping agent console integration');
          this.sessionStartFailed = true;
          return; // Don't try to send to agent console
        }
      }

      // 🔧 FIX: Don't try to send if session creation failed
      if (!this.agentSessionId || this.sessionStartFailed) {
        console.log('🎯 Agent console disabled, skipping message send to agent console');
        return;
      }

      try {
        const response = await fetch(`${this.baseURL}${CONFIG.messageEndpoint}/send`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            session_id: this.agentSessionId,
            content: content,
            sender_type: senderType,
            content_type: 'text'
          })
        });

        if (response.ok) {
          const data = await response.json();
          console.log('🎯 Message sent to agent console:', data.message);
          return data.message;
        } else {
          console.warn('🚨 Failed to send message to agent console:', response.status);
        }
      } catch (error) {
        console.error('🚨 Error sending message to agent console:', error);
      }
      
      return null;
    }

    async requestHandoff(reason = 'user_request', priority = 'normal') {
      // 🎯 Se non c'è una sessione attiva, creala prima
      // 🔧 FIX: Don't attempt if session creation already failed
      if (!this.agentSessionId && !this.sessionStartFailed) {
        console.log('🎯 No active session, starting new session for handoff...');
        const result = await this.startSession();
        if (!result || !result.sessionId) {
          console.warn('🚨 Failed to start session for handoff');
          this.sessionStartFailed = true;
          return false;
        }
      }
      
      // 🔧 FIX: Can't request handoff if session creation failed
      if (this.sessionStartFailed) {
        console.warn('🚨 Cannot request handoff: Agent console session unavailable');
        return false;
      }

      try {
        const response = await fetch(`${this.baseURL}${CONFIG.conversationEndpoint}/handoff/request`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            session_id: this.agentSessionId,
            trigger_type: 'user_explicit',
            reason: reason,
            priority: priority
          })
        });

        if (response.ok) {
          const data = await response.json();
          this.handoffStatus = 'handoff_pending';
          localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, this.handoffStatus);
          
          this.events.emit(CONFIG.events.HANDOFF_REQUESTED, {
            handoffId: data.handoff_request.id,
            priority: priority,
            reason: reason
          });
          
          console.log('🎯 Handoff requested:', data.handoff_request);
          return data.handoff_request;
        }
      } catch (error) {
        console.error('🚨 Error requesting handoff:', error);
      }
      
      return false;
    }

    getSessionInfo() {
      return {
        agentSessionId: this.agentSessionId,
        handoffStatus: this.handoffStatus,
        operatorInfo: this.operatorInfo
      };
    }

    isHandoffActive() {
      return this.handoffStatus === 'operator_active';
    }

    isPendingHandoff() {
      return this.handoffStatus === 'handoff_pending';
    }

    // Restore session from localStorage
    restoreSession() {
      this.agentSessionId = localStorage.getItem(CONFIG.storagePrefix + CONFIG.agentSessionKey);
      this.handoffStatus = localStorage.getItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey) || 'bot_only';
      
      if (this.agentSessionId) {
        console.log('🎯 Restored agent session:', this.agentSessionId, 'Status:', this.handoffStatus);
      }
    }
  }

  // =================================================================
  // 🤖 MAIN CHATBOT CLASS
  // =================================================================

  class ChatbotWidget {
    constructor(options = {}) {
      console.log('🤖 ChatbotWidget initializing with CONFIG:', {
        version: CONFIG.version,
        analyticsEndpoint: CONFIG.analyticsEndpoint,
        requestTimeout: CONFIG.requestTimeout
      });
      
      // Expose globally for WebSocket access
      window.chatbotWidget = this;
      
      this.options = {
        // Core configuration
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
        
        // Layout defaults
        layout: {
          widget: {
            width: '400px',
            height: '600px',
            borderRadius: '12px'
          },
          button: {
            size: '60px'
          }
        },
        
        // Behavior defaults
        behavior: {
          showHeader: true,
          showAvatar: true,
          showCloseButton: true,
          enableAnimations: true,
          enableDarkMode: false
        },
        
        // Branding defaults
        branding: {
          logoUrl: null,
          faviconUrl: null,
          fontFamily: "'Inter', sans-serif",
          customColors: null
        },
        
        // Form handling
        formCheckCooldown: 5000, // 5 secondi tra check form
        maxFormChecks: 10, // Max 10 check per sessione
        
        // Merge with provided options (this will override defaults)
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
      
      // Apply widget configuration to DOM
      this.applyConfiguration();
      
      // Initialize form renderer if enabled and available
      this.formRenderer = null;
      if (this.options.enableDynamicForms && window.ChatbotFormRenderer) {
        this.formRenderer = new ChatbotFormRenderer(this);
        // Impostalo globalmente per onclick handlers
        window.chatbotFormRenderer = this.formRenderer;
      }
      this.events = new EventEmitter();
      this.ui = new ChatbotUI(this.state, this.events, this.options);
      this.api = new ChatbotAPI(this.options.apiKey, this.options.baseURL);
      this.analytics = new Analytics(this.options.apiKey, this.options.tenantId, this.options.baseURL);
      
      // 🎯 Agent Console integration
      this.conversationTracker = new ConversationTracker({
        baseURL: this.options.baseURL,
        apiKey: this.options.apiKey,
        tenantId: this.options.tenantId,
        widgetConfigId: this.options.widgetConfigId || 1
      });
      
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

    async loadThemeConfiguration() {
      console.log('🌐 [THEME] === INIZIO LOAD THEME CONFIGURATION ===');
      
      if (!this.options.tenantId) {
        console.warn('🌐 [THEME] ❌ No tenantId provided, skipping theme configuration load');
        return;
      }
      
      console.log('🌐 [THEME] tenantId:', this.options.tenantId);
      
      try {
        const url = `${this.options.baseURL}/api/v1/tenants/${this.options.tenantId}/widget-theme`;
        console.log('🌐 [THEME] Loading theme configuration from:', url);
        
        const response = await fetch(url);
        console.log('🌐 [THEME] Response status:', response.status);
        
        if (!response.ok) {
          throw new Error(`Failed to load theme configuration: ${response.status}`);
        }
        
        const themeConfig = await response.json();
        console.log('🌐 [THEME] Theme configuration loaded:', themeConfig);
        
        // Merge theme configuration into options
        if (themeConfig.operator) {
          this.options.operator = themeConfig.operator;
          console.log('🌐 [THEME] ✅ Operator configuration loaded:', this.options.operator);
          console.log('🌐 [THEME] Operator enabled:', this.options.operator.enabled);
          console.log('🌐 [THEME] Operator availability:', this.options.operator.availability);
        } else {
          console.log('🌐 [THEME] ⚠️ No operator configuration in theme');
        }
        
        // Merge other theme properties if needed
        if (themeConfig.layout) {
          this.options.layout = { ...this.options.layout, ...themeConfig.layout };
        }
        if (themeConfig.behavior) {
          this.options.behavior = { ...this.options.behavior, ...themeConfig.behavior };
        }
        if (themeConfig.branding) {
          this.options.branding = { ...this.options.branding, ...themeConfig.branding };
        }
        
        console.log('🌐 [THEME] === FINE LOAD THEME CONFIGURATION ===');
        
      } catch (error) {
        console.error('🌐 [THEME] ❌ Failed to load theme configuration:', error);
        // Continue without theme configuration
      }
    }

    async init() {
      this.setupEventHandlers();
      
      // 🎨 Initialize UI FIRST - so elements are available for configuration
      if (this.ui && !this.ui.isInitialized) {
        console.log('[ChatbotWidget] Initializing UI...');
        this.ui.init();
      }
      
      // 🔧 Load theme configuration from API
      await this.loadThemeConfiguration();
      
      // Apply configuration AFTER UI is initialized
      this.applyConfiguration();
      
      // 🎯 Initialize Agent Console session
      this.initializeAgentConsole();
      
      // Initialize handoff UI state
      setTimeout(() => {
        this.initializeHandoffUI();
        // 🎯 Ensure handoff button is enabled by default
        this.ensureHandoffButtonEnabled();
        // Avvia polling SOLO se già in stato di handoff
        const status = this.conversationTracker?.handoffStatus || localStorage.getItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey);
        if (status === 'handoff_requested' || status === 'handoff_active' || status === 'operator_active') {
          this.enablePollingFallback();
        }
      }, 500);
      
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

    async initializeAgentConsole() {
      try {
        // Restore previous session if available
        this.conversationTracker.restoreSession();
        
        // Start new session if needed
        if (!this.conversationTracker.agentSessionId) {
          await this.conversationTracker.startSession();
        }
        
        // Setup handoff event listeners
        this.conversationTracker.events.on(CONFIG.events.HANDOFF_REQUESTED, (data) => {
          this.handleHandoffAccepted(data);
        });
        
        console.log('🎯 Agent Console initialized:', this.conversationTracker.getSessionInfo());
        
        // 📡 Setup WebSocket listeners for real-time operator messages
        this.setupWebSocketListeners();
      } catch (error) {
        console.warn('🚨 Failed to initialize Agent Console:', error);
      }
    }

    setupEventHandlers() {
      this.events.on('send-message', (message) => this.sendMessage(message));
      this.events.on('retry-last-message', () => this.retryLastMessage());
      
      // 🎯 Agent Console handoff events
      this.events.on('handoff-requested', (data) => {
        this.handleHandoffFromUI(data).catch(error => {
          console.error('🚨 Error in handoff handler:', error);
        });
      });
      
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
      // Only apply configuration if UI is initialized
      if (!this.ui || !this.ui.elements || !this.ui.isInitialized) {
        console.log('[ChatbotWidget] UI not ready for configuration, skipping...');
        return;
      }
      
      console.log('[ChatbotWidget] Applying configuration...');
      
      // Apply tenant configuration
      if (this.options.tenantId) {
        this.ui.elements.widget?.setAttribute('data-tenant', this.options.tenantId);
      }
      
      if (this.options.theme) {
        this.ui.elements.widget?.setAttribute('data-theme', this.options.theme);
      }
      
      // Apply advanced configuration through UI
      if (this.ui && typeof this.ui.applyConfiguration === 'function') {
        this.ui.applyConfiguration();
      }
      
      // Apply operator configuration
      this.applyOperatorConfiguration();
    }

    applyOperatorConfiguration() {
      console.log('🔧 [OPERATOR] === INIZIO APPLY CONFIGURATION ===');
      console.log('🔧 [OPERATOR] this.options.operator:', this.options.operator);
      
      const handoffBtn = document.getElementById('chatbot-handoff-btn');
      const handoffIcon = document.getElementById('chatbot-handoff-icon');
      
      if (!handoffBtn || !handoffIcon) {
        console.log('🔧 [OPERATOR] ❌ Operator elements not found');
        return;
      }
      
      console.log('🔧 [OPERATOR] ✅ Elementi trovati');
      
      // Get operator configuration from options
      const operatorConfig = this.options.operator || {};
      const isEnabled = operatorConfig.enabled || false;
      
      console.log('🔧 [OPERATOR] operatorConfig:', operatorConfig);
      console.log('🔧 [OPERATOR] isEnabled:', isEnabled);
      
      if (!isEnabled) {
        console.log('🔧 [OPERATOR] ❌ Operatore NON abilitato -> nascondo pulsante');
        handoffBtn.style.display = 'none';
        return;
      }
      
      console.log('🔧 [OPERATOR] ✅ Operatore abilitato');
      
      // Show button
      handoffBtn.style.display = 'flex';
      console.log('🔧 [OPERATOR] Pulsante impostato su display: flex');
      
      // Set icon based on configuration
      const iconType = operatorConfig.button_icon || 'headphones';
      const iconSvg = this.getOperatorIcon(iconType);
      handoffIcon.innerHTML = iconSvg;
      console.log('🔧 [OPERATOR] Icona impostata:', iconType);
      
      // Set tooltip
      const buttonText = operatorConfig.button_text || 'Operatore';
      handoffBtn.title = `Parla con un ${buttonText.toLowerCase()}`;
      console.log('🔧 [OPERATOR] Tooltip impostato:', handoffBtn.title);
      
      // Check availability
      console.log('🔧 [OPERATOR] Chiamata checkOperatorAvailability()...');
      this.checkOperatorAvailability();
      
      console.log('🔧 [OPERATOR] === FINE APPLY CONFIGURATION ===');
    }
    
    getOperatorIcon(iconType) {
      const icons = {
        'headphones': `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 14v3a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"></path>
          <path d="M21 14v3a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2z"></path>
          <path d="M8 14v-4a4 4 0 0 1 8 0v4"></path>
        </svg>`,
        'user': `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>`,
        'phone': `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
        </svg>`,
        'message-circle': `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
        </svg>`,
        'life-buoy': `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"></circle>
          <circle cx="12" cy="12" r="4"></circle>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
        </svg>`
      };
      
      return icons[iconType] || icons['headphones'];
    }
    
    checkOperatorAvailability() {
      console.log('🔍 [OPERATOR] === INIZIO CHECK DISPONIBILITÀ ===');
      
      const operatorConfig = this.options.operator || {};
      console.log('🔍 [OPERATOR] operatorConfig:', operatorConfig);
      
      const availability = operatorConfig.availability || {};
      console.log('🔍 [OPERATOR] availability:', availability);
      console.log('🔍 [OPERATOR] availability keys:', Object.keys(availability));
      
      // Se non c'è nessuna configurazione di orari, l'operatore è sempre disponibile
      if (!availability || Object.keys(availability).length === 0) {
        console.log('🔍 [OPERATOR] ✅ Nessuna configurazione orari -> Sempre disponibile');
        this.setOperatorAvailable();
        return;
      }
      
      const now = new Date();
      const currentDay = now.toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
      const currentTime = now.toTimeString().slice(0, 5); // HH:MM format
      console.log('🔍 [OPERATOR] currentDay:', currentDay);
      console.log('🔍 [OPERATOR] currentTime:', currentTime);
      
      // Check if current day exists in availability
      const daySchedule = availability[currentDay];
      console.log('🔍 [OPERATOR] daySchedule for', currentDay, ':', daySchedule);
      
      if (!daySchedule) {
        // Se il giorno non è configurato ma ci sono altri giorni configurati, 
        // significa che questo giorno è chiuso
        console.log('🔍 [OPERATOR] ❌ Giorno NON configurato -> NON disponibile');
        this.setOperatorUnavailable();
        return;
      }
      
      // Check if day is enabled (converti a boolean perché può essere stringa "0" o "1")
      const isEnabled = daySchedule.enabled === true || daySchedule.enabled === 1 || daySchedule.enabled === '1';
      console.log('🔍 [OPERATOR] daySchedule.enabled (raw):', daySchedule.enabled, 'type:', typeof daySchedule.enabled);
      console.log('🔍 [OPERATOR] isEnabled (converted):', isEnabled);
      
      if (!isEnabled) {
        console.log('🔍 [OPERATOR] ❌ Giorno NON abilitato -> NON disponibile');
        this.setOperatorUnavailable();
        return;
      }
      
      // Check if current time is within any of the available slots
      const slots = daySchedule.slots || [];
      console.log('🔍 [OPERATOR] slots:', slots);
      
      // Se non ci sono slot per questo giorno, non è disponibile
      if (slots.length === 0) {
        console.log('🔍 [OPERATOR] ❌ Nessuno slot configurato -> NON disponibile');
        this.setOperatorUnavailable();
        return;
      }
      
      let isAvailable = false;
      
      for (const slot of slots) {
        const startTime = slot.start_time;
        const endTime = slot.end_time;
        
        console.log('🔍 [OPERATOR] Checking slot:', { startTime, endTime });
        
        // Skip empty slots
        if (!startTime || !endTime) {
          console.log('🔍 [OPERATOR] ⚠️ Slot vuoto, skip');
          continue;
        }
        
        console.log('🔍 [OPERATOR] Confronto:', currentTime, '>=', startTime, '&&', currentTime, '<=', endTime);
        if (currentTime >= startTime && currentTime <= endTime) {
          console.log('🔍 [OPERATOR] ✅ Slot valido trovato!');
          isAvailable = true;
          break;
        }
      }
      
      console.log('🔍 [OPERATOR] isAvailable finale:', isAvailable);
      
      if (isAvailable) {
        console.log('🔍 [OPERATOR] ✅ Operatore DISPONIBILE');
        this.setOperatorAvailable();
      } else {
        console.log('🔍 [OPERATOR] ❌ Operatore NON DISPONIBILE');
        this.setOperatorUnavailable();
      }
      
      console.log('🔍 [OPERATOR] === FINE CHECK DISPONIBILITÀ ===');
    }
    
    setOperatorAvailable() {
      const handoffBtn = document.getElementById('chatbot-handoff-btn');
      if (handoffBtn) {
        handoffBtn.disabled = false;
        handoffBtn.style.display = 'flex';
        handoffBtn.style.opacity = '1';
        handoffBtn.title = handoffBtn.title.replace(' (non disponibile)', '');
      }
    }
    
    setOperatorUnavailable() {
      const handoffBtn = document.getElementById('chatbot-handoff-btn');
      if (handoffBtn) {
        // Nasconde completamente il pulsante quando non disponibile
        handoffBtn.style.display = 'none';
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
      
      // 🎯 Track message in Agent Console
      if (this.conversationTracker) {
        this.conversationTracker.sendMessage(content, 'user');
      }
      
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
      
      // Send to API with session ID for handoff control
      const sessionId = this.conversationTracker?.agentSessionId || '';
      console.log('🔍 ChatbotWidget.sendMessage - passing session ID:', sessionId);
      
      // 🚀 STREAMING: Create message element on FIRST chunk, not before
      let messageElement = null;
      let contentDiv = null;
      let accumulatedContent = '';
      
      const response = await this.api.sendMessage(messages, {
        model: this.options.model,
        temperature: this.options.temperature,
        maxTokens: this.options.maxTokens,
        sessionId: sessionId,
        stream: false, // ❌ DISABLED: Streaming causes infinite loop, needs complete rewrite
      });
      
      const responseTime = performance.now() - startTime;
      
      // Add citations after streaming completes
      if (messageElement && response.citations && response.citations.length > 0) {
        const citationsDiv = messageElement.querySelector('.message-citations');
        if (citationsDiv && this.ui && typeof this.ui.renderCitations === 'function') {
          citationsDiv.innerHTML = this.ui.renderCitations(response.citations);
        }
      }
      
      // Fallback: if streaming failed or message element wasn't created
      if (!messageElement) {
        console.warn('⚠️ Streaming failed, using non-streaming fallback');
        
        // Ensure response has content
        const content = response?.content || accumulatedContent || '';
        const citations = response?.citations || [];
        
        if (!content) {
          console.error('🚨 No content in response:', response);
          this.ui?.showError(new Error('Risposta vuota dal server'));
          return;
        }
        
        try {
          if (typeof this.addBotMessage === 'function') {
            this.addBotMessage(content, citations);
          } else if (this.ui && typeof this.ui.addBotMessage === 'function') {
            this.ui.addBotMessage(content, citations);
          } else {
            this.addBotMessageDirectly?.(content, citations);
          }
        } catch (e) {
          console.error('🚨 Failed to render bot message:', e);
          this.addBotMessageDirectly?.(content, citations);
        }
      }
      
      // Save to state with proper content
      const finalContent = response?.content || accumulatedContent || '';
      const finalCitations = response?.citations || [];
      
      this.state.addMessage({ 
        role: 'assistant', 
        content: finalContent,
        citations: finalCitations
      });
      
      // 🎯 Track bot response in Agent Console
      if (this.conversationTracker && finalContent) {
        this.conversationTracker.sendMessage(finalContent, 'system');
      }
      
      // Check for form triggers after bot response (per trigger non keyword-based)
      await this.checkFormTriggers(content);
      
      // Emit event with analytics data for tracking
      this.events.emit(CONFIG.events.MESSAGE_RECEIVED, {
        content: finalContent,
        citations: finalCitations,
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
      if (this.ui && this.ui.elements && this.ui.elements.messages) {
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
     * 🚨 Emergency fallback: Add operator message via direct DOM manipulation
     */
    addOperatorMessageDirectly(content) {
      try {
        console.log('🚨 Using emergency DOM manipulation to add operator message');
        
        // Find the messages container
        const messagesContainer = document.querySelector('.chatbot-messages') || 
                                 document.querySelector('#chatbot-messages') ||
                                 document.querySelector('[class*="messages"]');
        
        if (!messagesContainer) {
          console.error('🚨 No messages container found for emergency fallback');
          return;
        }
        
        // Create a simple bot message element
        const messageEl = document.createElement('div');
        messageEl.className = 'chatbot-message bot-message';
        messageEl.innerHTML = `
          <div class="message-content">
            <div class="message-bubble bot">
              ${content}
            </div>
            <div class="message-info">
              <span class="sender">👨‍💼 Operatore</span>
              <time>${new Date().toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'})}</time>
            </div>
          </div>
        `;
        
        // Append to container
        messagesContainer.appendChild(messageEl);
        
        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        console.log('✅ Emergency operator message added via DOM manipulation');
      } catch (error) {
        console.error('🚨 Emergency fallback also failed:', error);
      }
    }

    /**
     * 🚨 Emergency fallback: Add bot message via direct DOM manipulation
     */
    addBotMessageDirectly(content, citations = []) {
      try {
        console.log('🚨 Using emergency DOM manipulation to add bot message');
        const messagesContainer = document.querySelector('.chatbot-messages') ||
                                 document.querySelector('#chatbot-messages') ||
                                 document.querySelector('[class*="messages"]');
        if (!messagesContainer) {
          console.error('🚨 No messages container found for bot fallback');
          return;
        }
        const messageEl = document.createElement('div');
        messageEl.className = 'chatbot-message bot-message';
        // 🔧 CRITICAL FIX: Parse markdown content before inserting
        console.log('🔧 Emergency fallback: parsing markdown content');
        const parsedContent = MarkdownParser.parse(content);
        console.log('🔧 Emergency fallback: content parsed, contains <a href>:', parsedContent.includes('<a href>'));
        
        messageEl.innerHTML = `
          <div class="message-content">
            <div class="message-bubble bot">${parsedContent}</div>
            <div class="message-info">
              <span class="sender">🤖 Assistente</span>
              <time>${new Date().toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'})}</time>
            </div>
          </div>`;
        messagesContainer.appendChild(messageEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        console.log('✅ Emergency bot message added via DOM manipulation');
      } catch (error) {
        console.error('🚨 Emergency bot fallback failed:', error);
      }
    }

    /**
     * 🎯 Ensure handoff button is enabled by default
     */
    ensureHandoffButtonEnabled() {
      console.log('🎯 Ensuring handoff button is enabled...');
      
      try {
        if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
          const handoffStatus = this.getHandoffStatus()?.handoffStatus;
          
          // Enable button if no active handoff
          if (!handoffStatus || handoffStatus === 'bot_only') {
            this.ui.elements.handoffBtn.disabled = false;
            // Non impostare textContent, l'icona viene gestita da applyOperatorConfiguration
            // NON sovrascrivere display/opacity - lascia che checkOperatorAvailability gestisca la visibilità
            console.log('✅ Handoff button enabled (availability managed by operator config)');
          } else {
            console.log('ℹ️ Handoff button not enabled due to status:', handoffStatus);
          }
        } else {
          console.warn('⚠️ Handoff button elements not available yet');
          
          // Retry after additional delay
          setTimeout(() => {
            console.log('🔄 Retrying handoff button enablement...');
            this.ensureHandoffButtonEnabled();
          }, 1000);
        }
      } catch (error) {
        console.error('🚨 Error enabling handoff button:', error);
      }
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

    // =================================================================
    // 🎯 AGENT CONSOLE HANDOFF METHODS
    // =================================================================

    async handleHandoffFromUI(data) {
      try {
        console.log('🎯 Handoff requested from UI:', data);
        
        // Request handoff via ConversationTracker
        const handoffRequest = await this.conversationTracker.requestHandoff(
          data.reason || 'user_request',
          data.priority || 'normal'
        );
        
        if (handoffRequest) {
          // Update UI state (inline safe)
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
              this.ui.elements.handoffBtn.disabled = true;
            }
          } catch (e) { console.warn('⚠️ Cannot disable handoff button:', e.message); }
          
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
              this.ui.elements.handoffText.textContent = '🤝 Richiesta di assistenza in corso...';
              this.ui.elements.handoffStatus.className = 'chatbot-handoff-status pending';
              this.ui.elements.handoffStatus.style.display = 'block';
            }
          } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
          
          // Add system message (safe check)
          try {
            if (typeof this.addBotMessage === 'function') {
              this.addBotMessage(
                'Ho inoltrato la tua richiesta a un operatore. Ti risponderà al più presto!',
                [],
                false
              );
            } else {
              console.warn('⚠️ addBotMessage method not available in handleHandoffFromUI');
            }
          } catch (error) {
            console.error('🚨 Error adding system message in handoff:', error);
          }
          
          console.log('🎯 Handoff request successful:', handoffRequest);

          // Aggiorna stato interno e storage per permettere il polling
          this.conversationTracker.handoffStatus = 'handoff_requested';
          try { localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, 'handoff_requested'); } catch (e) {}
          // Avvia polling subito dopo la richiesta
          if (!this.pollingInterval) {
            this.enablePollingFallback();
          }
        } else {
          throw new Error('Failed to create handoff request');
        }
      } catch (error) {
        console.error('🚨 Failed to request handoff:', error);
        try {
          if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
            this.ui.elements.handoffText.textContent = '❌ Errore nella richiesta di assistenza';
            this.ui.elements.handoffStatus.className = 'chatbot-handoff-status error';
            this.ui.elements.handoffStatus.style.display = 'block';
          }
        } catch (e) { console.warn('⚠️ Cannot show error status:', e.message); }
        
        // Add error message (safe check)
        try {
          if (typeof this.addBotMessage === 'function') {
            this.addBotMessage(
              'Mi dispiace, non è stato possibile contattare un operatore al momento. Riprova più tardi.',
              [],
              false
            );
          } else {
            console.warn('⚠️ addBotMessage method not available in error handler');
          }
        } catch (error) {
          console.error('🚨 Error adding error message:', error);
        }
      }
    }

    handleHandoffAccepted(data) {
      console.log('🎯 Handoff accepted:', data);
      
      // Update UI (inline safe)
      try {
        if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
          this.ui.elements.handoffBtn.disabled = true;
        }
      } catch (e) { console.warn('⚠️ Cannot disable handoff button:', e.message); }
      
      try {
        if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
          this.ui.elements.handoffText.textContent = '👨‍💼 Operatore connesso';
          this.ui.elements.handoffStatus.className = 'chatbot-handoff-status accepted';
          this.ui.elements.handoffStatus.style.display = 'block';
        }
      } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
      
      // Add system message (safe check)
      try {
        if (typeof this.addBotMessage === 'function') {
          this.addBotMessage(
            'Un operatore si è unito alla conversazione. Ora puoi parlare direttamente con lui!',
            [],
            false
          );
        } else {
          console.warn('⚠️ addBotMessage method not available in handleHandoffAccepted');
        }
      } catch (error) {
        console.error('🚨 Error adding system message in accepted:', error);
      }
      
      // Update conversation tracker status
      this.conversationTracker.handoffStatus = 'operator_active';
      localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, 'operator_active');
    }

    getHandoffStatus() {
      return this.conversationTracker ? this.conversationTracker.getSessionInfo() : null;
    }

    initializeHandoffUI() {
      // Initialize handoff button state based on current session
      const sessionInfo = this.getHandoffStatus();
      if (sessionInfo) {
        // Update button based on handoff status (inline safe)
        if (sessionInfo.handoffStatus === 'handoff_pending' || sessionInfo.handoffStatus === 'operator_active') {
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
              this.ui.elements.handoffBtn.disabled = true;
            }
          } catch (e) { console.warn('⚠️ Cannot disable handoff button:', e.message); }
        } else {
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
              this.ui.elements.handoffBtn.disabled = false;
              // Non impostare textContent, l'icona viene gestita da applyOperatorConfiguration
            }
          } catch (e) { console.warn('⚠️ Cannot enable handoff button:', e.message); }
        }
        
        // Show status if handoff is active (inline safe)
        if (sessionInfo.handoffStatus === 'handoff_pending') {
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
              this.ui.elements.handoffText.textContent = '🤝 Richiesta di assistenza in corso...';
              this.ui.elements.handoffStatus.className = 'chatbot-handoff-status pending';
              this.ui.elements.handoffStatus.style.display = 'block';
            }
          } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
        } else if (sessionInfo.handoffStatus === 'operator_active') {
          try {
            if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
              this.ui.elements.handoffText.textContent = '👨‍💼 Operatore connesso';
              this.ui.elements.handoffStatus.className = 'chatbot-handoff-status accepted';
              this.ui.elements.handoffStatus.style.display = 'block';
            }
          } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
        }
      }
    }

    // 💬 Handle operator message received via WebSocket
    handleOperatorMessage(message) {
      console.log('💬 Handling operator message:', message);
      
      // Add operator message to chat (multiple fallback strategies)
      try {
        // Strategy 1: Try 'this' context
        let widget = this;
        console.log('🔍 Strategy 1 - Checking this context methods:', {
          hasAddBotMessage: typeof widget.addBotMessage,
          hasCreateMessage: typeof widget.createMessageElement,
          hasAppendMessage: typeof widget.appendMessage,
          thisContext: typeof this
        });
        
        // Strategy 2: If 'this' is wrong, try global widget instance
        if (!widget.addBotMessage && typeof window.chatbotWidget !== 'undefined') {
          console.log('⚠️ Strategy 2 - Using global widget instance');
          widget = window.chatbotWidget;
        }
        
        // Strategy 3: Try direct method calls
        if (widget.addBotMessage && typeof widget.addBotMessage === 'function') {
          widget.addBotMessage(message.content, message.citations || [], false);
          console.log('✅ Operator message added via addBotMessage');
        } else if (widget.createMessageElement && widget.appendMessage) {
          console.warn('⚠️ Using fallback: createMessageElement + appendMessage');
          const messageEl = widget.createMessageElement('bot', message.content, message.citations || [], false);
          if (messageEl) {
            widget.appendMessage(messageEl);
            console.log('✅ Operator message added via fallback');
          }
        } else {
          console.error('🚨 No methods available for adding messages - trying DOM manipulation');
          // Strategy 4: Direct DOM manipulation as last resort
          this.addOperatorMessageDirectly(message.content);
        }
      } catch (error) {
        console.error('🚨 Error adding operator message:', error);
      }
      
      // Update handoff status if needed (safe check for conversationTracker)
      if (this.conversationTracker && this.conversationTracker.handoffStatus !== 'operator_active') {
        // Update internal status
        this.conversationTracker.handoffStatus = 'operator_active';
        
        // Update UI status (inline safe)
        try {
          if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
            this.ui.elements.handoffText.textContent = '👨‍💼 Operatore connesso';
            this.ui.elements.handoffStatus.className = 'chatbot-handoff-status accepted';
            this.ui.elements.handoffStatus.style.display = 'block';
          }
        } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
        
        // Update localStorage
        localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, 'operator_active');
      }
      
      // Scroll to bottom to show new message
      this.scrollToBottom();
    }

    // 🔄 Enable polling fallback for real-time messaging
    enablePollingFallback() {
      // Abilita polling solo quando una richiesta di assistenza è attiva o accettata
      const status = this.conversationTracker?.handoffStatus || localStorage.getItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey);
      if (status !== 'handoff_requested' && status !== 'handoff_active' && status !== 'operator_active') {
        console.log('📡 Polling non attivato: handoff non richiesto/attivo (status:', status, ')');
        return;
      }
      if (this.pollingInterval) {
        return; // Already enabled
      }
      
      // Initialize processed messages set for deduplication
      this.processedMessageIds = new Set();
      
      console.log('🔄 Starting polling for operator messages...');
      
      // Do an initial check immediately to validate the session
      this.checkForNewMessages();
      
      // Capture 'this' reference to ensure correct binding in callbacks
      const widget = this;
      this.pollingInterval = setInterval(() => {
        widget.checkForNewMessages().catch(error => {
          console.log('🔄 Polling error (will retry):', error);
        });
      }, 3000); // Poll every 3 seconds
    }

    // 🔄 Check for new messages via API
    async checkForNewMessages() {
      if (!this.conversationTracker.agentSessionId) {
        console.log('🔄 [Polling] Skipped - no session ID');
        return;
      }
      
      console.log('🔄 [Polling] Checking for new messages...', {
        sessionId: this.conversationTracker.agentSessionId,
        handoffStatus: this.conversationTracker.handoffStatus
      });
      
      try {
        const response = await fetch(`${this.options.baseURL}/api/v1/conversations/${this.conversationTracker.agentSessionId}/messages`, {
          method: 'GET',
          headers: {
            'Authorization': `Bearer ${this.options.apiKey}`,
            'Accept': 'application/json',
            'X-Requested-With': 'ChatbotWidget'
          }
        });
        
        console.log('🔄 [Polling] Response status:', response.status, response.ok ? '✅' : '❌');
        
        if (response.ok) {
          const data = await response.json();
          
          // 🎯 Agent Console: Check conversation status changes
          if (data.conversation) {
            this.updateConversationStatus(data.conversation);
          }
          
      // Check for new messages (operator or system)
      if (data.messages && Array.isArray(data.messages)) {
        console.log('🔄 [Polling] Received', data.messages.length, 'messages');
        this.processNewMessages(data.messages);
        // Render system messages that may signal return to bot
        try {
          for (const m of data.messages) {
            console.log('   📨 Message:', m.sender_type, '-', m.content ? m.content.substring(0, 50) + '...' : 'empty');
            if (m.sender_type === 'system' && m.content) {
              const text = (m.content || '').toLowerCase();
              console.log('   🔍 System message detected, checking for "sono tornato"...');
              if (text.includes('sono tornato')) {
                console.log('   ✅ OPERATOR RELEASED! Switching back to bot...');
                // Show system message if not already in DOM via addBotMessage
                if (typeof this.addBotMessage === 'function') {
                  this.addBotMessage(m.content, m.citations || [], false);
                }
                // Update state to bot_only and stop polling
                this.conversationTracker.handoffStatus = 'bot_only';
                localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, 'bot_only');
                this.safeHideHandoffStatus();
                if (this.pollingInterval) {
                  clearInterval(this.pollingInterval);
                  this.pollingInterval = null;
                  console.log('   🎯 Polling stopped, UI reset to bot mode');
                }
              } else {
                console.log('   ⚠️ System message does NOT contain "sono tornato"');
              }
            }
          }
        } catch (e) { console.warn('⚠️ Cannot render/handle system messages from polling:', e.message); }
      } else {
        console.log('🔄 [Polling] No messages in response');
      }
        } else if (response.status === 404) {
          // 🗑️ Conversation was deleted - clean up localStorage and stop polling
          console.warn('🗑️ Conversation not found (deleted), cleaning up localStorage...');
          
          // Clear agent session data
          this.conversationTracker.agentSessionId = null;
          this.conversationTracker.handoffStatus = 'bot_only';
          this.conversationTracker.operatorInfo = null;
          
          // Clear localStorage
          localStorage.removeItem(CONFIG.storagePrefix + CONFIG.agentSessionKey);
          localStorage.removeItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey);
          
          // Reset UI
          this.safeHideHandoffStatus();
          this.safeEnableHandoffButton();
          
          // Stop polling for this non-existent conversation
          if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
            console.log('🔄 Polling stopped for deleted conversation');
          }
          
          return; // Exit early
        }
      } catch (error) {
        // Error will be caught by setInterval handler
        throw error;
      }
    }

    // 🎯 Update conversation status from polling
    updateConversationStatus(conversation) {
      const previousHandoffStatus = this.conversationTracker.handoffStatus;
      const newHandoffStatus = conversation.handoff_status;
      
      if (previousHandoffStatus !== newHandoffStatus) {
        console.log('🔄 Handoff status changed:', previousHandoffStatus, '→', newHandoffStatus);
        
        // Update conversation tracker
        this.conversationTracker.handoffStatus = newHandoffStatus;
        
        // Update localStorage  
        localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, newHandoffStatus);
        
        // Update UI based on new status (inline safe)
        switch (newHandoffStatus) {
          case 'bot_only':
            // Conversation released - back to normal
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffStatus) {
                this.ui.elements.handoffStatus.style.display = 'none';
              }
            } catch (e) { console.warn('⚠️ Cannot hide handoff status:', e.message); }
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
                this.ui.elements.handoffBtn.disabled = false;
                // Non impostare textContent, l'icona viene gestita da applyOperatorConfiguration
              }
            } catch (e) { console.warn('⚠️ Cannot enable handoff button:', e.message); }
            // Stop polling quando torna al bot
            if (this.pollingInterval) {
              clearInterval(this.pollingInterval);
              this.pollingInterval = null;
              console.log('📡 Polling disattivato: status bot_only');
            }
            console.log('✅ Conversation released - bot active');
            break;
          case 'handoff_requested':
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
                this.ui.elements.handoffText.textContent = 'Richiesta di assistenza in corso...';
                this.ui.elements.handoffStatus.className = 'chatbot-handoff-status pending';
                this.ui.elements.handoffStatus.style.display = 'block';
              }
            } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
                this.ui.elements.handoffBtn.disabled = true;
              }
            } catch (e) { console.warn('⚠️ Cannot disable handoff button:', e.message); }
            break;
          case 'handoff_active':
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffStatus && this.ui.elements.handoffText) {
                this.ui.elements.handoffText.textContent = '👨‍💼 Operatore connesso';
                this.ui.elements.handoffStatus.className = 'chatbot-handoff-status accepted';
                this.ui.elements.handoffStatus.style.display = 'block';
              }
            } catch (e) { console.warn('⚠️ Cannot show handoff status:', e.message); }
            try {
              if (this.ui && this.ui.elements && this.ui.elements.handoffBtn) {
                this.ui.elements.handoffBtn.disabled = true;
              }
            } catch (e) { console.warn('⚠️ Cannot disable handoff button:', e.message); }
            break;
        }
        // Avvia polling quando entra in stato di handoff
        if ((newHandoffStatus === 'handoff_requested' || newHandoffStatus === 'handoff_active' || newHandoffStatus === 'operator_active') && !this.pollingInterval) {
          this.enablePollingFallback();
        }
      }
    }

    // 🔄 Process new messages from polling
    processNewMessages(messages) {
      const lastMessageTime = this.lastPollingMessageTime || 0;
      let newMessagesFound = false;
      let latestMessageTime = lastMessageTime;
      
      console.log('🔄 Processing messages:', {
        messageCount: messages.length,
        lastMessageTime: new Date(lastMessageTime).toISOString(),
        processedIds: Array.from(this.processedMessageIds || [])
      });
      
      messages.forEach(message => {
        const messageTime = new Date(message.sent_at).getTime();
        
        // Track the latest message time
        if (messageTime > latestMessageTime) {
          latestMessageTime = messageTime;
        }
        
        // Only process messages newer than our last check AND from operator AND not already processed
        if (messageTime > lastMessageTime && 
            message.sender_type === 'operator' && 
            !(this.processedMessageIds && this.processedMessageIds.has(message.id))) {
          
          console.log('📨 New operator message via polling:', message);
          // Call with correct binding
          this.handleOperatorMessage.call(this, message);
          
          // Initialize set if not exists and add message ID
          if (!this.processedMessageIds) {
            this.processedMessageIds = new Set();
          }
          this.processedMessageIds.add(message.id);
          newMessagesFound = true;
        }
      });
      
      // Always update timestamp to prevent re-processing
      if (messages.length > 0) {
        this.lastPollingMessageTime = latestMessageTime;
        console.log('🕒 Updated lastPollingMessageTime to:', new Date(latestMessageTime).toISOString());
      }
    }

    // 📡 Setup WebSocket listeners for real-time operator messages
    setupWebSocketListeners(retryCount = 0) {
      try {
        // Check if Echo is available and properly initialized
        if (typeof window.Echo === 'undefined' || 
            typeof window.Echo.channel !== 'function' ||
            !window.Echo.connector) {
          if (retryCount >= 5) {
            console.warn('📡 Gave up waiting for Echo after 5 retries');
            return;
          }
          
          console.log('📡 Echo not ready yet, will setup WebSocket listeners later (retry', retryCount + 1, ')');
          console.log('📡 Echo status:', {
            echoExists: typeof window.Echo !== 'undefined',
            channelFunction: typeof window.Echo?.channel,
            hasConnector: !!window.Echo?.connector
          });
          
          // Retry after a short delay
          setTimeout(() => {
            this.setupWebSocketListeners(retryCount + 1);
          }, 2000);
          return;
        }

        const sessionId = this.conversationTracker.agentSessionId;
        if (!sessionId) {
          console.warn('📡 No agent session ID, cannot setup WebSocket listeners');
          return;
        }

        console.log('📡 Setting up WebSocket listeners for session:', sessionId);
        console.log('📡 Echo object:', window.Echo);
        console.log('📡 Echo connector:', window.Echo.connector);
        console.log('📡 Echo methods:', Object.getOwnPropertyNames(window.Echo));
        console.log('📡 Echo prototype methods:', Object.getOwnPropertyNames(Object.getPrototypeOf(window.Echo)));

        // Listen for operator messages (using public channel for testing)
        console.log('📡 Attempting to listen on public channel: conversation.' + sessionId);
        window.Echo.channel(`conversation.${sessionId}`)
          // Support both Laravel default naming (broadcastAs) and class name
          .listen('.message.sent', (event) => {
            console.log('📡 WebSocket message (broadcastAs) received:', event);
            this.handleIncomingRealtimeMessage(event);
          })
          .listen('ConversationMessageSent', (event) => {
            console.log('📡 WebSocket message received:', event);
            this.handleIncomingRealtimeMessage(event);
          })
          .error((error) => {
            console.error('📡 WebSocket error:', error);
          });

        console.log('📡 WebSocket listeners setup completed');

      } catch (error) {
        console.error('📡 Failed to setup WebSocket listeners:', error);
      }
    }

    // 🔔 Handle any realtime message (operator or system)
    handleIncomingRealtimeMessage(event) {
      try {
        const message = event && event.message ? event.message : null;
        if (!message) return;

        if (message.sender_type === 'operator') {
          console.log('👨‍💼 Operator message received via WebSocket');
          this.handleOperatorMessage.call(this, message);
          return;
        }

        if (message.sender_type === 'system') {
          console.log('🤖 System message received via WebSocket');
          // Mostra messaggio di sistema come bot message
          try {
            if (typeof this.addBotMessage === 'function') {
              this.addBotMessage(message.content, message.citations || [], false);
            } else if (this.createMessageElement && this.appendMessage) {
              const el = this.createMessageElement('bot', message.content, message.citations || [], false);
              if (el) this.appendMessage(el);
            }
          } catch (e) {
            console.warn('⚠️ Cannot render system message:', e.message);
          }

          // Se il system message indica ritorno al bot, aggiorna stato
          try {
            if (this.conversationTracker && this.conversationTracker.handoffStatus !== 'bot_only') {
              // Heuristic: se content contiene "Sono tornato" o session.handoff_status nel payload
              const text = (message.content || '').toLowerCase();
              if (text.includes('sono tornato') || (event.session && event.session.handoff_status === 'bot_only')) {
                this.conversationTracker.handoffStatus = 'bot_only';
                localStorage.setItem(CONFIG.storagePrefix + CONFIG.handoffStatusKey, 'bot_only');
                this.safeHideHandoffStatus();
              }
            }
          } catch (_) {}
        }
      } catch (e) {
        console.error('🚨 Error handling realtime message:', e);
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
