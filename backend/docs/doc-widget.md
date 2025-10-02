# 🤖 Widget Chatbot - Documentazione Funzionalità

## 📋 Panoramica
Il sistema Widget di ChatbotPlatform fornisce un chatbot completamente personalizzabile e accessibile per siti web. Integrato con il sistema RAG avanzato e API OpenAI-compatibili.

---

## 🏗️ Architettura Componenti

### **Backend (Laravel)**
- **`WidgetConfigController`**: Gestione configurazioni widget
- **`WidgetPreviewController`**: Preview pubblico senza autenticazione
- **`WidgetConfig` Model**: Configurazioni per tenant
- **API `/v1/chat/completions`**: Endpoint compatibile OpenAI per comunicazione

### **Frontend (Widget)**
- **`/public/widget/`**: Asset pubblici del widget
- **`chatbot-embed.js`**: Script di embedding principale
- **`chatbot-widget.js`**: Logica UI e gestione conversazioni
- **`chatbot-design-system.css`**: Sistema design completo
- **`chatbot-theming.js`**: Gestione temi dinamici

---

## ⚙️ Funzionalità Implementate

### **🎨 1. Configurazione Visiva**

**Temi Disponibili:**
- `default`: Blu aziendale
- `corporate`: Grigio corporate 
- `friendly`: Verde amichevole
- `high-contrast`: Alto contrasto accessibile
- `custom`: Colori personalizzati

**Configurazioni Layout:**
```php
// Posizionamento
'position' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']

// Dimensioni personalizzabili
'widget_width' => 'es. 400px'
'widget_height' => 'es. 600px'
'border_radius' => 'es. 12px'
'button_size' => 'es. 56px'

// Elementi UI
'show_header' => true/false
'show_avatar' => true/false  
'show_close_button' => true/false
'enable_animations' => true/false
'enable_dark_mode' => true/false
```

**Branding:**
- Logo personalizzato (`logo_url`)
- Favicon personalizzato (`favicon_url`)
- Font family personalizzato
- Palette colori completa (10 sfumature per tema)

### **🔧 2. Configurazione Comportamentale**

**Messaggi:**
- Messaggio di benvenuto personalizzabile
- Nome widget personalizzabile
- Auto-apertura configurabile

**API e LLM:**
```php
'api_model' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo']
'temperature' => 0.0-2.0
'max_tokens' => 1-4000
'enable_conversation_context' => true/false
```

### **🔐 3. Sicurezza e Accesso**

**Controlli Dominio:**
```php
'allowed_domains' => ['example.com', 'subdomain.example.com']
```

**Autenticazione API:**
- API Key dedicata per tenant (auto-generata)
- Scoping automatico delle richieste per tenant

### **📱 4. Accessibilità WCAG 2.1 AA**

**Implementato:**
- ✅ Focus management completo
- ✅ Screen reader support (ARIA, live regions)
- ✅ Keyboard navigation
- ✅ High contrast mode
- ✅ Reduced motion support
- ✅ Semantic HTML5

---

## 🎛️ Interfaccia Admin

### **Dashboard Widget (`/admin/tenants/{id}/widget-config`)**

**Sezioni di Configurazione:**
1. **Configurazione Base**: Nome, messaggio benvenuto, posizione
2. **Tema e Aspetto**: Selezione tema, colori custom, logo
3. **Layout**: Dimensioni, bordi, elementi UI
4. **Comportamento**: Animazioni, dark mode, auto-apertura
5. **API**: Modello LLM, parametri generazione
6. **Sicurezza**: Domini consentiti

**Funzionalità Admin:**
- 🔍 **Preview Live**: Anteprima in tempo reale con modifiche
- 📥 **Download Embed**: Generazione codice embedding HTML
- 🎨 **Download CSS**: Estrazione CSS tema personalizzato
- 🎨 **Color Picker**: Generazione CSS colori attuali

### **Gestione Multi-Tenant**
- Configurazione separata per ogni tenant
- API key dedicata per tenant
- Isolamento completo configurazioni

---

## 🚀 Deployment e Integrazione

### **Embedding in Siti Web**

