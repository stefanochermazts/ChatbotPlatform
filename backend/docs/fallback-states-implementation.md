# 🛡️ ChatBot Widget - Fallback States Implementation

## Overview

Il sistema di Fallback States garantisce un'esperienza utente robusta e professionale anche quando si verificano problemi tecnici, interruzioni di rete, o limitazioni del servizio.

## 🎯 Obiettivi

- **Graceful Degradation**: Il widget continua a funzionare anche in condizioni avverse
- **User Experience**: Messaggi chiari e azioni utili per ogni situazione di errore
- **Resilience**: Recupero automatico e retry intelligenti
- **Transparency**: Comunicazione trasparente sui problemi temporanei

## 🏗️ Architettura

### Componenti Principali

```
ChatbotFallbackManager
├── Network Monitoring        # Rileva connessione/disconnessione
├── Error Classification     # Categorizza gli errori
├── State Management         # Gestisce stati di fallback
├── Retry Mechanism          # Retry automatici con backoff
├── Offline Queue           # Coda messaggi offline
└── UI Components           # Interfaccia stati di fallback
```

### File Struttura

```
backend/public/widget/
├── js/
│   └── chatbot-fallback-manager.js    # Logica principale
├── css/
│   └── chatbot-fallback-states.css    # Stili UI stati
└── docs/
    └── fallback-states-implementation.md  # Questa documentazione
```

## 🔄 Stati di Fallback

### 1. Offline State

**Trigger**: Perdita di connessione di rete

**Comportamenti**:
- Monitoring automatico tramite `navigator.onLine`
- Check periodici di connettività ogni 30 secondi
- Coda automatica dei messaggi per invio differito
- Ripristino automatico al ritorno online

**UI**:
- Icona: 📶
- Messaggio: "Connessione non disponibile"
- Azioni: [Riprova, Modalità offline]

### 2. Maintenance Mode

**Trigger**: HTTP 503 o configurazione esplicita

**Comportamenti**:
- Blocco temporaneo delle nuove richieste
- Retry automatico dopo tempo specificato
- Possibilità di notifica proattiva

**UI**:
- Icona: 🔧
- Messaggio: "Manutenzione in corso"
- Azioni: [Riprova tra 1 min]

### 3. Rate Limited

**Trigger**: HTTP 429 con header `Retry-After`

**Comportamenti**:
- Parsing automatico del tempo di attesa
- Countdown visuale per l'utente
- Abilitazione automatica dopo scadenza

**UI**:
- Icona: ⏰
- Messaggio: "Troppi messaggi. Riprova tra {X} secondi"
- Azioni: [Riprova più tardi]

### 4. Server Error

**Trigger**: HTTP 5xx, errori di rete generici

**Comportamenti**:
- Retry automatico con exponential backoff
- Logging dell'errore per debugging
- Escalation a supporto se persiste

**UI**:
- Icona: ⚠️
- Messaggio: "Errore del servizio temporaneo"
- Azioni: [Riprova, Contatta supporto]

### 5. Authentication Error

**Trigger**: HTTP 401, 403

**Comportamenti**:
- Nessun retry automatico
- Suggerimento di contattare amministratore
- Possibile refresh delle credenziali

**UI**:
- Icona: 🔑
- Messaggio: "Problema di configurazione"
- Azioni: [Contatta supporto]

### 6. Timeout

**Trigger**: Richieste che superano timeout configurato

**Comportamenti**:
- Opzione di estendere il timeout
- Retry con timeout incrementale
- Cancellazione graceful

**UI**:
- Icona: ⏱️
- Messaggio: "Richiesta in attesa"
- Azioni: [Continua ad aspettare, Riprova]

### 7. Degraded Mode

**Trigger**: Fallimenti parziali dei servizi

**Comportamenti**:
- Disabilitazione funzioni non critiche
- Mantenimento funzionalità core
- Notifica delle limitazioni

