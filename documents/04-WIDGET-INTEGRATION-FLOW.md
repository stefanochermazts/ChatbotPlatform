# Flusso di Integrazione Widget

## Panoramica

Il widget JavaScript embeddable permette ai siti web di integrare il chatbot con poche righe di codice. Gestisce UI, markdown rendering, analytics e comunicazione con l'API backend.

## Diagramma del Flusso

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. EMBEDDING SCRIPT                                             │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Frontend HTML include:                                        ││
│ │                                                               ││
│ │ <script>                                                      ││
│ │   window.chatbotConfig = {                                   ││
│ │     tenantId: 5,                                             ││
│ │     apiToken: 'sk_test_abc123...',                           ││
│ │     baseUrl: 'https://chatbotplatform.test:8443'             ││
│ │   };                                                          ││
│ │ </script>                                                     ││
│ │ <script src=".../chatbot-embed.js"></script>                 ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. CONFIGURATION LOADING                                        │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ chatbot-embed.js:                                            ││
│ │                                                               ││
│ │ 1. Read window.chatbotConfig                                 ││
│ │ 2. Fetch theme config:                                       ││
│ │    GET /api/v1/tenants/{id}/widget-theme                     ││
│ │                                                               ││
│ │ Response:                                                    ││
│ │ {                                                             ││
│ │   "primary_color": "#3B82F6",                                ││
│ │   "secondary_color": "#1E40AF",                              ││
│ │   "font_family": "Inter, sans-serif",                        ││
│ │   "welcome_message": "Ciao! Come posso aiutarti?",           ││
│ │   "bot_name": "Assistente San Cesareo",                      ││
│ │   "avatar_url": "/storage/avatars/5.png",                    ││
│ │   "position": "bottom-right",                                ││
│ │   "bubble_icon": "chat"                                      ││
│ │ }                                                             ││
│ │                                                               ││
│ │ 3. Inject CSS variables                                      ││
│ │ 4. Load chatbot-widget.js                                    ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. WIDGET INITIALIZATION                                        │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ chatbot-widget.js:                                           ││
│ │                                                               ││
│ │ 1. Create DOM structure:                                     ││
│ │    <div id="chatbot-container">                              ││
│ │      <button id="chatbot-toggle"></button>                   ││
│ │      <div id="chatbot-window" hidden>                        ││
│ │        <div class="chatbot-header"></div>                    ││
│ │        <div class="chatbot-messages"></div>                  ││
│ │        <div class="chatbot-input"></div>                     ││
│ │      </div>                                                   ││
│ │    </div>                                                     ││
│ │                                                               ││
│ │ 2. Apply theme CSS                                           ││
│ │ 3. Show welcome message                                      ││
│ │ 4. Initialize analytics                                      ││
│ │ 5. Restore conversation from sessionStorage                  ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. USER INTERACTION                                             │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ User types message → clicks Send                             ││
│ │                                                               ││
│ │ 1. Add user message bubble (optimistic UI)                   ││
│ │ 2. Show "typing..." indicator                                ││
│ │ 3. Prepare API request                                       ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. API COMMUNICATION                                            │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ POST /api/v1/chat/completions                                ││
│ │ Headers:                                                     ││
│ │   Authorization: Bearer {apiToken}                           ││
│ │   Content-Type: application/json                             ││
│ │                                                               ││
│ │ Body:                                                        ││
│ │ {                                                             ││
│ │   "model": "gpt-4o-mini",                                    ││
│ │   "messages": [                                              ││
│ │     {"role": "user", "content": "chi è il sindaco?"}         ││
│ │   ],                                                          ││
│ │   "stream": false,                                           ││
│ │   "temperature": 0.3,                                        ││
│ │   "max_tokens": 1000                                         ││
│ │ }                                                             ││
│ │                                                               ││
│ │ → Backend RAG flow (vedi 03-RAG-RETRIEVAL-FLOW.md)           ││
│ │                                                               ││
│ │ Response:                                                    ││
│ │ {                                                             ││
│ │   "choices": [{                                              ││
│ │     "message": {                                             ││
│ │       "role": "assistant",                                   ││
│ │       "content": "Il sindaco di San Cesareo è Alessandra..."││
│ │     }                                                         ││
│ │   }],                                                         ││
│ │   "x_rag_metadata": {                                        ││
│ │     "sources": [{"document_id": 3920, "source_url": "..."}] ││
│ │   }                                                           ││
│ │ }                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. MESSAGE PROCESSING                                           │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ chatbot-widget.js processMessage():                          ││
│ │                                                               ││
│ │ 1. Extract response content                                  ││
│ │ 2. Parse markdown to HTML                                    ││
│ │ 3. Process special elements:                                 ││
│ │    - Tables (markdown → HTML table)                          ││
│ │    - Links (add target="_blank")                             ││
│ │    - Code blocks (syntax highlighting)                       ││
│ │    - Lists (ul/ol rendering)                                 ││
│ │                                                               ││
│ │ 4. URL Masking & Restoration:                                ││
│ │    a) Mask URLs → ###URLMASK{N}###                           ││
│ │    b) Convert markdown → HTML                                ││
│ │    c) Restore placeholders in HTML                           ││
│ │    d) Linkify standalone URLs                                ││
│ │                                                               ││
│ │ 5. Sanitize HTML (XSS prevention)                            ││
│ │ 6. Add citations if sources present                          ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. MARKDOWN RENDERING                                           │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ parseMarkdown() function:                                    ││
│ │                                                               ││
│ │ Input markdown:                                              ││
│ │ ```                                                           ││
│ │ Il sindaco è **Alessandra Sabelli**.                         ││
│ │                                                               ││
│ │ [Fonte](https://www.comune.sancesareo.rm.it/...)             ││
│ │ ```                                                           ││
│ │                                                               ││
│ │ Processing steps:                                            ││
│ │ 1. Mask https:// URLs → ###URLMASK0###                       ││
│ │ 2. Mask www. URLs → ###URLMASK1### (skip if in markdown)     ││
│ │ 3. Convert markdown syntax:                                  ││
│ │    **bold** → <strong>bold</strong>                          ││
│ │    *italic* → <em>italic</em>                                ││
│ │    [text](url) → <a href="url">text</a>                      ││
│ │    # Header → <h1>Header</h1>                                ││
│ │    - List → <ul><li>List</li></ul>                           ││
│ │    | Table | → <table>...</table>                            ││
│ │                                                               ││
│ │ 4. Restore URLs:                                             ││
│ │    a) In <a href="###URLMASK0###"> → href="https://..."     ││
│ │    b) In [text](###URLMASK0###) → markdown link conversion   ││
│ │    c) Standalone ###URLMASK0### → <a>link</a>                ││
│ │                                                               ││
│ │ Output HTML:                                                 ││
│ │ <p>Il sindaco è <strong>Alessandra Sabelli</strong>.</p>    ││
│ │ <p><a href="https://..." target="_blank">Fonte</a></p>       ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. CITATIONS RENDERING                                          │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ renderCitations(sources):                                    ││
│ │                                                               ││
│ │ If response has x_rag_metadata.sources:                      ││
│ │                                                               ││
│ │ <div class="chatbot-citations">                              ││
│ │   <p class="citations-title">📚 Fonti:</p>                   ││
│ │   <ul>                                                        ││
│ │     <li>                                                      ││
│ │       <a href="{source_url}" target="_blank">                ││
│ │         📄 {document_title}                                   ││
│ │       </a>                                                    ││
│ │     </li>                                                     ││
│ │   </ul>                                                       ││
│ │ </div>                                                        ││
│ │                                                               ││
│ │ Deep links to admin chunks view:                             ││
│ │ /admin/tenants/{tenant_id}/documents/{doc_id}/chunks         ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 9. UI UPDATE                                                    │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ 1. Remove "typing..." indicator                              ││
│ │ 2. Add bot message bubble with rendered HTML                 ││
│ │ 3. Smooth scroll to bottom                                   ││
│ │ 4. Save conversation to sessionStorage                       ││
│ │ 5. Enable input field                                        ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 10. ANALYTICS TRACKING                                          │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ POST /api/v1/widget/events/public                            ││
│ │                                                               ││
│ │ Events tracked:                                              ││
│ │ - widget_opened                                              ││
│ │ - widget_closed                                              ││
│ │ - message_sent                                               ││
│ │ - message_received                                           ││
│ │ - link_clicked (deep link to source)                         ││
│ │ - form_submitted                                             ││
│ │ - feedback_given (thumbs up/down)                            ││
│ │                                                               ││
│ │ Payload:                                                     ││
│ │ {                                                             ││
│ │   "tenant_id": 5,                                            ││
│ │   "event_type": "message_sent",                              ││
│ │   "session_id": "sess_abc123",                               ││
│ │   "metadata": {                                              ││
│ │     "message_length": 20,                                    ││
│ │     "response_time_ms": 2345                                 ││
│ │   }                                                           ││
│ │ }                                                             ││
│ └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

