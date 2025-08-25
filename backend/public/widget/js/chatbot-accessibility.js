/**
 * 🌐 Chatbot Widget - Accessibility Manager (WCAG 2.1 AA)
 * 
 * Comprehensive accessibility features for the chatbot widget:
 * - Focus management and keyboard navigation
 * - Screen reader support with ARIA live regions
 * - Focus trapping when widget is open
 * - Keyboard shortcuts and escape handlers
 * - Dynamic content announcements
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 🌐 ACCESSIBILITY MANAGER CLASS
  // =================================================================

  class AccessibilityManager {
    constructor(chatbotInstance) {
      this.chatbot = chatbotInstance;
      this.isEnabled = true;
      this.focusTrapActive = false;
      this.previouslyFocusedElement = null;
      this.keyboardNavActive = false;
      
      // DOM elements for accessibility
      this.liveRegion = null;
      this.statusRegion = null;
      this.skipLink = null;
      
      // Focus trap elements
      this.focusableElements = [];
      this.firstFocusableElement = null;
      this.lastFocusableElement = null;
      
      // Keyboard shortcuts
      this.shortcuts = {
        'Escape': () => this.handleEscape(),
        'Tab': (e) => this.handleTab(e),
        'Enter': (e) => this.handleEnter(e),
        'ArrowUp': (e) => this.handleArrowUp(e),
        'ArrowDown': (e) => this.handleArrowDown(e),
        'Home': (e) => this.handleHome(e),
        'End': (e) => this.handleEnd(e)
      };
      
      this.init();
    }

    init() {
      this.createAccessibilityElements();
      this.setupEventListeners();
      this.enhanceExistingElements();
      this.setupKeyboardNavigation();
      this.announceWidgetReady();
    }

    // =================================================================
    // 🎯 INITIALIZATION
    // =================================================================

    createAccessibilityElements() {
      // Create ARIA live region for dynamic announcements
      this.liveRegion = document.createElement('div');
      this.liveRegion.setAttribute('aria-live', 'polite');
      this.liveRegion.setAttribute('aria-atomic', 'true');
      this.liveRegion.className = 'chatbot-live-region';
      this.liveRegion.id = 'chatbot-live-region';
      document.body.appendChild(this.liveRegion);

      // Create status region for immediate announcements
      this.statusRegion = document.createElement('div');
      this.statusRegion.setAttribute('aria-live', 'assertive');
      this.statusRegion.setAttribute('aria-atomic', 'true');
      this.statusRegion.className = 'chatbot-status-announcement';
      this.statusRegion.id = 'chatbot-status-region';
      document.body.appendChild(this.statusRegion);

      // Create skip link for keyboard users
      this.skipLink = document.createElement('a');
      this.skipLink.href = '#chatbot-input';
      this.skipLink.className = 'chatbot-skip-link';
      this.skipLink.textContent = 'Vai al chatbot';
      this.skipLink.setAttribute('tabindex', '0');
      
      const container = document.getElementById('chatbot-container');
      if (container) {
        container.parentNode.insertBefore(this.skipLink, container);
      }
    }

    enhanceExistingElements() {
      const container = document.getElementById('chatbot-container');
      const fab = document.getElementById('chatbot-fab');
      const header = container?.querySelector('.chatbot-header');
      const messages = document.getElementById('chatbot-messages');
      const inputArea = container?.querySelector('.chatbot-input-area');
      const input = document.getElementById('chatbot-input');
      const sendButton = document.getElementById('chatbot-send-button');
      const closeButton = document.getElementById('chatbot-close-button');

      // Enhance main container
      if (container) {
        container.setAttribute('role', 'dialog');
        container.setAttribute('aria-labelledby', 'chatbot-header-title');
        container.setAttribute('aria-describedby', 'chatbot-messages');
        container.setAttribute('aria-modal', 'true');
        container.setAttribute('aria-hidden', 'true'); // Initially hidden
        container.classList.add('chatbot-focus-trap');
      }

      // Enhance FAB
      if (fab) {
        fab.setAttribute('aria-label', 'Apri assistente virtuale');
        fab.setAttribute('aria-expanded', 'false');
        fab.setAttribute('aria-controls', 'chatbot-container');
        fab.classList.add('chatbot-focus-ring');
      }

      // Enhance header
      if (header) {
        header.setAttribute('role', 'banner');
      }

      // Enhance messages area
      if (messages) {
        messages.setAttribute('role', 'log');
        messages.setAttribute('aria-label', 'Conversazione con assistente virtuale');
        messages.setAttribute('aria-live', 'polite');
        messages.setAttribute('aria-relevant', 'additions');
      }

      // Enhance input area
      if (inputArea) {
        inputArea.setAttribute('role', 'form');
        inputArea.setAttribute('aria-label', 'Invia messaggio');
      }

      // Enhance input field
      if (input) {
        input.setAttribute('aria-label', 'Scrivi il tuo messaggio');
        input.setAttribute('aria-describedby', 'chatbot-input-help');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-autocomplete', 'none');
        
        // Add helper text
        const helpText = document.createElement('div');
        helpText.id = 'chatbot-input-help';
        helpText.className = 'chatbot-sr-only';
        helpText.textContent = 'Premi Invio per inviare il messaggio, Escape per chiudere il chatbot';
        input.parentNode.appendChild(helpText);
      }

      // Enhance send button
      if (sendButton) {
        sendButton.setAttribute('aria-label', 'Invia messaggio');
        sendButton.setAttribute('type', 'submit');
        sendButton.classList.add('chatbot-focus-ring');
      }

      // Enhance close button
      if (closeButton) {
        closeButton.setAttribute('aria-label', 'Chiudi assistente virtuale');
        closeButton.classList.add('chatbot-focus-ring');
      }
    }

    setupEventListeners() {
      // Global keyboard event handler
      document.addEventListener('keydown', (e) => this.handleGlobalKeydown(e));
      
      // Focus events for keyboard navigation detection
      document.addEventListener('focusin', () => this.keyboardNavActive = true);
      document.addEventListener('mousedown', () => this.keyboardNavActive = false);
      
      // Chat events for accessibility feedback
      this.chatbot.events.on('chatbot:widget:opened', () => this.handleWidgetOpened());
      this.chatbot.events.on('chatbot:widget:closed', () => this.handleWidgetClosed());
      this.chatbot.events.on('chatbot:message:sent', (data) => this.handleMessageSent(data));
      this.chatbot.events.on('chatbot:message:received', (data) => this.handleMessageReceived(data));
      this.chatbot.events.on('chatbot:error', (error) => this.handleError(error));
      this.chatbot.events.on('chatbot:typing:start', () => this.handleTypingStart());
      this.chatbot.events.on('chatbot:typing:end', () => this.handleTypingEnd());
    }

    setupKeyboardNavigation() {
      const container = document.getElementById('chatbot-container');
      if (container) {
        container.addEventListener('keydown', (e) => this.handleContainerKeydown(e));
      }
    }

    // =================================================================
    // 🎯 FOCUS MANAGEMENT
    // =================================================================

    trapFocus() {
      this.focusTrapActive = true;
      this.updateFocusableElements();
      
      // Set initial focus to input field
      const input = document.getElementById('chatbot-input');
      if (input) {
        setTimeout(() => input.focus(), 100);
      }
    }

    releaseFocus() {
      this.focusTrapActive = false;
      
      // Return focus to previously focused element or FAB
      if (this.previouslyFocusedElement && document.contains(this.previouslyFocusedElement)) {
        this.previouslyFocusedElement.focus();
      } else {
        const fab = document.getElementById('chatbot-fab');
        if (fab) fab.focus();
      }
      
      this.previouslyFocusedElement = null;
    }

    updateFocusableElements() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      const focusableSelector = [
        'input:not([disabled]):not([aria-hidden="true"])',
        'button:not([disabled]):not([aria-hidden="true"])',
        'textarea:not([disabled]):not([aria-hidden="true"])',
        'select:not([disabled]):not([aria-hidden="true"])',
        'a[href]:not([aria-hidden="true"])',
        '[tabindex]:not([tabindex="-1"]):not([aria-hidden="true"])',
        '[role="button"]:not([disabled]):not([aria-hidden="true"])',
        '[role="link"]:not([aria-hidden="true"])'
      ].join(',');

      this.focusableElements = Array.from(container.querySelectorAll(focusableSelector))
        .filter(el => this.isVisible(el));

      this.firstFocusableElement = this.focusableElements[0];
      this.lastFocusableElement = this.focusableElements[this.focusableElements.length - 1];
    }

    isVisible(element) {
      const style = window.getComputedStyle(element);
      return style.display !== 'none' && 
             style.visibility !== 'hidden' && 
             style.opacity !== '0' &&
             element.offsetWidth > 0 && 
             element.offsetHeight > 0;
    }

    focusNext() {
      if (!this.focusableElements.length) return;
      
      const currentIndex = this.focusableElements.indexOf(document.activeElement);
      const nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % this.focusableElements.length;
      
      this.focusableElements[nextIndex].focus();
    }

    focusPrevious() {
      if (!this.focusableElements.length) return;
      
      const currentIndex = this.focusableElements.indexOf(document.activeElement);
      const prevIndex = currentIndex === -1 ? 
        this.focusableElements.length - 1 : 
        (currentIndex - 1 + this.focusableElements.length) % this.focusableElements.length;
      
      this.focusableElements[prevIndex].focus();
    }

    // =================================================================
    // ⌨️ KEYBOARD HANDLERS
    // =================================================================

    handleGlobalKeydown(e) {
      // Handle global keyboard shortcuts
      if (e.key === 'Escape' && this.focusTrapActive) {
        e.preventDefault();
        this.chatbot.close();
        return;
      }

      // Alt + C to open chatbot
      if (e.altKey && e.key.toLowerCase() === 'c' && !this.focusTrapActive) {
        e.preventDefault();
        this.chatbot.open();
        return;
      }
    }

    handleContainerKeydown(e) {
      if (!this.focusTrapActive) return;

      const handler = this.shortcuts[e.key];
      if (handler) {
        handler(e);
      }
    }

    handleTab(e) {
      if (!this.focusTrapActive) return;

      this.updateFocusableElements();

      if (this.focusableElements.length === 0) {
        e.preventDefault();
        return;
      }

      if (e.shiftKey) {
        // Shift + Tab (backwards)
        if (document.activeElement === this.firstFocusableElement) {
          e.preventDefault();
          this.lastFocusableElement.focus();
        }
      } else {
        // Tab (forwards)
        if (document.activeElement === this.lastFocusableElement) {
          e.preventDefault();
          this.firstFocusableElement.focus();
        }
      }
    }

    handleEscape() {
      if (this.focusTrapActive) {
        this.chatbot.close();
      }
    }

    handleEnter(e) {
      const target = e.target;
      
      if (target.id === 'chatbot-input') {
        e.preventDefault();
        const sendButton = document.getElementById('chatbot-send-button');
        if (sendButton) sendButton.click();
      }
    }

    handleArrowUp(e) {
      const messages = document.getElementById('chatbot-messages');
      if (messages && document.activeElement === messages) {
        e.preventDefault();
        messages.scrollTop -= 50;
      }
    }

    handleArrowDown(e) {
      const messages = document.getElementById('chatbot-messages');
      if (messages && document.activeElement === messages) {
        e.preventDefault();
        messages.scrollTop += 50;
      }
    }

    handleHome(e) {
      const messages = document.getElementById('chatbot-messages');
      if (messages && document.activeElement === messages) {
        e.preventDefault();
        messages.scrollTop = 0;
      }
    }

    handleEnd(e) {
      const messages = document.getElementById('chatbot-messages');
      if (messages && document.activeElement === messages) {
        e.preventDefault();
        messages.scrollTop = messages.scrollHeight;
      }
    }

    // =================================================================
    // 📢 SCREEN READER ANNOUNCEMENTS
    // =================================================================

    announce(message, priority = 'polite') {
      const region = priority === 'assertive' ? this.statusRegion : this.liveRegion;
      
      if (region) {
        region.textContent = '';
        setTimeout(() => {
          region.textContent = message;
        }, 100);
      }
    }

    announceWidgetReady() {
      this.announce('Assistente virtuale pronto. Premi Alt+C per aprire o usa il pulsante.', 'polite');
    }

    // =================================================================
    // 🎭 EVENT HANDLERS
    // =================================================================

    handleWidgetOpened() {
      const container = document.getElementById('chatbot-container');
      const fab = document.getElementById('chatbot-fab');
      
      if (container) {
        container.setAttribute('aria-hidden', 'false');
      }
      
      if (fab) {
        fab.setAttribute('aria-expanded', 'true');
      }
      
      // Store previously focused element
      this.previouslyFocusedElement = document.activeElement;
      
      // Trap focus and announce opening
      this.trapFocus();
      this.announce('Assistente virtuale aperto. Usa Tab per navigare, Escape per chiudere.', 'assertive');
      
      // Update keyboard navigation indicator
      if (container && this.keyboardNavActive) {
        container.setAttribute('data-keyboard-nav', 'true');
      }
    }

    handleWidgetClosed() {
      const container = document.getElementById('chatbot-container');
      const fab = document.getElementById('chatbot-fab');
      
      if (container) {
        container.setAttribute('aria-hidden', 'true');
        container.removeAttribute('data-keyboard-nav');
      }
      
      if (fab) {
        fab.setAttribute('aria-expanded', 'false');
      }
      
      // Release focus and announce closing
      this.releaseFocus();
      this.announce('Assistente virtuale chiuso.', 'assertive');
    }

    handleMessageSent(data) {
      this.announce(`Messaggio inviato: ${data.content}`, 'polite');
    }

    handleMessageReceived(data) {
      const citationsText = data.citations && data.citations.length > 0 
        ? ` con ${data.citations.length} ${data.citations.length === 1 ? 'fonte' : 'fonti'}`
        : '';
      
      this.announce(`Risposta ricevuta${citationsText}.`, 'polite');
      
      // Ensure messages area is scrolled to bottom for screen readers
      const messages = document.getElementById('chatbot-messages');
      if (messages) {
        setTimeout(() => {
          messages.scrollTop = messages.scrollHeight;
        }, 100);
      }
    }

    handleError(error) {
      this.announce(`Errore: ${error.message || 'Si è verificato un errore inaspettato.'}`, 'assertive');
    }

    handleTypingStart() {
      this.announce('L\'assistente sta scrivendo...', 'polite');
    }

    handleTypingEnd() {
      // Don't announce typing end as it will be followed by message received
    }

    // =================================================================
    // 🛠️ UTILITY METHODS
    // =================================================================

    enable() {
      this.isEnabled = true;
      this.announce('Accessibilità abilitata.', 'polite');
    }

    disable() {
      this.isEnabled = false;
      if (this.focusTrapActive) {
        this.releaseFocus();
      }
    }

    // Add ARIA labels to dynamically created messages
    enhanceMessage(messageElement, isUser = false) {
      if (!messageElement || !messageElement.setAttribute || typeof messageElement.setAttribute !== 'function') {
        console.warn('ChatbotAccessibilityManager: Invalid message element provided to enhanceMessage');
        return;
      }

      const role = isUser ? 'user' : 'assistant';
      const label = isUser ? 'Tuo messaggio' : 'Risposta assistente';
      const timestamp = new Date().toLocaleTimeString('it-IT', { 
        hour: '2-digit', 
        minute: '2-digit' 
      });

      messageElement.setAttribute('role', 'group');
      messageElement.setAttribute('aria-label', `${label}, ${timestamp}`);
      messageElement.setAttribute('tabindex', '0');

      // Add citation information for screen readers
      if (!isUser) {
        const citations = messageElement.querySelectorAll('a[href]');
        if (citations.length > 0) {
          const citationText = document.createElement('span');
          citationText.className = 'chatbot-sr-only';
          citationText.textContent = ` Include ${citations.length} ${citations.length === 1 ? 'fonte' : 'fonti'}.`;
          messageElement.appendChild(citationText);
        }
      }
    }

    // Add loading state announcement
    announceLoading() {
      this.announce('Elaborazione in corso...', 'polite');
    }

    // Remove loading state announcement
    announceLoadingComplete() {
      this.announce('Elaborazione completata.', 'polite');
    }

    // Clean up accessibility elements
    destroy() {
      if (this.liveRegion && this.liveRegion.parentNode) {
        this.liveRegion.parentNode.removeChild(this.liveRegion);
      }
      
      if (this.statusRegion && this.statusRegion.parentNode) {
        this.statusRegion.parentNode.removeChild(this.statusRegion);
      }
      
      if (this.skipLink && this.skipLink.parentNode) {
        this.skipLink.parentNode.removeChild(this.skipLink);
      }
    }
  }

  // =================================================================
  // 🌐 GLOBAL EXPORT
  // =================================================================

  // Export to global scope for use by chatbot widget
  window.ChatbotAccessibilityManager = AccessibilityManager;

})();