**UI**:
- Icona: ⚡
- Messaggio: "Modalità ridotta attivata"
- Azioni: [Continua]

## 🔧 Implementazione Tecnica

### Inizializzazione

```javascript
// Nel costruttore del ChatbotWidget
this.fallbackManager = null;
if (window.ChatbotFallbackManager) {
    this.fallbackManager = new window.ChatbotFallbackManager(this);
}
```

### Network Monitoring

```javascript
// Monitoring eventi browser
window.addEventListener('online', () => this.handleOnline());
window.addEventListener('offline', () => this.handleOffline());

// Check periodici di connettività
setInterval(() => this.checkConnectivity(), 30000);
```

### Error Classification

```javascript
handleError(error) {
    switch (error.type) {
        case 'network': return this.handleNetworkError(error);
        case 'api': return this.handleApiError(error);
        case 'timeout': return this.showFallbackState('timeout');
        case 'rate_limit': return this.handleRateLimit(error);
        default: return this.showFallbackState('serverError');
    }
}
```

### Retry Mechanism

```javascript
// Exponential backoff delays
retryDelays = [1000, 2000, 5000, 10000]; // 1s, 2s, 5s, 10s

retryLastAction() {
    if (this.state.retryCount < this.state.maxRetries) {
        const delay = this.retryDelays[this.state.retryCount];
        setTimeout(() => this.widget.lastFailedAction.retry(), delay);
    }
}
```

### Offline Queue

```javascript
queueMessage(message) {
    if (this.state.isOffline) {
        this.offlineQueue.push({
            content: message,
            timestamp: Date.now(),
            id: 'msg_' + Date.now()
        });
        return true; // Message queued
    }
    return false; // Send normally
}
```

## 🎨 Personalizzazione UI

### CSS Variables

```css
:root {
    --fallback-offline-bg: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    --fallback-error-bg: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    --fallback-maintenance-bg: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    --fallback-border-radius: 8px;
    --fallback-animation-duration: 0.3s;
}
```

### Responsive Design

```css
@media (max-width: 640px) {
    .fallback-actions {
        flex-direction: column;
        width: 100%;
    }
    .fallback-action-btn {
        width: 100%;
    }
}
```

### Dark Mode Support

```css
@media (prefers-color-scheme: dark) {
    .fallback-offline {
        background: linear-gradient(135deg, #451a03 0%, #78350f 100%);
        color: #fbbf24;
    }
}
```

## 📊 Configurazione

### Parametri Configurabili

```javascript
const fallbackConfig = {
    maxRetries: 3,
    retryDelays: [1000, 2000, 5000, 10000],
    connectivityCheckInterval: 30000,
    offlineQueueEnabled: true,
    fallbackMessagesEnabled: true,
    autoRecoveryEnabled: true
};
```

### Messaggi Personalizzabili

```javascript
fallbackMessages: {
    offline: {
        title: 'Connessione non disponibile',
        message: 'Personalizza questo messaggio per il tuo brand',
        icon: '📶',
        actions: [
            { label: 'Riprova', action: 'retry' },
            { label: 'Modalità offline', action: 'offline_mode' }
        ]
    }
    // ... altri stati
}
```

## 📈 Analytics e Monitoraggio

### Eventi Tracciati

```javascript
// Connessione persa/ripristinata
this.widget.analytics.trackEvent('connection_lost', {
    last_successful_request: timestamp
});

this.widget.analytics.trackEvent('connection_restored', {
    offline_duration: duration,
    queued_messages: count
});

// Stati di fallback attivati
this.widget.analytics.trackEvent('fallback_state_shown', {
    type: 'offline',
    error_context: error
});

// Azioni utente sui fallback
this.widget.analytics.trackEvent('fallback_action_taken', {
    action: 'retry',
    fallback_type: 'offline'
});
```

### Metriche da Monitorare

