/**
 * 📝 ChatbotFormRenderer - Sistema rendering form dinamici
 * Gestisce visualizzazione, validazione e submit di form configurabili
 */
class ChatbotFormRenderer {
    constructor(chatbotWidget) {
        this.widget = chatbotWidget;
        this.currentForm = null;
        this.currentFormData = {};
        this.validationErrors = {};
        
        this.bindEvents();
        
        console.log('📝 ChatbotFormRenderer initialized');
    }

    /**
     * Mostra un form nel chatbot
     * @param {Object} formConfig - Configurazione form dal trigger
     */
    showForm(formConfig) {
        console.log('📝 Showing form:', formConfig.form_name);
        
        this.currentForm = formConfig;
        this.currentFormData = {};
        this.validationErrors = {};

        // Assicurati che non ci sia il thinking state
        if (this.widget.ui && this.widget.ui.hideTyping) {
            this.widget.ui.hideTyping();
        }

        // Crea e aggiungi il form direttamente (senza messaggio bot introduttivo)
        const formElement = this.createFormElement(formConfig);
        this.widget.addCustomMessage(formElement);
        
        // Scroll al form
        this.widget.scrollToBottom();
        
        // Focus primo campo
        setTimeout(() => {
            const firstInput = formElement.querySelector('input, select, textarea');
            if (firstInput) {
                firstInput.focus();
            }
        }, 300);
    }

    /**
     * Crea l'elemento HTML del form
     * @param {Object} formConfig - Configurazione form
     * @returns {HTMLElement}
     */
    createFormElement(formConfig) {
        const formContainer = document.createElement('div');
        formContainer.className = 'chatbot-form-container';
        formContainer.setAttribute('data-form-id', formConfig.form_id);

        let html = `
            <div class="chatbot-form">
                <div class="chatbot-form-header">
                    ${formConfig.message ? `<div class="chatbot-form-intro">${this.escapeHtml(formConfig.message)}</div>` : ''}
                    <h3 class="chatbot-form-title">${this.escapeHtml(formConfig.form_name)}</h3>
                    ${formConfig.form_description ? `<p class="chatbot-form-description">${this.escapeHtml(formConfig.form_description)}</p>` : ''}
                </div>
                
                <div class="chatbot-form-body">
                    <form class="chatbot-dynamic-form" onsubmit="return false;">
        `;

        // Genera campi del form
        formConfig.fields.forEach(field => {
            html += this.renderField(field);
        });

        html += `
                        <div class="chatbot-form-actions">
                            <button type="button" class="chatbot-form-cancel" onclick="chatbotFormRenderer.cancelForm()">
                                ❌ Annulla
                            </button>
                            <button type="button" class="chatbot-form-submit" onclick="chatbotFormRenderer.submitForm()">
                                📤 Invia Richiesta
                            </button>
                        </div>
                        
                        <div class="chatbot-form-errors" style="display: none;"></div>
                        <div class="chatbot-form-loading" style="display: none;">
                            <div class="chatbot-form-spinner"></div>
                            <span>Invio in corso...</span>
                        </div>
                    </form>
                </div>
            </div>
        `;

        formContainer.innerHTML = html;
        return formContainer;
    }

