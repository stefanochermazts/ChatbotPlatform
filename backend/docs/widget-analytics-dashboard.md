# 📊 Widget Analytics Dashboard - Documentazione

La **Widget Analytics Dashboard** è un sistema completo per il monitoraggio e l'analisi dell'utilizzo dei chatbot widget da parte dei tenant. Fornisce insights dettagliati su performance, engagement e comportamento degli utenti.

## 🎯 Caratteristiche Principali

### Dashboard Overview

- **Panoramica Multi-Tenant**: Visualizzazione aggregata di tutti i tenant
- **Filtro per Tenant**: Selezione tenant specifico per analytics dettagliate
- **Filtro Temporale**: Selezione range date personalizzabile
- **Metriche KPI**: Indicatori di performance chiave in tempo reale

### Metriche Tracked

#### Metriche Base
- **Eventi Totali**: Numero totale di eventi registrati
- **Sessioni Uniche**: Numero di sessioni utente distinte
- **Messaggi Inviati**: Numero di messaggi inviati dagli utenti
- **Risposte Generate**: Numero di risposte generate dal RAG

#### Metriche Performance
- **Tempo Risposta Medio**: Latenza media delle risposte RAG
- **Token Utilizzati**: Consumo totale di token OpenAI
- **Citazioni Fornite**: Numero di citazioni nei risultati RAG
- **Tasso Successo**: Percentuale di risposte completate con successo

#### Metriche Engagement
- **Tasso Conversione**: Percentuale sessioni che diventano conversazioni
- **Messaggi per Sessione**: Media messaggi per sessione utente
- **Durata Sessioni**: Tempo medio di utilizzo del widget
- **Tasso Errori**: Percentuale di errori/fallimenti

## 🔧 Implementazione Tecnica

### Backend Components

#### Model: `WidgetEvent`
```php
// app/Models/WidgetEvent.php
class WidgetEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'event_type',
        'session_id',
        'event_timestamp',
        'user_agent',
        'ip_address',
        'event_data',
        'response_time_ms',
        'confidence_score',
        'tokens_used',
        'had_error',
        'citations_count',
    ];

    // Scopes per query ottimizzate
    public function scopeForTenant($query, $tenantId);
    public function scopeInDateRange($query, $startDate, $endDate);
    public function scopeByEventType($query, $eventType);
}
```

#### Controller: `WidgetAnalyticsController`
```php
// app/Http/Controllers/Admin/WidgetAnalyticsController.php
class WidgetAnalyticsController extends Controller
{
    public function index(Request $request); // Dashboard overview
    public function show(Tenant $tenant, Request $request); // Tenant analytics
    public function export(Tenant $tenant, Request $request); // CSV export
}
```

#### API Controller: `WidgetEventController`
```php
// app/Http/Controllers/Api/WidgetEventController.php
class WidgetEventController extends Controller
{
    public function track(Request $request); // Track events from widget
    public function sessionStats(Request $request); // Get session statistics
    public function health(Request $request); // Health check
}
```

### Frontend Widget Integration

#### Analytics Class
```javascript
class Analytics {
    constructor(apiKey, tenantId, baseURL = '') {
        this.apiKey = apiKey;
        this.tenantId = tenantId;
        this.sessionId = this.getOrCreateSessionId();
        this.enabled = CONFIG.enableAnalytics && apiKey && tenantId;
    }

    trackEvent(eventType, eventData = {}) {
        // Implementazione tracking eventi
    }

    // Metodi specifici per eventi widget
    trackWidgetOpened();
    trackWidgetClosed();
    trackMessageSent(query);
    trackMessageReceived(response, responseTime, citations, confidence, tokensUsed);
    trackError(error, context);
}
```

#### Eventi Tracciati

| Evento | Descrizione | Dati Inclusi |
|--------|-------------|--------------|
| `widget_loaded` | Widget caricato sulla pagina | URL, user agent, viewport |
| `chatbot_opened` | Widget aperto dall'utente | Timestamp |
| `chatbot_closed` | Widget chiuso dall'utente | Durata sessione |
| `message_sent` | Messaggio inviato dall'utente | Query, lunghezza |
| `message_received` | Risposta ricevuta dal RAG | Response time, citazioni, token |
| `message_error` | Errore durante l'invio | Dettagli errore |
| `widget_error` | Errore generale widget | Contesto errore |

## 📱 Interfaccia Utente

### Dashboard Index (`/admin/widget-analytics`)

#### Sezioni Principali

1. **Header con Filtri**
   - Dropdown selezione tenant
   - Input date range (inizio/fine)
   - Pulsanti aggiorna/reset

2. **Metrics Cards** (se tenant selezionato)
   - Eventi totali con trend giornaliero
   - Sessioni uniche con rate conversione
   - Messaggi e risposte con media per sessione
   - Performance metrics (response time, token)

3. **Quick Actions**
   - Link analytics dettagliate
   - Link configurazione widget
   - Link anteprima widget

4. **Tenant Overview Table**
   - Lista tutti i tenant con status widget
   - Indicatori attività recente
   - Link azioni rapide per tenant