## Classi/File Coinvolti

### 1. Frontend JavaScript
- **`chatbot-embed.js`** (Loader)
  - Carica configurazione tenant
  - Inietta CSS e widget
  - Inizializza ambiente

- **`chatbot-widget.js`** (Main logic)
  - `init()` - Inizializzazione widget
  - `sendMessage()` - Invia query a backend
  - `receiveMessage()` - Process response
  - `parseMarkdown()` - Markdown → HTML
  - `renderCitations()` - Render sources
  - `trackEvent()` - Analytics

### 2. Backend Controllers
- **`WidgetController.php`**
  - `getTheme()` - GET `/api/v1/tenants/{id}/widget-theme`
  - Returns theme configuration JSON

- **`WidgetAnalyticsController.php`**
  - `trackEvent()` - POST `/api/v1/widget/events/public`
  - Store analytics events

### 3. Models
- **`WidgetConfig`**
  - Theme settings per tenant
  - Colors, fonts, welcome message
  - Relationship: `belongsTo(Tenant::class)`

## Esempio Pratico

### HTML Embedding

```html
<!DOCTYPE html>
<html>
<head>
  <title>Comune di San Cesareo</title>
</head>
<body>
  <h1>Benvenuto sul sito del Comune</h1>
  
  <!-- Chatbot Widget -->
  <script>
    window.chatbotConfig = {
      tenantId: 5,
      apiToken: 'sk_test_abc123',
      baseUrl: 'https://chatbotplatform.test:8443',
      debug: false
    };
  </script>
  <script src="https://chatbotplatform.test:8443/widget/js/chatbot-embed.js"></script>
</body>
</html>
```