    /**
     * Renderizza un singolo campo del form
     * @param {Object} field - Configurazione campo
     * @returns {string} HTML del campo
     */
    renderField(field) {
        const fieldId = `chatbot_field_${field.name}`;
        const required = field.required ? ' required' : '';
        const requiredMark = field.required ? ' <span class="chatbot-form-required">*</span>' : '';

        let html = `
            <div class="chatbot-form-field" data-field-name="${field.name}" data-field-type="${field.type}">
                <label for="${fieldId}" class="chatbot-form-label">
                    ${this.escapeHtml(field.label)}${requiredMark}
                </label>
        `;

        switch (field.type) {
            case 'textarea':
                html += `
                    <textarea 
                        id="${fieldId}" 
                        name="${field.name}" 
                        placeholder="${this.escapeHtml(field.placeholder || '')}"
                        ${required}
                        class="chatbot-form-input chatbot-form-textarea"
                        rows="3"
                        onchange="chatbotFormRenderer.updateFieldValue('${field.name}', this.value)"
                    ></textarea>
                `;
                break;

            case 'select':
                html += `<select id="${fieldId}" name="${field.name}" ${required} class="chatbot-form-input chatbot-form-select" onchange="chatbotFormRenderer.updateFieldValue('${field.name}', this.value)">`;
                html += '<option value="">Seleziona...</option>';
                if (field.options) {
                    Object.entries(field.options).forEach(([value, label]) => {
                        html += `<option value="${this.escapeHtml(value)}">${this.escapeHtml(label)}</option>`;
                    });
                }
                html += '</select>';
                break;

            case 'checkbox':
                if (field.options) {
                    html += '<div class="chatbot-form-checkbox-group">';
                    Object.entries(field.options).forEach(([value, label]) => {
                        html += `
                            <label class="chatbot-form-checkbox-item">
                                <input 
                                    type="checkbox" 
                                    name="${field.name}[]" 
                                    value="${this.escapeHtml(value)}"
                                    onchange="chatbotFormRenderer.updateCheckboxValue('${field.name}')"
                                />
                                <span class="chatbot-form-checkbox-label">${this.escapeHtml(label)}</span>
                            </label>
                        `;
                    });
                    html += '</div>';
                }
                break;

            case 'radio':
                if (field.options) {
                    html += '<div class="chatbot-form-radio-group">';
                    Object.entries(field.options).forEach(([value, label]) => {
                        html += `
                            <label class="chatbot-form-radio-item">
                                <input 
                                    type="radio" 
                                    name="${field.name}" 
                                    value="${this.escapeHtml(value)}"
                                    onchange="chatbotFormRenderer.updateFieldValue('${field.name}', this.value)"
                                />
                                <span class="chatbot-form-radio-label">${this.escapeHtml(label)}</span>
                            </label>
                        `;
                    });
                    html += '</div>';
                }
                break;

            default:
                const inputType = this.getInputType(field.type);
                html += `
                    <input 
                        type="${inputType}" 
                        id="${fieldId}" 
                        name="${field.name}" 
                        placeholder="${this.escapeHtml(field.placeholder || '')}"
                        ${required}
                        class="chatbot-form-input"
                        onchange="chatbotFormRenderer.updateFieldValue('${field.name}', this.value)"
                        oninput="chatbotFormRenderer.clearFieldError('${field.name}')"
                    />
                `;
                break;
        }

        // Help text
        if (field.help_text) {
            html += `<div class="chatbot-form-help">${this.escapeHtml(field.help_text)}</div>`;
        }

        // Error container
        html += `<div class="chatbot-form-field-error" data-field="${field.name}" style="display: none;"></div>`;

        html += '</div>';
        return html;
    }

    /**
     * Aggiorna valore di un campo
     */
    updateFieldValue(fieldName, value) {
        this.currentFormData[fieldName] = value;
        this.clearFieldError(fieldName);
        console.log('📝 Field updated:', fieldName, '=', value);
    }

    /**
     * Aggiorna valore checkbox (multiple values)
     */
    updateCheckboxValue(fieldName) {
        const checkboxes = document.querySelectorAll(`input[name="${fieldName}[]"]:checked`);
        const values = Array.from(checkboxes).map(cb => cb.value);
        this.currentFormData[fieldName] = values;
        this.clearFieldError(fieldName);
        console.log('📝 Checkbox updated:', fieldName, '=', values);
    }

    /**
     * Ottieni tipo input HTML per tipo campo
     */
    getInputType(fieldType) {
        const typeMap = {
            'email': 'email',
            'phone': 'tel',
            'date': 'date',
            'number': 'number',
            'text': 'text'
        };
        return typeMap[fieldType] || 'text';
    }

    /**
     * Validazione lato client
     */
    validateForm() {
        this.validationErrors = {};
        let isValid = true;

        this.currentForm.fields.forEach(field => {
            const value = this.currentFormData[field.name];
            const errors = this.validateField(field, value);
            
            if (errors.length > 0) {
                this.validationErrors[field.name] = errors;
                isValid = false;
            }
        });

        return isValid;
    }

