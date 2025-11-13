/**
 * 📎 Chatbot Widget - Citations Manager
 * 
 * Gestisce la visualizzazione e l'interazione con le citazioni RAG:
 * - Generazione secure token per visualizzazione documenti
 * - UI per citazioni interattive con deep-link
 * - Modal/iframe per visualizzazione documenti inline
 * - Caching e ottimizzazioni performance
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 📎 CITATIONS MANAGER CLASS
  // =================================================================

  class CitationsManager {
    constructor(chatbotInstance) {
      this.chatbot = chatbotInstance;
      this.apiKey = chatbotInstance.options.apiKey;
      this.tenantId = chatbotInstance.options.tenantId;
      this.baseURL = chatbotInstance.options.baseURL || '';
      
      // Cache for view tokens
      this.tokenCache = new Map();
      this.documentInfoCache = new Map();
      
      // Modal state
      this.modal = null;
      this.isModalOpen = false;
      
      this.init();
    }

    init() {
      this.createModal();
      this.setupEventListeners();
    }

    // =================================================================
    // 🎨 UI CREATION
    // =================================================================

    createModal() {
      // Create modal structure
      this.modal = document.createElement('div');
      this.modal.className = 'chatbot-citation-modal';
      this.modal.setAttribute('role', 'dialog');
      this.modal.setAttribute('aria-modal', 'true');
      this.modal.setAttribute('aria-labelledby', 'citation-modal-title');
      this.modal.setAttribute('aria-hidden', 'true');
      this.modal.style.display = 'none';
      
      this.modal.innerHTML = `
        <div class="chatbot-citation-modal-backdrop" aria-hidden="true"></div>
        <div class="chatbot-citation-modal-container">
          <div class="chatbot-citation-modal-header">
            <h3 id="citation-modal-title" class="chatbot-citation-modal-title">
              Visualizza Documento
            </h3>
            <button 
              type="button" 
              class="chatbot-citation-modal-close"
              aria-label="Chiudi visualizzazione documento"
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          
          <div class="chatbot-citation-modal-body">
            <div class="chatbot-citation-loading" role="status" aria-label="Caricamento documento">
              <div class="chatbot-loading-dots">
                <span></span><span></span><span></span>
              </div>
              <span class="chatbot-sr-only">Caricamento documento in corso...</span>
            </div>
            
            <iframe 
              class="chatbot-citation-iframe"
              title="Visualizzazione documento"
              sandbox="allow-scripts allow-forms"
              style="display: none;"
            ></iframe>
            
            <div class="chatbot-citation-error" style="display: none;" role="alert">
              <div class="chatbot-citation-error-icon" aria-hidden="true">❌</div>
              <div class="chatbot-citation-error-message"></div>
              <button type="button" class="chatbot-citation-retry">Riprova</button>
            </div>
          </div>
          
          <div class="chatbot-citation-modal-footer">
            <div class="chatbot-citation-info">
              <span class="chatbot-citation-document-type"></span>
              <span class="chatbot-citation-document-size"></span>
            </div>
            <div class="chatbot-citation-actions">
              <a 
                href="#" 
                class="chatbot-citation-download"
                target="_blank" 
                rel="noopener noreferrer"
                aria-label="Scarica documento originale"
              >
                📥 Download
              </a>
            </div>
          </div>
        </div>
      `;
      
      document.body.appendChild(this.modal);
    }

    setupEventListeners() {
      // Close modal events
      const closeButton = this.modal.querySelector('.chatbot-citation-modal-close');
      const backdrop = this.modal.querySelector('.chatbot-citation-modal-backdrop');
      
      closeButton.addEventListener('click', () => this.closeModal());
      backdrop.addEventListener('click', () => this.closeModal());
      
      // Escape key to close modal
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isModalOpen) {
          this.closeModal();
        }
      });
      
      // Retry button
      const retryButton = this.modal.querySelector('.chatbot-citation-retry');
      retryButton.addEventListener('click', () => this.retryCurrentDocument());
    }

    // =================================================================
    // 📄 CITATION RENDERING
    // =================================================================

    renderCitations(citations) {
      if (!citations || citations.length === 0) {
        return '';
      }

      const citationElements = citations.map((citation, index) => {
        const icon = this.getDocumentIcon(citation.document_type);
        const rawTitle = citation.document_title || citation.title || `Fonte ${index + 1}`;
        const title = this.escapeHtml(rawTitle);
        const snippet = this.escapeHtml(citation.snippet || citation.chunk_text || '');
        const pageUrl = citation.page_url || citation.document_source_url || citation.url || '#';
        
        const documentId = citation.document_id || citation.id || '';

        return `
          <div class="chatbot-citation" data-citation-index="${index}">
            <div class="chatbot-citation-header">
              <span class="chatbot-citation-icon" aria-hidden="true">${icon}</span>
              <button 
                type="button"
                class="chatbot-citation-title"
                data-document-id="${documentId}"
                data-chunk-index="${citation.chunk_index || 0}"
                data-chunk-text="${this.escapeHtml(citation.chunk_text || '')}"
                data-document-title="${title}"
                data-document-url="${this.escapeHtml(pageUrl)}"
                aria-describedby="citation-${index}-snippet"
                title="Clicca per visualizzare il documento"
              >
                ${title}
              </button>
            </div>
            <div 
              id="citation-${index}-snippet" 
              class="chatbot-citation-snippet"
              aria-label="Estratto dal documento"
            >
              ${snippet}
            </div>
          </div>
        `;
      }).join('');

      return `
        <div class="chatbot-citations" role="group" aria-label="Fonti delle informazioni">
          <div class="chatbot-citations-header">
            <span class="chatbot-citations-icon" aria-hidden="true">📎</span>
            <span class="chatbot-citations-label">
              ${citations.length === 1 ? 'Fonte' : 'Fonti'} (${citations.length})
            </span>
          </div>
          <div class="chatbot-citations-list">
            ${citationElements}
          </div>
        </div>
      `;
    }

    // =================================================================
    // 🔐 TOKEN MANAGEMENT
    // =================================================================

    async generateViewToken(documentId, chunkIndex = 0, highlightText = '') {
      const cacheKey = `${documentId}-${chunkIndex}-${highlightText}`;
      
      // Check cache first
      if (this.tokenCache.has(cacheKey)) {
        const cached = this.tokenCache.get(cacheKey);
        if (cached.expires_at > Date.now()) {
          return cached;
        } else {
          this.tokenCache.delete(cacheKey);
        }
      }

      try {
        const response = await fetch(`${this.baseURL}/api/v1/documents/view-token`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.apiKey}`,
            'X-Tenant-Id': this.tenantId.toString()
          },
          body: JSON.stringify({
            document_id: documentId,
            chunk_position: chunkIndex,
            highlight_text: highlightText,
            expires_in: 3600 // 1 hour
          })
        });

        if (!response.ok) {
          throw new Error(`Failed to generate view token: ${response.status}`);
        }

        const data = await response.json();
        
        if (!data.success) {
          throw new Error(data.message || 'Failed to generate view token');
        }

        // Cache the token
        const tokenData = {
          ...data,
          expires_at: Date.now() + (50 * 60 * 1000) // Cache for 50 minutes (before 1h expiry)
        };
        
        this.tokenCache.set(cacheKey, tokenData);
        return tokenData;

      } catch (error) {
        console.error('Error generating view token:', error);
        throw error;
      }
    }

    // =================================================================
    // 📄 DOCUMENT VIEWING
    // =================================================================

    async openDocument(documentId, chunkIndex = 0, highlightText = '') {
      try {
        this.openModal();
        this.showLoading();

        // Generate secure view token
        const tokenData = await this.generateViewToken(documentId, chunkIndex, highlightText);
        
        // Update modal title with document info
        const modalTitle = this.modal.querySelector('#citation-modal-title');
        modalTitle.textContent = `Visualizza: ${tokenData.document.title}`;
        
        // Update footer info
        this.updateModalFooter(tokenData.document, tokenData.view_url);
        
        // Load document in iframe
        await this.loadDocumentInIframe(tokenData.view_url);
        
        this.hideLoading();

      } catch (error) {
        console.error('Error opening document:', error);
        this.hideLoading();
        this.showError(error.message || 'Errore durante il caricamento del documento');
      }
    }

    async loadDocumentInIframe(viewUrl) {
      return new Promise((resolve, reject) => {
        const iframe = this.modal.querySelector('.chatbot-citation-iframe');
        
        // Set up load handlers
        const onLoad = () => {
          iframe.style.display = 'block';
          cleanup();
          resolve();
        };
        
        const onError = () => {
          cleanup();
          reject(new Error('Errore durante il caricamento del documento'));
        };
        
        const onTimeout = () => {
          cleanup();
          reject(new Error('Timeout durante il caricamento del documento'));
        };
        
        const cleanup = () => {
          iframe.removeEventListener('load', onLoad);
          iframe.removeEventListener('error', onError);
          clearTimeout(timeoutId);
        };
        
        // Set timeout for loading
        const timeoutId = setTimeout(onTimeout, 15000); // 15 seconds
        
        iframe.addEventListener('load', onLoad);
        iframe.addEventListener('error', onError);
        
        // Start loading
        iframe.src = viewUrl;
      });
    }

    // =================================================================
    // 🎭 MODAL MANAGEMENT
    // =================================================================

    openModal() {
      if (this.isModalOpen) return;
      
      this.isModalOpen = true;
      this.modal.style.display = 'flex';
      this.modal.setAttribute('aria-hidden', 'false');
      
      // Focus management
      setTimeout(() => {
        const closeButton = this.modal.querySelector('.chatbot-citation-modal-close');
        if (closeButton) closeButton.focus();
      }, 100);
      
      // Prevent body scroll
      document.body.style.overflow = 'hidden';
      
      // Announce to screen readers
      if (this.chatbot.accessibility) {
        this.chatbot.accessibility.announce('Visualizzazione documento aperta', 'assertive');
      }
    }

    closeModal() {
      if (!this.isModalOpen) return;
      
      this.isModalOpen = false;
      this.modal.style.display = 'none';
      this.modal.setAttribute('aria-hidden', 'true');
      
      // Clear iframe
      const iframe = this.modal.querySelector('.chatbot-citation-iframe');
      iframe.src = 'about:blank';
      iframe.style.display = 'none';
      
      // Reset modal state
      this.hideLoading();
      this.hideError();
      
      // Restore body scroll
      document.body.style.overflow = '';
      
      // Return focus to chatbot
      const input = document.getElementById('chatbot-input');
      if (input) input.focus();
      
      // Announce to screen readers
      if (this.chatbot.accessibility) {
        this.chatbot.accessibility.announce('Visualizzazione documento chiusa', 'polite');
      }
    }

    showLoading() {
      const loading = this.modal.querySelector('.chatbot-citation-loading');
      const iframe = this.modal.querySelector('.chatbot-citation-iframe');
      const error = this.modal.querySelector('.chatbot-citation-error');
      
      loading.style.display = 'flex';
      iframe.style.display = 'none';
      error.style.display = 'none';
    }

    hideLoading() {
      const loading = this.modal.querySelector('.chatbot-citation-loading');
      loading.style.display = 'none';
    }

    showError(message) {
      const error = this.modal.querySelector('.chatbot-citation-error');
      const errorMessage = this.modal.querySelector('.chatbot-citation-error-message');
      const loading = this.modal.querySelector('.chatbot-citation-loading');
      const iframe = this.modal.querySelector('.chatbot-citation-iframe');
      
      errorMessage.textContent = message;
      error.style.display = 'flex';
      loading.style.display = 'none';
      iframe.style.display = 'none';
    }

    hideError() {
      const error = this.modal.querySelector('.chatbot-citation-error');
      error.style.display = 'none';
    }

    updateModalFooter(document, downloadUrl) {
      const typeSpan = this.modal.querySelector('.chatbot-citation-document-type');
      const sizeSpan = this.modal.querySelector('.chatbot-citation-document-size');
      const downloadLink = this.modal.querySelector('.chatbot-citation-download');
      
      typeSpan.textContent = document.type?.toUpperCase() || 'DOC';
      sizeSpan.textContent = this.formatFileSize(document.size);
      downloadLink.href = downloadUrl || '#';
    }

    retryCurrentDocument() {
      // Implementation for retry functionality
      this.hideError();
      // Would need to store current document info to retry
    }

    // =================================================================
    // 🛠️ UTILITY METHODS
    // =================================================================

    getDocumentIcon(documentType) {
      const type = (documentType || '').toLowerCase();
      return {
        'pdf': '📄',
        'doc': '📄',
        'docx': '📄',
        'txt': '📝',
        'md': '📝',
        'xlsx': '📊',
        'xls': '📊',
        'csv': '📊',
        'pptx': '📊',
        'ppt': '📊',
        'html': '🌐',
        'htm': '🌐',
        'json': '📋',
        'xml': '📋'
      }[type] || '📁';
    }

    escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    formatFileSize(bytes) {
      if (!bytes || bytes === 0) return '0 B';
      
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    // =================================================================
    // 📎 PUBLIC API
    // =================================================================

    // Handle citation click events
    handleCitationClick(citationElement) {
      const documentId = parseInt(citationElement.dataset.documentId);
      const chunkIndex = parseInt(citationElement.dataset.chunkIndex) || 0;
      const highlightText = citationElement.dataset.chunkText || '';
      
      if (!documentId) {
        console.warn('Citation missing document ID');
        return;
      }

      this.openDocument(documentId, chunkIndex, highlightText);
    }

    // Clean up resources
    destroy() {
      if (this.modal && this.modal.parentNode) {
        this.modal.parentNode.removeChild(this.modal);
      }
      
      // Clear caches
      this.tokenCache.clear();
      this.documentInfoCache.clear();
    }
  }

  // =================================================================
  // 🌐 GLOBAL EXPORT
  // =================================================================

  // Export to global scope for use by chatbot widget
  window.ChatbotCitationsManager = CitationsManager;

})();
