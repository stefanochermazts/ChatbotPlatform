/**
 * ChatBot Quick Actions Manager
 * Handles quick action buttons and execution in the widget
 */
class ChatbotQuickActions {
    constructor(widget) {
        this.widget = widget;
        this.actions = [];
        this.container = null;
        this.isLoading = false;
        
        this.init();
    }
    
    async init() {
        // Check if widget is properly configured
        if (!this.widget.options || !this.widget.options.apiKey) {
            console.warn('QuickActions: Widget not properly configured, skipping initialization');
            return;
        }
        
        try {
            await this.loadActions();
            this.createUI();
            this.setupEventHandlers();
        } catch (error) {
            console.error('Failed to initialize quick actions:', error);
        }
    }
    
    async loadActions() {
        try {
                    const response = await fetch(`${this.widget.options.baseURL}/api/v1/quick-actions/`, {
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${this.widget.options.apiKey}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.actions = data.actions || [];
                console.log(`Loaded ${this.actions.length} quick actions`);
            } else {
                throw new Error(data.message || 'Failed to load actions');
            }
            
        } catch (error) {
            console.error('Error loading quick actions:', error);
            this.actions = [];
        }
    }
    
    createUI() {
        if (this.actions.length === 0) {
            return;
        }
        
        // Create quick actions container
        this.container = document.createElement('div');
        this.container.className = 'chatbot-quick-actions';
        this.container.setAttribute('role', 'toolbar');
        this.container.setAttribute('aria-label', 'Azioni rapide');
        
        // Create header
        const header = document.createElement('div');
        header.className = 'quick-actions-header';
        header.innerHTML = `
            <span class="header-text">🚀 Azioni Rapide</span>
            <button class="toggle-btn" aria-label="Mostra/Nascondi azioni rapide">
                <span class="toggle-icon">▼</span>
            </button>
        `;
        
        // Create actions list
        const actionsList = document.createElement('div');
        actionsList.className = 'quick-actions-list';
        
        this.actions.forEach((action, index) => {
            const actionBtn = this.createActionButton(action, index);
            actionsList.appendChild(actionBtn);
        });
        
        this.container.appendChild(header);
        this.container.appendChild(actionsList);
        
        // Insert into widget (before input area)
        const inputArea = this.widget.container.querySelector('.chatbot-input-area');
        if (inputArea) {
            inputArea.parentNode.insertBefore(this.container, inputArea);
        }
    }
    
    createActionButton(action, index) {
        const button = document.createElement('button');
        button.className = `quick-action-btn ${action.style || 'primary'}`;
        button.setAttribute('data-action-id', action.id);
        button.setAttribute('data-action-type', action.type);
        button.setAttribute('aria-label', action.description || action.label);
        button.setAttribute('tabindex', '0');
        
        button.innerHTML = `
            <span class="action-icon">${action.icon || '⚡'}</span>
            <span class="action-label">${action.label}</span>
            <span class="action-loading" style="display: none;">⏳</span>
        `;
        
        return button;
    }
    
    setupEventHandlers() {
        if (!this.container) return;
        
        // Toggle quick actions visibility
        const toggleBtn = this.container.querySelector('.toggle-btn');
        const actionsList = this.container.querySelector('.quick-actions-list');
        
        if (toggleBtn && actionsList) {
            toggleBtn.addEventListener('click', () => {
                const isVisible = actionsList.style.display !== 'none';
                actionsList.style.display = isVisible ? 'none' : 'block';
                const icon = toggleBtn.querySelector('.toggle-icon');
                icon.textContent = isVisible ? '▶' : '▼';
                
                // Update aria-expanded
                toggleBtn.setAttribute('aria-expanded', !isVisible);
            });
        }
        
        // Handle action button clicks
        this.container.addEventListener('click', (e) => {
            const actionBtn = e.target.closest('.quick-action-btn');
            if (actionBtn && !this.isLoading) {
                e.preventDefault();
                this.handleActionClick(actionBtn);
            }
        });
        
        // Keyboard support
        this.container.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                const actionBtn = e.target.closest('.quick-action-btn');
                if (actionBtn && !this.isLoading) {
                    e.preventDefault();
                    this.handleActionClick(actionBtn);
                }
            }
        });
    }
    
    async handleActionClick(button) {
        const actionId = parseInt(button.getAttribute('data-action-id'));
        const actionType = button.getAttribute('data-action-type');
        
        const action = this.actions.find(a => a.id === actionId);
        if (!action) {
            console.error('Action not found:', actionId);
            return;
        }
        
        // Show confirmation if required
        if (action.confirmation) {
            if (!confirm(action.confirmation)) {
                return;
            }
        }
        
        // Check required fields and show form if needed
        if (action.required_fields && action.required_fields.length > 0) {
            this.showActionForm(action, button);
        } else {
            this.executeAction(action, {}, button);
        }
    }
    
    showActionForm(action, button) {
        // Create a simple modal form
        const modal = document.createElement('div');
        modal.className = 'quick-action-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>${action.label}</h3>
                    <button class="modal-close" aria-label="Chiudi">&times;</button>
                </div>
                <div class="modal-body">
                    <form class="action-form">
                        ${action.required_fields.map(field => `
                            <div class="form-group">
                                <label for="field-${field.name}">${field.label}${field.required ? ' *' : ''}</label>
                                <input 
                                    type="${this.getInputType(field.name)}" 
                                    id="field-${field.name}"
                                    name="${field.name}"
                                    ${field.required ? 'required' : ''}
                                    placeholder="Inserisci ${field.label.toLowerCase()}"
                                >
                            </div>
                        `).join('')}
                        <div class="form-actions">
                            <button type="button" class="btn-cancel">Annulla</button>
                            <button type="submit" class="btn-submit">Invia</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Handle form submission
        const form = modal.querySelector('.action-form');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            
            const formData = new FormData(form);
            const actionData = {};
            for (const [key, value] of formData.entries()) {
                actionData[key] = value;
            }
            
            document.body.removeChild(modal);
            this.executeAction(action, actionData, button);
        });
        
        // Handle cancel/close
        const closeButtons = modal.querySelectorAll('.modal-close, .btn-cancel');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                document.body.removeChild(modal);
            });
        });
        
        // Focus first input
        const firstInput = modal.querySelector('input');
        if (firstInput) {
            firstInput.focus();
        }
    }
    
    getInputType(fieldName) {
        switch (fieldName) {
            case 'email':
                return 'email';
            case 'phone':
                return 'tel';
            case 'message':
                return 'textarea';
            default:
                return 'text';
        }
    }
    
    async executeAction(action, actionData, button) {
        this.setButtonLoading(button, true);
        
        try {
            const response = await fetch(`${this.widget.options.baseURL}/api/v1/quick-actions/execute`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.widget.options.apiKey}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    action_id: action.id,
                    action_data: actionData,
                    session_id: this.widget.sessionId || 'unknown'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.handleActionSuccess(action, result, button);
            } else {
                this.handleActionError(action, result.message || 'Action failed', button);
            }
            
        } catch (error) {
            console.error('Action execution error:', error);
            this.handleActionError(action, 'Network error occurred', button);
        } finally {
            this.setButtonLoading(button, false);
        }
    }
    
    handleActionSuccess(action, result, button) {
        // Show success message in chat
        if (result.message) {
            this.widget.ui.addBotMessage(result.message, null, true);
        }
        
        // Handle specific action results
        if (result.result && result.result.download_url) {
            // Open download link
            window.open(result.result.download_url, '_blank');
        }
        
        // Track analytics
        if (this.widget.analytics) {
            this.widget.analytics.trackEvent('quick_action_executed', {
                action_type: action.type,
                action_id: action.id,
                execution_id: result.execution_id,
                success: true
            });
        }
        
        // Visual feedback
        this.showButtonFeedback(button, 'success');
    }
    
    handleActionError(action, message, button) {
        // Show error message in chat
        this.widget.ui.addBotMessage(`❌ ${message}`, null, true);
        
        // Track analytics
        if (this.widget.analytics) {
            this.widget.analytics.trackEvent('quick_action_failed', {
                action_type: action.type,
                action_id: action.id,
                error: message,
                success: false
            });
        }
        
        // Visual feedback
        this.showButtonFeedback(button, 'error');
    }
    
    setButtonLoading(button, loading) {
        this.isLoading = loading;
        const icon = button.querySelector('.action-icon');
        const loadingSpinner = button.querySelector('.action-loading');
        
        if (loading) {
            icon.style.display = 'none';
            loadingSpinner.style.display = 'inline';
            button.disabled = true;
        } else {
            icon.style.display = 'inline';
            loadingSpinner.style.display = 'none';
            button.disabled = false;
        }
    }
    
    showButtonFeedback(button, type) {
        const originalClass = button.className;
        button.classList.add(`feedback-${type}`);
        
        setTimeout(() => {
            button.className = originalClass;
        }, 2000);
    }
    
    // Public methods
    refresh() {
        if (this.container) {
            this.container.remove();
            this.container = null;
        }
        this.init();
    }
    
    hide() {
        if (this.container) {
            this.container.style.display = 'none';
        }
    }
    
    show() {
        if (this.container) {
            this.container.style.display = 'block';
        }
    }
}

// Export for use in main widget
window.ChatbotQuickActions = ChatbotQuickActions;