### Tenant Analytics (`/admin/tenants/{tenant}/widget-analytics`)

#### Sezioni Avanzate

1. **KPI Cards Dettagliate**
   - Metriche con trend e valutazioni qualitative
   - Indicatori visivi (✅ ottimo, ⚠️ accettabile, ❌ problemi)

2. **Charts e Grafici**
   - **Daily Activity Chart**: Attività giornaliera con bar chart
   - **Event Distribution**: Distribuzione tipi eventi con percentuali
   - **Performance Metrics**: Metriche dettagliate con soglie

3. **Recent Events Table**
   - Ultimi 50 eventi con dettagli
   - Filtri per tipo evento
   - Dettagli query/errori in tooltip

4. **Export e Azioni**
   - Export CSV dati analytics
   - Link configurazione widget
   - Link RAG tester
   - Anteprima live widget

## 📊 API Endpoints

### Analytics Tracking API

```http
POST /api/v1/widget/events
Authorization: Bearer {API_KEY}
X-Tenant-Id: {TENANT_ID}

{
  "event_type": "message_sent",
  "session_id": "session_123456789",
  "event_data": {
    "query": "Quanto costa il servizio?",
    "query_length": 22,
    "page_url": "https://example.com/contact"
  }
}
```

```http
GET /api/v1/widget/session-stats?session_id=session_123456789
Authorization: Bearer {API_KEY}
X-Tenant-Id: {TENANT_ID}

Response:
{
  "success": true,
  "stats": {
    "session_id": "session_123456789",
    "total_events": 15,
    "total_messages": 5,
    "total_responses": 5,
    "avg_response_time": 1250,
    "session_duration": 180,
    "error_count": 0
  }
}
```

### Analytics Data Export

```http
GET /admin/tenants/{tenant}/widget-analytics/export?start_date=2024-01-01&end_date=2024-01-31

Response: CSV file download
```

## 🚀 Setup e Configurazione

### 1. Database Migration
```bash
php artisan migrate
```

### 2. Widget Configuration
```javascript
// Nel sito del cliente
window.chatbotConfig = {
    apiKey: 'your-api-key',
    tenantId: 123,
    enableAnalytics: true, // Abilita tracking
    // ... altre configurazioni
};
```

### 3. Accesso Dashboard
- URL: `/admin/widget-analytics`
- Autenticazione: Richiede token admin
- Permessi: Accesso completo a tutti i tenant

## 📈 Utilizzo e Best Practices

### Monitoraggio Performance

1. **Response Time**: Mantenere sotto 2.5s per P95
   - ✅ < 1000ms: Ottimo
   - ⚠️ 1000-2500ms: Accettabile
   - ❌ > 2500ms: Lento, require ottimizzazione

2. **Tasso Conversione**: Obiettivo > 20%
   - ✅ > 50%: Ottimo engagement
   - ⚠️ 20-50%: Buono
   - ❌ < 20%: Basso, rivedere UX/contenuti

3. **Tasso Successo**: Obiettivo > 95%
   - ✅ > 95%: Eccellente
   - ⚠️ 85-95%: Buono
   - ❌ < 85%: Problemi tecnici

### Ottimizzazione Based on Analytics

#### Performance Issues
- Alto response time → Ottimizzare RAG pipeline
- Molti errori → Debug configurazione/API
- Basso engagement → Migliorare UX widget

#### Content Issues  
- Basse citazioni → Migliorare knowledge base
- Bassa confidenza → Rivedere chunking/embeddings
- Alto token usage → Ottimizzare prompt/context

## 🔒 Sicurezza e Privacy

### Data Protection
- **Session IDs**: Anonimi, non trackano identità
- **IP Addresses**: Hashati per compliance GDPR
- **User Queries**: Opzionalmente excluded from logging
- **Data Retention**: Configurabile per tenant

### API Security
- Autenticazione Bearer token required
- Rate limiting su tracking endpoints
- Tenant isolation garantita
- Input validation e sanitization

## 🛠️ Maintenance e Monitoring

### Database Cleanup
```bash
# Script automatico cleanup eventi vecchi
php artisan widget:cleanup-events --days=90
```

### Performance Monitoring
- Monitor dimensioni tabella `widget_events`
- Index ottimizzati per query dashboard
- Query caching per dashboard rapide

### Alerts e Notifications
- Alert se error rate > 10%
- Notification se response time > 5s
- Report settimanali automatici

## 📋 Future Enhancements

### Planned Features
- [ ] Real-time analytics dashboard con WebSockets
- [ ] Funnel analysis per conversion tracking
- [ ] A/B testing framework per widget variants
- [ ] Predictive analytics con ML models
- [ ] Integration con Google Analytics/Mixpanel
- [ ] Alert automatici configurabili per tenant
- [ ] Analytics API per integrazioni external

### Advanced Analytics
- Heat maps di click sul widget
- User journey mapping
- Sentiment analysis dei messaggi
- Performance comparison cross-tenant
- ROI calculation per widget deployment

---

**Documentazione aggiornata**: Gennaio 2024  
**Versione Dashboard**: 1.0.0  
**Compatibilità**: Laravel 11, PHP 8.2+
