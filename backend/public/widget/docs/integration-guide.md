# 🚀 Chatbot Widget - Guida di Integrazione

## 📋 Panoramica

Il **Chatbot Widget** è una soluzione completa per aggiungere un assistente virtuale intelligente al tuo sito web. Supporta **RAG avanzato** con memoria conversazionale, **accessibilità WCAG 2.1 AA**, **personalizzazione completa** e **citazioni delle fonti**.

### ✨ Caratteristiche Principali

- 🧠 **RAG Conversation-Aware** con HyDE e LLM Reranking
- 🎨 **Personalizzazione completa** (colori, loghi, layout)
- ♿ **Accessibilità WCAG 2.1 AA** compliant
- 📱 **Responsive design** mobile-first
- 🔗 **Citazioni con deep-link** ai documenti sorgente
- 🌙 **Dark mode** automatico
- 🔒 **Sicurezza enterprise** con HMAC e rate limiting
- 📊 **Analytics integrato** per insights

---

## ⚡ Installazione Rapida (1 minuto)

### **Metodo 1: Configurazione Inline (Consigliato)**

```html
<!-- 1. Configura il widget -->
<script>
  window.chatbotConfig = {
    apiKey: 'IL_TUO_API_KEY',
    tenantId: 'il-tuo-tenant-id',
    theme: 'corporate',
    autoOpen: false
  };
</script>

<!-- 2. Carica il widget -->
<script src="https://tuo-dominio.com/widget/embed/chatbot-embed.js" async></script>
```

### **Metodo 2: Configurazione Data Attributes**

```html
<script 
  src="https://tuo-dominio.com/widget/embed/chatbot-embed.js" 
  data-api-key="IL_TUO_API_KEY"
  data-tenant-id="il-tuo-tenant-id"
  data-theme="friendly"
  data-auto-open="false"
  async
></script>
```

### **Metodo 3: JavaScript Dinamico**

```javascript
// Caricamento programmatico
const script = document.createElement('script');
script.src = 'https://tuo-dominio.com/widget/embed/chatbot-embed.js';
script.dataset.apiKey = 'IL_TUO_API_KEY';
script.dataset.tenantId = 'il-tuo-tenant-id';
script.async = true;
document.head.appendChild(script);
```

---

## ⚙️ Configurazione Completa

### **Opzioni Disponibili**

| Opzione | Tipo | Default | Descrizione |
|---------|------|---------|-------------|
| `apiKey` | `string` | **Richiesto** | Chiave API del tuo tenant |
| `tenantId` | `string` | **Richiesto** | ID del tuo tenant |
| `theme` | `string` | `'default'` | Tema: `default`, `corporate`, `friendly`, `high-contrast` |
| `autoOpen` | `boolean` | `false` | Apri automaticamente al caricamento |
| `position` | `string` | `'bottom-right'` | Posizione: `bottom-right`, `bottom-left`, `top-right`, `top-left` |
| `enableConversationContext` | `boolean` | `true` | Abilita memoria conversazionale |
| `enableAnalytics` | `boolean` | `true` | Abilita tracking eventi |
| `debug` | `boolean` | `false` | Modalità debug con log estesi |

### **Esempio Configurazione Avanzata**

```html
<script>
  window.chatbotConfig = {
    // Credenziali
    apiKey: 'cb_live_1234567890abcdef',
    tenantId: 'la-tua-azienda',
    
    // Aspetto
    theme: 'corporate',
    position: 'bottom-left',
    
    // Comportamento
    autoOpen: false,
    enableConversationContext: true,
    enableAnalytics: true,
    
    // Debug (solo in sviluppo)
    debug: true
  };
</script>
<script src="https://tuo-dominio.com/widget/embed/chatbot-embed.js" async></script>
```

---

## 🎨 Personalizzazione Temi

### **Temi Predefiniti**