    /**
     * Valida un singolo campo
     */
    validateField(field, value) {
        const errors = [];

        // Required validation
        if (field.required && (!value || (Array.isArray(value) && value.length === 0) || value.toString().trim() === '')) {
            errors.push(`Il campo ${field.label} è obbligatorio`);
            return errors; // Early return per required
        }

        // Solo validare il resto se il campo ha valore
        if (!value || value.toString().trim() === '') {
            return errors;
        }

        // Type-specific validation
        switch (field.type) {
            case 'email':
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    errors.push(`${field.label} deve essere un indirizzo email valido`);
                }
                break;

            case 'phone':
                const phoneRegex = /^[\+]?[0-9\s\-\(\)]+$/;
                if (!phoneRegex.test(value)) {
                    errors.push(`${field.label} deve essere un numero di telefono valido`);
                }
                break;

            case 'number':
                if (isNaN(value)) {
                    errors.push(`${field.label} deve essere un numero`);
                }
                break;

            case 'date':
                if (new Date(value).toString() === 'Invalid Date') {
                    errors.push(`${field.label} deve essere una data valida`);
                }
                break;
        }

        return errors;
    }

    /**
     * Mostra errori di validazione
     */
    showValidationErrors() {
        // Nascondi tutti gli errori esistenti
        document.querySelectorAll('.chatbot-form-field-error').forEach(el => {
            el.style.display = 'none';
        });

        // Mostra nuovi errori
        Object.entries(this.validationErrors).forEach(([fieldName, errors]) => {
            const errorEl = document.querySelector(`.chatbot-form-field-error[data-field="${fieldName}"]`);
            if (errorEl && errors.length > 0) {
                errorEl.innerHTML = errors.map(error => `<div>${error}</div>`).join('');
                errorEl.style.display = 'block';
                
                // Aggiungi classe error al campo
                const fieldContainer = document.querySelector(`.chatbot-form-field[data-field-name="${fieldName}"]`);
                if (fieldContainer) {
                    fieldContainer.classList.add('chatbot-form-field-error-state');
                }
            }
        });
    }

    /**
     * Pulisci errore di un campo specifico
     */
    clearFieldError(fieldName) {
        const errorEl = document.querySelector(`.chatbot-form-field-error[data-field="${fieldName}"]`);
        if (errorEl) {
            errorEl.style.display = 'none';
        }

        const fieldContainer = document.querySelector(`.chatbot-form-field[data-field-name="${fieldName}"]`);
        if (fieldContainer) {
            fieldContainer.classList.remove('chatbot-form-field-error-state');
        }

        if (this.validationErrors[fieldName]) {
            delete this.validationErrors[fieldName];
        }
    }

    /**
     * Submit del form
     */
    async submitForm() {
        console.log('📝 Submitting form...', this.currentFormData);

        // Mostra loading
        this.showFormLoading(true);

        // Validazione
        if (!this.validateForm()) {
            this.showValidationErrors();
            this.showFormLoading(false);
            this.showFormErrors(['Correggi gli errori evidenziati e riprova']);
            return;
        }

        try {
            // Prepara dati per API
            const submissionData = {
                form_id: this.currentForm.form_id,
                session_id: this.widget.sessionId,
                form_data: this.currentFormData,
                chat_context: this.widget.getConversationHistory(),
                trigger_type: this.currentForm.trigger_type,
                trigger_value: this.currentForm.trigger_value,
                ip_address: await this.getUserIP(),
                user_agent: navigator.userAgent
            };

            // Invia al server
            const response = await this.submitToServer(submissionData);

            if (response.success) {
                this.showFormSuccess(response);
                
                // Notifica widget del successo
                if (this.widget.onFormSubmitted) {
                    this.widget.onFormSubmitted(response);
                }
            } else {
                this.showFormErrors(response.errors || [response.message]);
                
                // Notifica widget dell'errore
                if (this.widget.onFormError) {
                    this.widget.onFormError(new Error(response.message || 'Form submission failed'));
                }
            }

        } catch (error) {
            console.error('📝 Form submission error:', error);
            
            let errorMessage = 'Errore di connessione. Riprova più tardi.';
            
            // Messaggi di errore più specifici
            if (error.message.includes('404')) {
                errorMessage = 'Servizio non disponibile. Contatta il supporto.';
            } else if (error.message.includes('422')) {
                errorMessage = 'Alcuni dati inseriti non sono validi. Controlla i campi.';
            } else if (error.message.includes('500')) {
                errorMessage = 'Errore interno del server. Riprova tra qualche minuto.';
            } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
                errorMessage = 'Errore di rete. Controlla la connessione internet.';
            }
            
            this.showFormErrors([errorMessage]);
            
            // Notifica widget dell'errore
            if (this.widget.onFormError) {
                this.widget.onFormError(error);
            }
        } finally {
            this.showFormLoading(false);
        }
    }

    /**
     * Invia dati al server
     */
    async submitToServer(data) {
        // Costruisci URL base dal widget
        const baseUrl = this.widget.options.baseUrl || window.location.origin;
        const apiUrl = `${baseUrl}/api/v1/forms/submit`;
        
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${this.widget.options.apiKey}`,
                'Accept': 'application/json',
                'X-Requested-With': 'ChatbotWidget'
            },
            body: JSON.stringify(data)
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
    }

    /**
     * Mostra stato loading
     */
    showFormLoading(show) {
        const loadingEl = document.querySelector('.chatbot-form-loading');
        const submitBtn = document.querySelector('.chatbot-form-submit');
        
        if (loadingEl) {
            loadingEl.style.display = show ? 'flex' : 'none';
        }
        
        if (submitBtn) {
            submitBtn.disabled = show;
            submitBtn.style.opacity = show ? '0.6' : '1';
        }
    }

    /**
     * Mostra messaggio di successo
     */
    showFormSuccess(response) {
        const formContainer = document.querySelector('.chatbot-form-container');
        if (formContainer) {
            formContainer.innerHTML = `
                <div class="chatbot-form-success">
                    <div class="chatbot-form-success-icon">✅</div>
                    <h3>Richiesta Inviata!</h3>
                    <p>${response.message}</p>
                    ${response.confirmation_email_sent ? '<p class="chatbot-form-email-sent">📧 Email di conferma inviata</p>' : ''}
                </div>
            `;
        }

        // Aggiungi messaggio bot di conferma
        this.widget.ui.addBotMessage(response.message);
        
        // Reset form corrente
        this.currentForm = null;
        this.currentFormData = {};
        
        console.log('📝 Form submitted successfully:', response);
    }

    /**
     * Mostra errori del form
     */
    showFormErrors(errors) {
        const errorsEl = document.querySelector('.chatbot-form-errors');
        if (errorsEl) {
            errorsEl.innerHTML = errors.map(error => `<div class="chatbot-form-error-item">❌ ${error}</div>`).join('');
            errorsEl.style.display = 'block';
        }
    }

    /**
     * Annulla form
     */
    cancelForm() {
        const formContainer = document.querySelector('.chatbot-form-container');
        if (formContainer) {
            formContainer.remove();
        }

        this.widget.ui.addBotMessage('Form annullato. Come posso aiutarti diversamente?');
        
        // Notifica widget della cancellazione
        if (this.widget.onFormCancelled) {
            this.widget.onFormCancelled();
        }
        
        // Reset
        this.currentForm = null;
        this.currentFormData = {};
        
        console.log('📝 Form cancelled');
    }

    /**
     * Ottieni IP utente (per analytics)
     */
    async getUserIP() {
        try {
            const response = await fetch('https://api.ipify.org?format=json');
            const data = await response.json();
            return data.ip;
        } catch (error) {
            console.warn('📝 Could not get user IP:', error);
            return null;
        }
    }

    /**
     * Bind eventi globali
     */
    bindEvents() {
        // Gestione Enter nei campi input
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.closest('.chatbot-form')) {
                e.preventDefault();
                if (e.target.tagName !== 'TEXTAREA') {
                    this.submitForm();
                }
            }
        });
    }

    /**
     * Escape HTML per sicurezza
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Verifica se c'è un form attivo
     */
    hasActiveForm() {
        return this.currentForm !== null;
    }

    /**
     * Chiudi form attivo (se presente)
     */
    closeActiveForm() {
        if (this.hasActiveForm()) {
            this.cancelForm();
        }
    }
}

// Rendi disponibile globalmente
window.ChatbotFormRenderer = ChatbotFormRenderer;
