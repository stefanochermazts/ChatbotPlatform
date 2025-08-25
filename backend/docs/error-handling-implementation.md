# 🚨 Error Handling & Rate Limiting - Chatbot Widget

## Overview

Il chatbot widget include un sistema completo di gestione errori e rate limiting che garantisce un'esperienza utente fluida anche in presenza di problemi tecnici, limiti API o errori di rete.

## Features

### 🚨 Error Detection & Classification
- **Automatic Classification**: Rileva e classifica automaticamente gli errori
- **Severity Levels**: 4 livelli di severità (Low, Medium, High, Critical)
- **Error Types**: Network, Server, Auth, Rate Limit, Timeout, Quota, Maintenance
- **Response Analysis**: Analizza status code e headers per determinare azioni

### 🔄 Intelligent Retry Logic
- **Exponential Backoff**: Retry automatici con delay progressivo
- **Retry-After Header**: Rispetta header `Retry-After` per rate limiting
- **Max Attempts**: Configurabile per tipo di errore
- **Manual Retry**: Pulsanti per retry manuale quando appropriato

### 💬 User-Friendly Messages
- **Contextual Messages**: Messaggi specifici per ogni tipo di errore
- **Countdown Timers**: Mostra tempo rimanente per retry automatici
- **Action Buttons**: Azioni chiare e actionable per l'utente
- **Progress Indicators**: Mostra progresso retry (attempt 1/3)

### 🎨 Accessible UI
- **ARIA Support**: Screen reader announcements per errori
- **High Contrast**: Stili ottimizzati per high contrast mode
- **Keyboard Navigation**: Controlli accessibili via tastiera
- **Focus Management**: Focus appropriato su elementi interattivi

## Architecture

### File Structure
```
widget/
├── css/
│   └── chatbot-error-handling.css    # Stili error overlay e messaging
├── js/
│   └── chatbot-error-handling.js     # ChatbotErrorHandler class
└── docs/
    └── error-handling-implementation.md
```

### Error Types

```javascript
const ERROR_TYPES = {
  RATE_LIMIT: 'rate_limit',           // 429, rate limiting
  NETWORK: 'network',                 // Network failures
  SERVER: 'server',                   // 5xx errors
  AUTHENTICATION: 'authentication',   // 401, 403
  VALIDATION: 'validation',           // 400 Bad Request
  TIMEOUT: 'timeout',                 // Request timeouts
  MAINTENANCE: 'maintenance',         // 503 Service Unavailable
  QUOTA_EXCEEDED: 'quota_exceeded',   // 402, quota limits
  SERVICE_UNAVAILABLE: 'service_unavailable',
  UNKNOWN: 'unknown'
};
```

### Severity Levels

```javascript
const ERROR_SEVERITIES = {
  LOW: 'low',           // Auto-retry, minimal user impact
  MEDIUM: 'medium',     // User notification, retry suggested
  HIGH: 'high',         // Service degraded, user action needed
  CRITICAL: 'critical'  // Service down, contact support
};
```

### Retry Configurations

```javascript
const RETRY_CONFIGS = {
  network: { attempts: 3, delay: 1000, backoff: 2 },
  server: { attempts: 2, delay: 2000, backoff: 1.5 },
  timeout: { attempts: 2, delay: 1500, backoff: 2 },
  rate_limit: { attempts: 1, delay: 60000, backoff: 1 },
  default: { attempts: 1, delay: 5000, backoff: 1 }
};
```

## Implementation Details

### ChatbotErrorHandler Class

```javascript
// Inizializzazione automatica nel widget
this.errorHandler = new ChatbotErrorHandler(this);

// Gestione errore
const shouldRetry = await this.errorHandler.handleError(error, {
  action: 'send_message',
  content: originalContent,
  attempt: retryCount
}, response);
```

### Error Classification Logic

L'error handler analizza:
1. **HTTP Status Codes**: 400, 401, 403, 429, 5xx
2. **Error Messages**: Parole chiave in error.message
3. **Response Headers**: `Retry-After`, `X-RateLimit-*`
4. **Error Types**: NetworkError, TimeoutError, etc.

### Rate Limiting Detection

```javascript
// Rileva rate limiting da:
// - HTTP 429 status
// - "rate limit" in error message
// - Retry-After header
if (response?.status === 429 || error.message?.includes('rate limit')) {
  return {
    type: ERROR_TYPES.RATE_LIMIT,
    severity: ERROR_SEVERITIES.MEDIUM,
    retryAfter: this.extractRetryAfter(response)
  };
}
```

### Retry Logic