**Metodo 1 - Script Automatico:**
```html
<script src="http://your-domain/widget/embed/chatbot-embed.js" 
        data-tenant="tenant-slug" 
        data-api-url="http://your-domain/api"></script>
```

**Metodo 2 - HTML Completo (scaricabile dall'admin):**
```html
<!DOCTYPE html>
<!-- Codice completo generato automaticamente -->
```

### **Preview Pubblico**
- Route pubblico: `/widget/preview/{tenant}`
- Accessibile senza login admin
- Supporta configurazioni temporanee via query string

---

## 🔄 Integrazione Backend

### **Comunicazione API**
```javascript
// Endpoint utilizzato dal widget
POST /api/v1/chat/completions

// Headers richiesti
Authorization: Bearer {tenant-api-key}
Content-Type: application/json

// Payload OpenAI-compatibile
{
  "model": "gpt-4o-mini",
  "messages": [...],
  "temperature": 0.7,
  "max_tokens": 1000
}
```

### **Configurazioni RAG Integrate**
Il widget utilizza automaticamente:
- Configurazioni RAG specifiche per tenant
- Knowledge Base selezionate automaticamente  
- Intent detection per query specializzate
- Fallback "Non lo so" per bassa confidence

---

## 🛠️ Personalizzazione Avanzata

### **CSS Variables (540+ variabili)**
```css
:root {
  --chatbot-primary-50: #eff6ff;
  --chatbot-primary-500: #3b82f6;
  --chatbot-primary-900: #1e3a8a;
  /* ... 540+ variabili */
}
```

### **Event System**
```javascript
// Eventi widget personalizzabili
window.chatbotEvents = {
  onOpen: () => console.log('Widget opened'),
  onClose: () => console.log('Widget closed'),
  onMessage: (msg) => console.log('Message sent:', msg),
  onResponse: (resp) => console.log('Response received:', resp)
}
```

### **Quick Actions (Roadmap)**
- Azioni server-mediated con JWT
- Autenticazione HMAC
- Slot personalizzabili

---

## 📊 Monitoraggio e Analytics

### **Widget Analytics Dashboard**
- Conversazioni totali
- Messaggi per sessione
- Bounce rate widget
- Performance LLM
- Costi per conversazione

### **Metriche Accessibilità**
- Test automatici WCAG
- Compatibilità screen reader
- Performance focus management

---

## 🔧 File Critici

```
backend/
├── app/Http/Controllers/Admin/
│   ├── WidgetConfigController.php      # Gestione configurazioni
│   └── WidgetAnalyticsController.php   # Analytics e metriche
├── app/Http/Controllers/
│   └── WidgetPreviewController.php     # Preview pubblico
├── app/Models/
│   └── WidgetConfig.php               # Model configurazioni
└── resources/views/admin/widget-config/ # Views admin

public/widget/
├── embed/chatbot-embed.js             # Script embedding
├── js/
│   ├── chatbot-widget.js              # Core widget logic
│   ├── chatbot-theming.js             # Theme management
│   └── chatbot-analytics.js           # Analytics tracking
├── css/
│   ├── chatbot-design-system.css      # Design system
│   ├── chatbot-themes.css             # Temi predefiniti
│   └── chatbot-accessibility.css      # Accessibilità
└── chatbot-widget.html               # Template widget
```

---

## 🚨 Note Operative

### **Problemi Comuni e Fix**
1. **Theme toggle non funziona**: Verifica attributi CSS `[data-chatbot-theme]`
2. **Doppia linkificazione URL**: Pattern regex corretti in `chatbot-widget.js`
3. **CSS non caricato**: File potrebbero essere corrotti - usare inline CSS
4. **CORS errors**: Verificare `allowed_domains` nelle configurazioni

### **Performance**
- CSS design system ottimizzato (gzip: ~8KB)
- JavaScript vanilla senza dipendenze (~15KB)
- Lazy loading componenti non critici
- Caching configurazioni lato client

### **Compatibilità Browser**
- ✅ Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
- ✅ Mobile iOS Safari, Chrome Mobile
- ✅ Screen readers: NVDA, JAWS, VoiceOver
- ✅ Keyboard navigation completa





