- **Offline Duration**: Tempo medio offline per sessione
- **Retry Success Rate**: Percentuale di retry riusciti
- **Queue Processing**: Tempo di elaborazione messaggi in coda
- **Fallback Activation**: Frequenza attivazione stati
- **User Actions**: Azioni più utilizzate nei fallback

## 🧪 Testing

### Test Scenari

```javascript
// Simulare offline
navigator.serviceWorker.controller.postMessage({
    type: 'SIMULATE_OFFLINE'
});

// Simulare errori server
fetch.mockRejectedValue(new Error('Server Error'));

// Simulare rate limiting
fetch.mockResolvedValue({
    status: 429,
    headers: { get: () => '60' }
});
```

### Unit Tests

```javascript
describe('FallbackManager', () => {
    test('should queue messages when offline', () => {
        fallbackManager.handleOffline();
        const queued = fallbackManager.queueMessage('test');
        expect(queued).toBe(true);
        expect(fallbackManager.offlineQueue).toHaveLength(1);
    });
    
    test('should retry with exponential backoff', async () => {
        await fallbackManager.retryLastAction();
        expect(setTimeout).toHaveBeenCalledWith(
            expect.any(Function), 
            1000
        );
    });
});
```

## 🚀 Best Practices

### Error Handling

1. **Fail Gracefully**: Non bloccare mai completamente l'interfaccia
2. **Communicate Clearly**: Messaggi comprensibili e actionable
3. **Provide Actions**: Sempre offrire opzioni all'utente
4. **Auto-Recovery**: Tentare recupero automatico quando possibile

### Performance

1. **Debounce Checks**: Evitare check troppo frequenti
2. **Queue Management**: Limitare dimensione coda offline
3. **Memory Usage**: Pulire timer e listener non utilizzati
4. **Network Efficiency**: Minimizzare richieste di test

### Accessibility

1. **Screen Readers**: Usare `aria-live` per annunci
2. **Keyboard Navigation**: Tutti i pulsanti devono essere navigabili
3. **High Contrast**: Supportare modalità alto contrasto
4. **Focus Management**: Gestire focus durante transizioni

### User Experience

1. **Transparency**: Comunicare chiaramente cosa sta succedendo
2. **Control**: Dare all'utente controllo sulla situazione
3. **Feedback**: Confermare le azioni intraprese
4. **Consistency**: Mantenere stile coerente con il resto del widget

## 🔮 Estensioni Future

### Planned Features

- **Smart Retry**: AI-powered retry timing based on error patterns
- **Predictive Offline**: Preload essentials before going offline
- **Progressive Degradation**: Gradual feature reduction under stress
- **Custom Handlers**: Plugin system for custom fallback behaviors

### Integration Points

- **Service Workers**: Cache offline responses
- **WebRTC**: Peer-to-peer backup channels
- **Push Notifications**: Notify users of service restoration
- **Background Sync**: Sync queued data when connectivity returns

## 📚 API Reference

### Public Methods

```javascript
// Force specific fallback states (testing)
fallbackManager.forceOfflineMode();
fallbackManager.forceMaintenanceMode();

// Check current state
fallbackManager.isInFallbackState();
fallbackManager.getCurrentFallbackType();

// Reset and cleanup
fallbackManager.reset();
fallbackManager.destroy();
```

### Events

```javascript
// Listen to fallback events
widget.on('fallback:state:changed', (state) => {
    console.log('Fallback state changed to:', state);
});

widget.on('fallback:message:queued', (message) => {
    console.log('Message queued for later:', message);
});

widget.on('fallback:recovery:complete', () => {
    console.log('Recovery completed successfully');
});
```

## 🤝 Contributing

Per contribuire agli stati di fallback:

1. Identifica nuovi scenari di errore
2. Implementa handling specifico
3. Aggiungi test per il nuovo scenario
4. Aggiorna documentazione
5. Considera l'impatto UX

---

**Il sistema di Fallback States garantisce che il widget fornisca sempre un'esperienza utente professionale, anche nelle condizioni più avverse.** 🛡️✨