```javascript
async handleRetryableError(errorId, classification) {
  const attempts = this.incrementAttempts(errorId);
  const config = RETRY_CONFIGS[classification.type];
  
  if (attempts <= config.attempts) {
    const delay = config.delay * Math.pow(config.backoff, attempts - 1);
    // Show countdown and auto-retry
    setTimeout(() => resolve(true), delay);
  } else {
    // Max attempts reached, show manual retry option
    this.showMaxRetriesMessage(classification);
  }
}
```

## Error Messages & UI

### Message Structure

```javascript
{
  rateLimit: {
    title: '⏱️ Troppo veloce!',
    message: 'Hai raggiunto il limite di messaggi. Riprova tra {time}.',
    action: 'Attendi'
  },
  network: {
    title: '🌐 Problemi di connessione',
    message: 'Verifica la tua connessione internet. Riprovo automaticamente...',
    action: 'Riprova ora'
  }
  // ... altri tipi di errore
}
```

### Error Overlay

L'overlay appare sopra il widget con:
- **Backdrop blur** per focalizzare attenzione
- **Error content** con icona, titolo, messaggio
- **Action buttons** per retry o altre azioni
- **Countdown timer** per retry automatici
- **Progress indicator** per multi-retry

### CSS Classes

```css
.chatbot-error-overlay          # Overlay container
.chatbot-error-content          # Content card
.severity-{level}               # Severity styling
.chatbot-error-{type}           # Type-specific styling
.chatbot-error-countdown        # Countdown timer
.chatbot-error-retry-button     # Retry button
```

## Usage Examples

### Basic Integration

L'error handling si attiva automaticamente:

```html
<link rel="stylesheet" href="./css/chatbot-error-handling.css">
<script src="./js/chatbot-error-handling.js" defer></script>
```

### Manual Error Handling

```javascript
// Accesso all'error handler
const errorHandler = window.chatbotWidget.errorHandler;

// Check stato errori
const hasErrors = errorHandler.hasActiveErrors();
const errorInfo = errorHandler.getErrorInfo();

// Check maintenance mode
const isDown = errorHandler.isMaintenanceMode();

// Clear errori
errorHandler.clearAllErrors();
```

### Custom Error Messages

```javascript
// Override messaggi di errore
window.chatbotWidget.errorHandler.messages.network = {
  title: '🔄 Connessione instabile',
  message: 'Problemi di rete rilevati. Riconnessione in corso...',
  action: 'Riconnetti'
};
```

### Event Listening

```javascript
// Ascolta eventi di errore
document.addEventListener('chatbot:manual:retry', (e) => {
  console.log('Manual retry requested at:', e.detail.timestamp);
});

// Track errori per analytics
widget.analytics.trackEvent('error_occurred', {
  errorType: 'network',
  severity: 'medium',
  context: 'send_message'
});
```

## Rate Limiting

### Detection Methods

1. **HTTP 429 Status**: Standard rate limit response
2. **Retry-After Header**: Seconds or HTTP date
3. **Error Message Keywords**: "rate limit", "too many requests"
4. **Custom Headers**: `X-RateLimit-Remaining`, `X-RateLimit-Reset`

### User Experience

```javascript
// Rate limit flow:
// 1. Request fails with 429
// 2. Show countdown "Riprova tra 1m 30s"
// 3. Auto-retry after delay
// 4. Announce completion to screen readers
```

### Retry-After Processing

```javascript
extractRetryAfter(response) {
  const retryAfter = response.headers.get('Retry-After');
  
  // Seconds
  const seconds = parseInt(retryAfter);
  if (!isNaN(seconds)) return seconds * 1000;
  
  // HTTP Date
  const date = new Date(retryAfter);
  if (!isNaN(date.getTime())) {
    return Math.max(0, date.getTime() - Date.now());
  }
  
  return null;
}
```

## Accessibility Features

### Screen Reader Support

```javascript
// Announce errori
if (this.chatbot.accessibility) {
  this.chatbot.accessibility.announce(
    `Errore: ${title}. ${message}`, 
    'assertive'
  );
}
```

### ARIA Attributes

```html
<div class="chatbot-error-overlay" 
     role="alert" 
     aria-live="assertive" 
     aria-atomic="true">
  <button aria-label="Riprova invio messaggio">Riprova</button>
</div>
```

### Keyboard Navigation

- **Tab**: Naviga tra elementi interattivi
- **Enter/Space**: Attiva retry button
- **Escape**: Chiude errore (se dismissible)

### High Contrast & Forced Colors

```css
@media (forced-colors: active) {
  .chatbot-error-content {
    background: Canvas !important;
    border: 2px solid ButtonText !important;
  }
  
  .chatbot-error-retry-button {
    background: ButtonFace !important;
    color: ButtonText !important;
  }
}
```