### Theme Configuration Response

```json
{
  "primary_color": "#3B82F6",
  "secondary_color": "#1E40AF",
  "text_color": "#1F2937",
  "font_family": "Inter, system-ui, sans-serif",
  "font_size": "14px",
  "border_radius": "12px",
  "welcome_message": "Ciao! Sono l'assistente virtuale del Comune di San Cesareo. Come posso aiutarti?",
  "bot_name": "Assistente San Cesareo",
  "avatar_url": "/storage/avatars/san-cesareo.png",
  "position": "bottom-right",
  "bubble_icon": "chat",
  "bubble_size": "60px",
  "window_width": "400px",
  "window_height": "600px",
  "z_index": 9999,
  "show_powered_by": true
}
```

### Markdown Processing Example

```javascript
// Input
const markdown = `
Il sindaco è **Alessandra Sabelli**.

Puoi contattare il Comune:
- 📞 Tel: 06.95898200
- 📧 Email: info@comune.sancesareo.rm.it

[Visita il sito](https://www.comune.sancesareo.rm.it)
`;

// After parseMarkdown()
const html = `
<p>Il sindaco è <strong>Alessandra Sabelli</strong>.</p>

<p>Puoi contattare il Comune:</p>
<ul>
  <li>📞 Tel: 06.95898200</li>
  <li>📧 Email: info@comune.sancesareo.rm.it</li>
</ul>

<p>
  <a href="https://www.comune.sancesareo.rm.it" target="_blank" rel="noopener noreferrer" class="chatbot-link">
    Visita il sito
  </a>
</p>
`;
```

## Note Tecniche

### URL Masking & Restoration

Problema: Markdown parser confonde URLs durante conversione

Soluzione: 3-step process

```javascript
// 1. MASK: Protect URLs durante markdown parsing
html = html.replace(/(https?:\/\/[^\s<"']+)/g, (match) => {
  const placeholder = `###URLMASK${urlCounter++}###`;
  urlPlaceholders.push({ placeholder, url: match });
  return placeholder;
});

// 2. CONVERT: Markdown → HTML (URL sono safe come placeholder)
html = convertMarkdownToHtml(html);