#### **Default Blue** (`theme: 'default'`)
- Colore primario: Blu moderno (#3b82f6)
- Ideale per: Siti corporate, tecnologia, servizi

#### **Corporate** (`theme: 'corporate'`)
- Colore primario: Grigio scuro (#1f2937)
- Ideale per: Aziende, banche, settore legale

#### **Friendly** (`theme: 'friendly'`)
- Colore primario: Verde (#10b981)
- Ideale per: E-commerce, salute, educazione

#### **High Contrast** (`theme: 'high-contrast'`)
- Massimo contrasto per accessibilità
- Ideale per: Conformità WCAG 2.1 AAA

### **Tema Personalizzato**

```javascript
// Dopo il caricamento del widget
window.addEventListener('chatbot:embed:loaded', function() {
  // Registra tema personalizzato
  window.ChatbotThemeEngine.registerTheme('il-mio-tema', {
    name: 'Il Mio Brand',
    colors: {
      primary: {
        500: '#ff6b35', // Colore principale
        600: '#e55a2b', // Hover state
        700: '#cc4d20'  // Active state
      }
    },
    brand: {
      name: 'Il Mio Assistente',
      logo: 'https://tuo-dominio.com/logo.png',
      companyName: 'La Tua Azienda'
    },
    layout: {
      widget: {
        width: '420px',
        borderRadius: '16px'
      }
    }
  });
  
  // Applica il tema
  window.ChatbotThemeEngine.applyTheme('il-mio-tema');
});
```

---

## 🔧 API e Eventi

### **Controllo Programmatico**

```javascript
// Accedi al widget
const widget = window.chatbotWidget;

// Apri/chiudi widget
widget.open();
widget.close();
widget.toggle();

// Reset conversazione
widget.reset();

// Aggiorna configurazione
widget.updateConfig({
  theme: 'friendly',
  autoOpen: true
});
```

### **Eventi Disponibili**

```javascript
// Ascolto eventi widget
window.addEventListener('chatbot:widget:opened', function() {
  console.log('Widget aperto');
  // Analytics, tracking, etc.
});

window.addEventListener('chatbot:widget:closed', function() {
  console.log('Widget chiuso');
});

window.addEventListener('chatbot:message:sent', function(event) {
  console.log('Messaggio inviato:', event.detail.content);
});

window.addEventListener('chatbot:message:received', function(event) {
  console.log('Risposta ricevuta:', event.detail);
  console.log('Citazioni:', event.detail.citations);
});

window.addEventListener('chatbot:theme:changed', function(event) {
  console.log('Tema cambiato:', event.detail.themeId);
});
```

### **Hooks di Customizzazione**

```javascript
// Personalizza messaggi
widget.on('chatbot:message:sent', function(data) {
  // Aggiungi tracking custom
  gtag('event', 'chatbot_message_sent', {
    'message_length': data.content.length
  });
});

// Personalizza risposte
widget.on('chatbot:message:received', function(data) {
  // Evidenzia citazioni importanti
  if (data.citations && data.citations.length > 3) {
    console.log('Risposta con molte fonti!');
  }
});
```

---

## 📱 Integrazione Mobile

### **Responsive Design Automatico**

Il widget si adatta automaticamente a schermi mobile:
- **Desktop**: Widget flottante (380x600px)
- **Mobile**: Schermo intero per UX ottimizzata
- **Tablet**: Dimensioni adattive

### **Configurazione Mobile-Specific**

```javascript
// Configura comportamento mobile
if (window.innerWidth <= 768) {
  window.chatbotConfig.autoOpen = false; // Non aprire automaticamente su mobile
  window.chatbotConfig.position = 'bottom-right'; // Posizione ottimale
}
```

### **Progressive Web App (PWA) Support**

```javascript
// Integrazione PWA
if ('serviceWorker' in navigator) {
  window.addEventListener('chatbot:embed:loaded', function() {
    // Widget disponibile offline
    console.log('Chatbot ready for offline use');
  });
}
```

---

## 🔒 Sicurezza e Privacy

### **Content Security Policy (CSP)**

```html
<!-- Aggiungi al tuo CSP header -->
<meta http-equiv="Content-Security-Policy" content="
  script-src 'self' https://tuo-dominio.com 'unsafe-inline';
  style-src 'self' https://tuo-dominio.com 'unsafe-inline' https://fonts.googleapis.com;
  connect-src 'self' https://tuo-dominio.com;
  img-src 'self' https://tuo-dominio.com data:;
  font-src 'self' https://fonts.gstatic.com;
">
```

### **Iframe Sandboxing (Opzionale)**

```html
<!-- Per massima sicurezza -->
<iframe 
  src="https://tuo-dominio.com/widget/secure-embed.html?tenant=il-tuo-tenant" 
  sandbox="allow-scripts allow-same-origin allow-forms"
  style="position: fixed; bottom: 20px; right: 20px; width: 380px; height: 600px; border: none; z-index: 9999;"
></iframe>
```

### **Gestione Cookies e GDPR**

```javascript
// Conformità GDPR
window.chatbotConfig = {
  apiKey: 'your-api-key',
  tenantId: 'your-tenant',
  
  // Disabilita analytics se consenso non dato
  enableAnalytics: checkGDPRConsent(),
  
  // Politiche privacy
  privacyPolicyUrl: 'https://tuo-sito.com/privacy',
  cookieNotice: true
};

function checkGDPRConsent() {
  // Controlla consenso cookie/analytics
  return localStorage.getItem('gdpr-consent') === 'true';
}
```

---

## ⚡ Performance e Ottimizzazioni

### **Caricamento Asincrono**

```html
<!-- ✅ Corretto: non blocca il rendering -->
<script src="chatbot-embed.js" async></script>

<!-- ❌ Evitare: blocca il rendering -->
<script src="chatbot-embed.js"></script>
```

### **Preconnect per Performance**

```html
<!-- Migliora i tempi di caricamento -->
<link rel="preconnect" href="https://tuo-dominio.com">
<link rel="dns-prefetch" href="https://tuo-dominio.com">
```

### **Lazy Loading**

```javascript
// Carica widget solo quando necessario
function loadChatbotOnInteraction() {
  const script = document.createElement('script');
  script.src = 'https://tuo-dominio.com/widget/embed/chatbot-embed.js';
  script.dataset.apiKey = 'your-api-key';
  script.async = true;
  document.head.appendChild(script);
}

// Carica al primo scroll, click o dopo 5 secondi
let loaded = false;
function loadOnce() {
  if (!loaded) {
    loaded = true;
    loadChatbotOnInteraction();
  }
}

window.addEventListener('scroll', loadOnce, { once: true });
window.addEventListener('click', loadOnce, { once: true });
setTimeout(loadOnce, 5000);
```

### **Resource Hints**

```html
<!-- Ottimizza caricamento risorse -->
<link rel="prefetch" href="https://tuo-dominio.com/widget/css/chatbot-widget.css">
<link rel="prefetch" href="https://tuo-dominio.com/widget/js/chatbot-widget.js">
```

---

## 🧪 Testing e Debug

### **Modalità Debug**

```javascript
// Abilita debug dettagliato
window.chatbotConfig = {
  debug: true, // ← Importante
  apiKey: 'your-api-key',
  tenantId: 'your-tenant'
};

// Osserva log console
// [ChatbotEmbed] Starting widget load...
// [ChatbotEmbed] CSS loaded: .../chatbot-widget.css
// [ChatbotEmbed] JS loaded: .../chatbot-widget.js
// [ChatbotEmbed] Widget embedded successfully
```

### **Test di Compatibilità**

```javascript
// Verifica supporto browser
window.addEventListener('chatbot:embed:error', function(event) {
  console.error('Widget non supportato:', event.detail.error);
  
  // Mostra fallback personalizzato
  showCustomFallback();
});

function showCustomFallback() {
  const fallback = document.createElement('div');
  fallback.innerHTML = `
    <div style="position: fixed; bottom: 20px; right: 20px; padding: 15px; background: #f0f0f0; border-radius: 8px; max-width: 300px;">
      <strong>Chat non disponibile</strong><br>
      <a href="mailto:support@tuodominio.com">Contatta il supporto</a>
    </div>
  `;
  document.body.appendChild(fallback);
}
```

### **Test Accessibilità**

```javascript
// Verifica compliance accessibilità
window.addEventListener('chatbot:widget:opened', function() {
  // Test focus management
  console.log('Focus element:', document.activeElement);
  
  // Test ARIA attributes
  const widget = document.getElementById('chatbot-widget');
  console.log('ARIA modal:', widget.getAttribute('aria-modal'));
  console.log('Role:', widget.getAttribute('role'));
});
```

---

## 🚀 Casi d'Uso Avanzati

### **E-commerce Integration**

```javascript
// Passa contesto ordine al chatbot
window.chatbotConfig = {
  apiKey: 'your-api-key',
  tenantId: 'ecommerce-store',
  
  // Contesto personalizzato
  customContext: {
    orderId: getCurrentOrderId(),
    customerId: getCurrentCustomerId(),
    cartItems: getCartItems().length,
    pageType: 'product' // product, cart, checkout, account
  }
};

// Invia contesto con ogni messaggio
widget.on('chatbot:message:sent', function(data) {
  // Il backend può accedere al contesto per risposte personalizzate
});
```

### **Multi-Language Support**

```javascript
// Configura lingua dinamicamente
const userLanguage = navigator.language.split('-')[0]; // 'it', 'en', 'fr'

window.chatbotConfig = {
  apiKey: 'your-api-key',
  tenantId: 'your-tenant',
  language: userLanguage,
  
  // Personalizza messaggi per lingua
  messages: {
    it: {
      placeholder: 'Scrivi un messaggio...',
      welcome: 'Ciao! Come posso aiutarti?'
    },
    en: {
      placeholder: 'Type a message...',
      welcome: 'Hello! How can I help you?'
    }
  }
};
```

### **A/B Testing**

```javascript
// Test versioni diverse del widget
const variant = Math.random() < 0.5 ? 'A' : 'B';

window.chatbotConfig = {
  apiKey: 'your-api-key',
  tenantId: 'your-tenant',
  theme: variant === 'A' ? 'corporate' : 'friendly',
  autoOpen: variant === 'A' ? false : true,
  
  // Tracking per analisi
  variant: variant
};

// Track risultati
widget.on('chatbot:message:sent', function(data) {
  analytics.track('chatbot_interaction', {
    variant: variant,
    messageLength: data.content.length
  });
});
```

---

## 🔧 Troubleshooting

### **Problemi Comuni**

#### **Widget non appare**
```javascript
// 1. Verifica configurazione
console.log('Config:', window.chatbotConfig);

// 2. Verifica caricamento script
const scripts = document.querySelectorAll('script[src*="chatbot-embed"]');
console.log('Scripts caricati:', scripts.length);

// 3. Verifica errori console
window.addEventListener('chatbot:embed:error', function(event) {
  console.error('Errore embedding:', event.detail);
});
```

#### **Errori API**
```javascript
// Verifica connettività API
fetch('/v1/chat/completions', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + window.chatbotConfig.apiKey,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    model: 'gpt-4o-mini',
    messages: [{ role: 'user', content: 'test' }]
  })
})
.then(response => {
  console.log('API Status:', response.status);
  return response.json();
})
.then(data => {
  console.log('API Response:', data);
})
.catch(error => {
  console.error('API Error:', error);
});
```

#### **Conflitti CSS**
```css
/* Isola widget da CSS del sito */
#chatbot-widget-container {
  all: initial;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

#chatbot-widget-container * {
  box-sizing: border-box;
}
```

#### **Problemi Performance**
```javascript
// Monitora performance
window.addEventListener('chatbot:embed:loaded', function() {
  console.log('Widget load time:', performance.now() + 'ms');
});

// Optimize per siti lenti
if (navigator.connection && navigator.connection.effectiveType === 'slow-2g') {
  // Disabilita widget su connessioni molto lente
  window.chatbotConfig = { disabled: true };
}
```

### **Supporto Browser**

| Browser | Versione Minima | Note |
|---------|-----------------|------|
| Chrome | 60+ | Supporto completo |
| Firefox | 55+ | Supporto completo |
| Safari | 12+ | Supporto completo |
| Edge | 79+ | Supporto completo |
| IE | Non supportato | Fallback disponibile |

---

## 📞 Supporto

### **Risorse Aggiuntive**

- 📚 **Documentazione API**: `/docs/api`
- 🎨 **Playground Temi**: `/docs/theme-builder`
- 🧪 **Sandbox Testing**: `/docs/sandbox`
- 📊 **Analytics Dashboard**: `/admin/analytics`

### **Contatti**

- 💬 **Chat Support**: Widget nel nostro sito
- 📧 **Email**: `support@chatbotplatform.com`
- 🐛 **Bug Reports**: GitHub Issues
- 💡 **Feature Requests**: Roadmap pubblico

### **Community**

- 👥 **Discord**: Community sviluppatori
- 📖 **Blog**: Best practices e case studies
- 🎥 **YouTube**: Tutorial e webinar
- 📱 **Twitter**: Aggiornamenti e novità

---

**🎉 Congratulazioni!** Il tuo chatbot intelligente è ora pronto per migliorare l'esperienza dei tuoi utenti con RAG avanzato, accessibilità completa e personalizzazione totale.

> 💡 **Tip**: Inizia con la configurazione base e personalizza gradualmente il tema e le funzionalità avanzate basandoti sul feedback degli utenti.