## Error Analytics

### Tracked Metrics

```javascript
// Eventi tracciati automaticamente
analytics.trackEvent('error_occurred', {
  errorType: 'rate_limit',
  severity: 'medium', 
  statusCode: 429,
  context: 'send_message',
  retryable: true,
  timestamp: Date.now()
});

analytics.trackEvent('manual_retry', {
  errorCount: 2,
  previousErrors: ['network', 'timeout']
});
```

### Dashboard Integration

Gli errori sono tracciati nel sistema di analytics:
- **Error Rate**: Percentuale di messaggi con errore
- **Error Types**: Distribuzione per tipo di errore
- **Recovery Time**: Tempo medio per recovery
- **Retry Success**: Tasso di successo dei retry

## Testing

### Error Simulation

```javascript
// Simula network error
navigator.serviceWorker.ready.then(registration => {
  registration.active.postMessage({
    command: 'simulate-error',
    type: 'network'
  });
});

// Simula rate limiting
fetch('/api/simulate-rate-limit', { method: 'POST' });
```

### Manual Testing Checklist

1. **Network Errors**:
   - [ ] Disconnect internet durante invio
   - [ ] Verifica auto-retry con backoff
   - [ ] Test manual retry button

2. **Rate Limiting**:
   - [ ] Invia molti messaggi velocemente  
   - [ ] Verifica countdown timer
   - [ ] Verifica auto-retry dopo delay

3. **Server Errors**:
   - [ ] Simula 500/502/503 errors
   - [ ] Verifica retry attempts
   - [ ] Test max retries reached

4. **Authentication**:
   - [ ] Usa API key non valida
   - [ ] Verifica messaggio "contatta admin"
   - [ ] Verifica nessun auto-retry

5. **Accessibility**:
   - [ ] Screen reader announces
   - [ ] Keyboard navigation
   - [ ] High contrast mode
   - [ ] Focus management

### Automated Testing

```javascript
// Jest/Cypress tests
describe('Error Handling', () => {
  it('should handle rate limiting with countdown', async () => {
    // Mock 429 response
    cy.intercept('POST', '/api/v1/chat/completions', {
      statusCode: 429,
      headers: { 'Retry-After': '60' }
    });
    
    cy.sendMessage('test');
    cy.get('.chatbot-error-countdown').should('contain', '60s');
  });
});
```

## Performance Considerations

### Memory Management

```javascript
// Cleanup errori vecchi
setInterval(() => {
  const cutoff = Date.now() - (5 * 60 * 1000); // 5 minuti
  for (const [id, error] of this.currentErrors) {
    if (error.timestamp < cutoff) {
      this.clearError(id);
    }
  }
}, 60000);
```

### Event Cleanup

```javascript
destroy() {
  this.clearAllErrors();
  this.errorContainer?.remove();
  // Remove event listeners
  document.removeEventListener('chatbot:manual:retry', this.retryHandler);
}
```

## Browser Support

### Core Features
- **Modern Browsers**: Chrome 76+, Firefox 67+, Safari 12+, Edge 79+
- **Error Classification**: Supportato ovunque
- **Retry Logic**: Supportato ovunque
- **UI Features**: Graceful degradation

### Fallbacks
- **No Fetch API**: XMLHttpRequest fallback
- **No AbortController**: Timeout fallback
- **Old Browsers**: Basic error messages without animations

## Troubleshooting

### Common Issues

**Errori non gestiti**:
```javascript
// Verifica inizializzazione
console.log(window.chatbotWidget.errorHandler);

// Verifica file CSS/JS caricati
console.log(window.ChatbotErrorHandler);
```

**Retry non funziona**:
```javascript
// Check retry configuration
console.log(errorHandler.retryAttempts);
console.log(errorHandler.currentErrors);
```

**UI non appare**:
```css
/* Verifica z-index */
.chatbot-error-overlay {
  z-index: 1000 !important;
}
```

### Debug Utils

```javascript
// Debug info
const errorHandler = window.chatbotWidget.errorHandler;
console.table({
  'Active Errors': errorHandler.currentErrors.size,
  'Maintenance Mode': errorHandler.isMaintenanceMode(),
  'Retry Attempts': errorHandler.retryAttempts.size
});

// Force error for testing
errorHandler.handleError(new Error('Test error'), {
  action: 'test',
  type: 'network'
});
```

---

**Note**: Questa implementazione rispetta le best practices per user experience, accessibilità e performance, garantendo che gli utenti ricevano sempre feedback chiaro e actionable anche in presenza di errori.