// 3. RESTORE: Replace placeholder con URL reali
// Priorità: HTML <a>, poi markdown, poi standalone
urlPlaceholders.forEach(({ placeholder, url }) => {
  // a) In HTML: <a href="###URLMASK0###">
  html = html.replace(
    new RegExp(`<a([^>]*?)href="${placeholder}"`, 'g'),
    `<a$1href="${url}"`
  );
  
  // b) In markdown: [text](###URLMASK0###)
  html = html.replace(
    new RegExp(`\\[([^\\]]+)\\]\\(${placeholder}\\)`, 'g'),
    `<a href="${url}" target="_blank">$1</a>`
  );
  
  // c) Standalone: ###URLMASK0###
  html = html.replace(placeholder, `<a href="${url}">${url}</a>`);
});
```

### www. URL Handling

**Critical fix**: Non mascherare `www.` URLs già in markdown links

```javascript
html = html.replace(/(?<!["\[>])(www\.[^\s<"']+?)(?=[\s<"']|$)/g, (match, offset, string) => {
  // Check if www. è dentro [text](www.url)
  const beforeMatch = string.substring(Math.max(0, offset - 200), offset);
  const afterMatch = string.substring(offset, Math.min(string.length, offset + 10));
  
  if (beforeMatch.includes('[') && afterMatch.startsWith(match + '](')) {
    return match; // Skip masking
  }
  
  // Mask solo se standalone
  const placeholder = `###URLMASK${urlCounter++}###`;
  urlPlaceholders.push({ placeholder, url: match });
  return placeholder;
});
```

### Session Management

Conversation context salvato in sessionStorage:

```javascript
const conversation = {
  session_id: generateSessionId(),
  tenant_id: config.tenantId,
  messages: [
    { role: 'assistant', content: welcomeMessage, timestamp: Date.now() },
    { role: 'user', content: 'chi è il sindaco?', timestamp: Date.now() },
    { role: 'assistant', content: 'Il sindaco è...', timestamp: Date.now() }
  ],
  created_at: Date.now()
};

sessionStorage.setItem('chatbot_conversation', JSON.stringify(conversation));
```

## Troubleshooting

### Problema: Widget non appare

**Sintomi**: Script caricato ma nessun elemento visibile

**Debug**:
```javascript
// Check console errors
console.log('Chatbot config:', window.chatbotConfig);

// Verifica DOM injection
console.log('Widget container:', document.getElementById('chatbot-container'));

// Check theme loading
fetch('/api/v1/tenants/5/widget-theme')
  .then(r => r.json())
  .then(theme => console.log('Theme loaded:', theme));
```

**Soluzioni**:
1. Verifica `apiToken` valido
2. Check CORS headers su backend
3. Verifica `tenantId` esiste
4. Check browser console per errori JavaScript

### Problema: Markdown link malformati

**Sintomi**: Link appare come `###URLMASK0###`

**Debug**:
```javascript
// Add logging in parseMarkdown()
console.log('URL placeholders:', urlPlaceholders);
console.log('HTML before restoration:', html);
console.log('HTML after restoration:', html);
```

**Soluzioni**:
1. Verifica ordine restoration (HTML → markdown → standalone)
2. Check regex escape per special chars in URL
3. Verifica `www.` URL masking non interferisce con markdown

### Problema: Citations non mostrate

**Sintomi**: Response ha sources ma non vengono renderizzate

**Debug**:
```javascript
console.log('Response metadata:', response.x_rag_metadata);
console.log('Sources:', response.x_rag_metadata?.sources);
```

**Soluzioni**:
```javascript
// Check sources present
if (response.x_rag_metadata?.sources?.length > 0) {
  renderCitations(response.x_rag_metadata.sources);
}
```

## Best Practices

1. **Async loading**: Usa `defer` o `async` per script embed
2. **Error handling**: Catch e mostra errori user-friendly
3. **Loading states**: Sempre mostra "typing..." durante API call
4. **Accessibility**: ARIA labels, keyboard navigation
5. **Mobile responsive**: Fullscreen su mobile <768px
6. **Session persistence**: Restore conversation on page reload
7. **Analytics**: Track ogni interazione per insights
8. **XSS prevention**: Sanitize HTML rendered da markdown

## Performance

### Target Metrics
- **Load time**: <500ms per init widget
- **Interaction latency**: <100ms per UI update
- **Message render**: <50ms per markdown parsing
- **Memory footprint**: <2MB per session

### Optimization
```javascript
// Lazy load widget on first interaction
let widgetLoaded = false;

document.getElementById('chatbot-toggle').addEventListener('click', () => {
  if (!widgetLoaded) {
    loadWidget();
    widgetLoaded = true;
  }
  toggleWindow();
});
```

## Prossimi Step

1. → **[05-DATA-MODELS.md](05-DATA-MODELS.md)** - Database schema
2. → **[06-QUEUE-WORKERS.md](06-QUEUE-WORKERS.md)** - Background jobs

