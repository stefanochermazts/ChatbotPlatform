# TODO: Agent Console & Human Handoff Implementation

## ✅ **Status & Progress Tracking**

🎉 **FASE 3 COMPLETATA**: Widget Modifications  
📊 **Progresso complessivo**: 37.5% (3/8 fasi completate)

### 🎯 **FASI COMPLETATE:**
- ✅ **FASE 1**: Database & Models (100% completato)
- ✅ **FASE 2**: Backend API & Services (100% completato)
- ✅ **FASE 3**: Widget Modifications (100% completato)

### 🚧 **PROSSIMO STEP**: 
**FASE 4**: Operator Console (0% completato)

---

## 📋 **Roadmap completa per implementazione Agent Console**

Basato su: [analisi-funzionale-agent.md](./analisi-funzionale-agent.md)

---

## ✅ **FASE 1: Database & Modelli** - COMPLETATA

### 1.1 Migrazioni Database
- [x] **Migration**: Tabella `conversation_sessions`
  - `id`, `tenant_id`, `user_id`, `widget_session_id`, `status` (bot_active/handoff_pending/human_active/closed)
  - `started_at`, `last_activity_at`, `closed_at`
  - `assigned_operator_id` (nullable)
  - Indici: `tenant_id`, `status`, `assigned_operator_id`

- [x] **Migration**: Tabella `conversation_messages`
  - ✅ Implementato: `id`, `conversation_session_id`, `sender_type`, `content`, `sent_at`
  - ✅ Aggiunto: `metadata` (JSON), `citations`, `confidence`, `is_helpful`
  - ✅ Indici e relazioni configurate

- [x] **Migration**: Tabella `handoff_requests` 
  - ✅ Implementato: `id`, `conversation_session_id`, `status`, `priority`
  - ✅ Aggiunto: `trigger_type`, `reason`, `routing_criteria`, `sla_target`
  - ✅ Escalation support e metrics tracking

- [x] **Migration**: Aggiorna tabella `users` per ruoli operatori
  - ✅ Aggiunto: `user_type`, `is_operator`, `operator_status`, `operator_skills`
  - ✅ Aggiunto: `operator_permissions`, `work_schedule`, `notification_settings`
  - ✅ Metrics: `total_conversations_handled`, `average_response_time_minutes`

### 1.2 Modelli Eloquent
- [x] **Model**: `ConversationSession` con relazioni
  - ✅ Implementato: `belongsTo(Tenant)`, `belongsTo(User, 'assigned_operator_id')`
  - ✅ Implementato: `hasMany(ConversationMessage)`, `hasMany(HandoffRequest)`
  - ✅ Scopes: `active()`, `pendingHandoff()`, `assignedTo($operatorId)`

- [x] **Model**: `ConversationMessage` con cast JSON
  - ✅ Implementato: `belongsTo(ConversationSession)`, `belongsTo(User, 'sender_id')`
  - ✅ Cast: `metadata`, `citations` → `array`
  - ✅ Scopes: `byType($type)`, `recent()`, helper methods

- [x] **Model**: `HandoffRequest`
  - ✅ Implementato: `belongsTo(ConversationSession)`, `belongsTo(User, 'assigned_to')`
  - ✅ Scopes: `pending()`, `forTenant($tenantId)`, `byPriority()`
  - ✅ Helper methods: `getAgeInMinutes()`, `isOverdue()`

### 1.3 Policies Autorizzazione
- [x] **Policy**: Autorizzazione integrata nei controller
  - ✅ Tenant scoping automatico in tutti i controller
  - ✅ Operator authentication via middleware `OperatorAuth`
  - ✅ Verifiche permessi su conversazioni e handoff

---

## ✅ **FASE 2: Backend API & Services** - COMPLETATA

### 2.1 Controllers API
- [x] **ConversationController**: Gestione sessioni conversazione
  - ✅ `start()`: Crea nuova sessione - API: `/api/v1/conversations/start`
  - ✅ `show()`: Dettagli sessione - API: `/api/v1/conversations/{sessionId}`
  - ✅ `end()`: Chiudi sessione - API: `/api/v1/conversations/{sessionId}/end`
  - ✅ `status()`: Status sessione - API: `/api/v1/conversations/{sessionId}/status`

- [x] **MessageController**: Gestione messaggi
  - ✅ `send()`: Invia messaggio - API: `/api/v1/conversations/messages/send`
  - ✅ `index()`: Lista messaggi - API: `/api/v1/conversations/{sessionId}/messages`
  - ✅ `feedback()`: Feedback messaggio - API: `/api/v1/conversations/messages/{id}/feedback`
  - ✅ `edit()`: Modifica messaggio - API: `/api/v1/conversations/messages/{id}/edit`

- [x] **HandoffController**: Gestione escalation
  - ✅ `request()`: Richiedi handoff - API: `/api/v1/handoffs/request`
  - ✅ `assign()`: Assegna handoff - API: `/api/v1/handoffs/{id}/assign`
  - ✅ `resolve()`: Risolvi handoff - API: `/api/v1/handoffs/{id}/resolve`
  - ✅ `escalate()`: Escalation - API: `/api/v1/handoffs/{id}/escalate`

- [x] **OperatorController**: Gestione operatori
  - ✅ `available()`: Lista operatori - API: `/api/v1/operators/available`
  - ✅ `updateStatus()`: Update status - API: `/api/v1/operators/status`
  - ✅ `conversations()`: Conversazioni operatore - API: `/api/v1/operators/{id}/conversations`
  - ✅ `heartbeat()`: Keep-alive - API: `/api/v1/operators/heartbeat`

### 2.2 Services Business Logic
- [x] **HandoffService**: Logica escalation bot→operatore
  - ✅ `requestHandoff()`: Gestione richieste handoff con priorità
  - ✅ `assignHandoff()`: Assegnazione automatica e manuale
  - ✅ `resolveHandoff()`: Chiusura e metrics
  - ✅ `escalateHandoff()`: Escalation supervisor
  - ✅ Metrics e SLA tracking integrato

- [x] **OperatorRoutingService**: Algoritmo assegnazione intelligente
  - ✅ `findAvailableOperators()`: Filtri skill, workload, schedule
  - ✅ `scoreOperator()`: Scoring basato su skills, performance, carico
  - ✅ `assignHandoffAutomatically()`: Auto-assignment con fallback

### 2.3 Security & Authentication
- [x] **OperatorAuth Middleware**: Autenticazione operatori
  - ✅ Verifica ruolo operatore e permessi tenant
  - ✅ Update `last_seen_at` per presence tracking
  - ✅ Scoping automatico per sicurezza multitenancy

### 2.4 WebSocket & Real-time Events
- [x] **ConversationMessageSent Event**: Broadcast messaggi
  - ✅ Channel privati per sessioni e tenant operatori
  - ✅ Payload con messaggio e context sessione
  - ✅ Filtering per recipients appropriati

- [x] **HandoffRequested Event**: Notifiche handoff
  - ✅ Broadcast real-time a operatori tenant
  - ✅ Priorità e urgency per routing notifications
  - ✅ Payload con context completo per triage

### 2.5 API Routes & Testing
- [x] **Route Configuration**: Tutte le routes API configurate
  - ✅ Namespace corretto e middleware applicati
  - ✅ Public routes: `/conversations/*`
  - ✅ Protected routes: `/handoffs/*`, `/operators/*`

- [x] **Testing & Validation**: API completamente funzionali
  - ✅ Test conversation start: ✅ 201 Created
  - ✅ Test message send: ✅ 201 Created
  - ✅ Test message retrieval: ✅ 200 OK
  - ✅ Database operations: ✅ Models funzionanti

---

## ✅ **FASE 3: Widget Modifications** - COMPLETATA

### 3.1 Widget Conversation Tracking
- [x] **ConversationTracker Class**: Gestione sessioni Agent Console
  - ✅ `startSession()`: Inizializza sessione Agent Console via API
  - ✅ `sendMessage()`: Tracking automatico messaggi user/bot
  - ✅ `requestHandoff()`: Richiesta escalation a operatore umano
  - ✅ Persistent session management con localStorage
  - ✅ Error handling e fallback per API non disponibili

- [x] **Widget Integration**: Integrazione seamless con existing workflow
  - ✅ Auto-initialization durante widget startup
  - ✅ Tracking automatico messaggi in `sendMessage()` e `processMessage()`
  - ✅ Event-driven communication tra UI e backend services
  - ✅ Graceful degradation se Agent Console non disponibile

### 2.2 API Controllers
- [ ] **Controller**: `Api\ConversationController`
  - `POST /api/v1/conversations/start` (widget)
  - `POST /api/v1/conversations/{id}/messages` (widget + operator)
  - `GET /api/v1/conversations/{id}/messages` (operator console)

- [ ] **Controller**: `Api\HandoffController`
  - `POST /api/v1/conversations/{id}/request-handoff` (widget)
  - `POST /api/v1/handoff/{id}/accept` (operator)
  - `POST /api/v1/conversations/{id}/release` (operator)

- [ ] **Controller**: `Admin\OperatorConsoleController`
  - `GET /admin/operator-console` (dashboard)
  - `GET /api/admin/conversations` (list with filters)
  - `GET /api/admin/conversations/{id}` (detail view)

### 2.3 Middleware & Validazione
- [ ] **Middleware**: `OperatorAuth`
  - Verifica ruolo `operator` o `supervisor`
  - Verifica accesso tenant per operatori

- [ ] **FormRequest**: `HandoffRequestValidation`
  - Validazione `reason`, `priority`, `conversation_id`

- [ ] **FormRequest**: `SendMessageValidation`
  - Validazione contenuto messaggio, rate limiting

---

## 🎨 **FASE 3: Frontend - Widget Modifications**

### 3.1 Widget State Management
- [ ] **JS**: Estendere `chatbot-widget.js`
  - Aggiungere `conversationSession` object tracking
  - Stato: `bot_active`, `handoff_pending`, `human_active`
  - Indicatori visivi per tipo di risposta (bot vs operatore)

- [ ] **CSS**: Stili distintivi per messaggi operatore
  - `.message-operator` con colore diverso da `.message-bot`
  - Badge "👤 Operatore" vs "🤖 Assistant"
  - Stato di typing per operatori

### 3.2 Widget UX Features
- [ ] **Feature**: Pulsante "Parla con operatore"
  - Trigger handoff request via API
  - Disabilitazione durante `handoff_pending`

- [ ] **Feature**: WebSocket connection per real-time
  - Integrazione Laravel Echo
  - Aggiornamenti stato sessione
  - Nuovi messaggi operatore in tempo reale

- [ ] **Feature**: Coda di attesa
  - Mostra posizione in coda durante `handoff_pending`
  - Tempo stimato di attesa

---

## 🖥️ **FASE 4: Operator Console (Frontend)**

### 4.1 Layout Console
- [ ] **View**: `admin/operator-console/dashboard.blade.php`
  - Layout full-width (come admin UI modificata)
  - Sidebar con filtri (tenant, stato, priorità)
  - Lista conversazioni in real-time

- [ ] **View**: `admin/operator-console/conversation.blade.php`
  - Vista chat dettagliata
  - History messaggi bot/utente/operatori
  - Panel controlli (take/release)

### 4.2 Componenti Livewire
- [ ] **Component**: `ConversationsList`
  - Lista conversazioni filtrabili
  - Auto-refresh ogni 2s o via WebSocket
  - Badges stato, priorità, tempo attesa

- [ ] **Component**: `ConversationDetail`
  - Chat interface completa
  - Form invio messaggio
  - Timeline eventi (handoff, release, etc.)

- [ ] **Component**: `OperatorControls`
  - Pulsanti "Prendi in carico", "Rilascia al bot"
  - Assignment ad altri operatori (supervisori)
  - Quick actions (templates comuni)

### 4.3 Real-time Features
- [ ] **JS**: Laravel Echo integration
  - Channel `operator-console.{tenantId}`
  - Eventi: `NewHandoffRequest`, `SessionUpdated`, `MessageReceived`

- [ ] **UX**: Notifiche browser
  - Permission request per notifiche
  - Sound alerts per nuove richieste

---

## 🔄 **FASE 5: Integration & Event Handling**

### 5.1 ChatBot Integration
- [ ] **Modifiche**: `ChatCompletionsController`
  - Check session status prima di rispondere
  - Se `human_active` → non processare con AI
  - Se `handoff_pending` → messaggio di attesa

- [ ] **Modifiche**: `HandoffDetection` in message processing
  - Pattern matching per frasi come "operatore", "umano"
  - Auto-trigger handoff per confidence < threshold
  - Integration con `HandoffService`

### 5.2 Events & Listeners
- [ ] **Event**: `HandoffRequested`
  - Payload: `ConversationSession`, `HandoffRequest`
  - Dispatch to operator notification service

- [ ] **Event**: `HandoffAccepted`, `HandoffReleased`
  - Update session status
  - Notify widget via WebSocket

- [ ] **Listener**: `NotifyOperators`
  - Send real-time notifications
  - Update operator console UI

### 5.3 Queue Jobs
- [ ] **Job**: `ProcessHandoffRequest`
  - Background processing della richiesta
  - Assignment automatico se configurato
  - Timeout handling

- [ ] **Job**: `CleanupOldSessions`
  - Cleanup sessioni inattive > 24h
  - Archive completed conversations

---

## 📊 **FASE 6: Analytics & Monitoring**

### 6.1 Metrics & Reports
- [ ] **Service**: `OperatorAnalyticsService`
  - Tempo medio risposta operatori
  - % chat risolte da bot vs operatore
  - Satisfaction scores post-handoff

- [ ] **View**: `admin/operator-console/analytics.blade.php`
  - Dashboard metriche operatori
  - Charts con Chart.js
  - Export report (CSV/PDF)

### 6.2 Audit Trail
- [ ] **Model**: `OperatorAction`
  - Log ogni azione: take, release, message
  - Timestamp, operator_id, conversation_id, action_type

- [ ] **View**: `admin/operator-console/audit.blade.php`
  - Cronologia azioni operatori
  - Filtri per data, operatore, azione

---

## 🔐 **FASE 7: Security & Testing**

### 7.1 Security Features
- [ ] **Rate Limiting**: API endpoints operatori
- [ ] **CSRF Protection**: Form submissions
- [ ] **Authorization**: Granular permissions
- [ ] **Audit Logging**: Tutte le azioni critiche
- [ ] **Data Privacy**: GDPR compliance per chat logs

### 7.2 Testing
- [ ] **Unit Tests**: Services e Models (Pest)
- [ ] **Feature Tests**: API endpoints
- [ ] **Browser Tests**: Operator console UI
- [ ] **Load Tests**: WebSocket connections multiple

---

## 🚀 **FASE 8: Deployment & Production**

### 8.1 Infrastructure
- [ ] **WebSocket**: Laravel Echo Server o Pusher
- [ ] **Redis**: Per pub/sub e session storage
- [ ] **Queue Workers**: Supervisord per background jobs
- [ ] **Monitoring**: Log aggregation per operator actions

### 8.2 Documentation
- [ ] **Docs**: Operator manual (getting started)
- [ ] **Docs**: API documentation (OpenAPI)
- [ ] **Docs**: Architecture overview
- [ ] **Docs**: Troubleshooting guide

---

## 📋 **Criteri di Accettazione**

### MVP Requirements
- [ ] ✅ Operatore vede nuove richieste in <1s
- [ ] ✅ Bot non risponde durante human_active
- [ ] ✅ Tutti i messaggi loggati e distinguibili
- [ ] ✅ Widget mostra chiaramente bot vs operatore
- [ ] ✅ Handoff request/accept/release workflow completo
- [ ] ✅ Real-time notifications funzionanti
- [ ] ✅ Authorization per tenant correttamente implementata

### Nice-to-Have (Post-MVP)
- [ ] 🎯 Auto-assignment operatori
- [ ] 🎯 Template risposte comuni
- [ ] 🎯 File/image sharing in chat
- [ ] 🎯 Customer satisfaction survey post-chat
- [ ] 🎯 Advanced analytics dashboard
- [ ] 🎯 Mobile app per operatori

---

## 📅 **Timeline Stimata**

| Fase | Durata | Complessità |
|------|--------|-------------|
| Database & Models | 3-4 giorni | 🟡 Media |
| Backend API | 5-7 giorni | 🔴 Alta |
| Widget Modifications | 3-4 giorni | 🟡 Media |
| Operator Console | 7-10 giorni | 🔴 Alta |
| Integration & Events | 4-5 giorni | 🔴 Alta |
| Analytics | 2-3 giorni | 🟢 Bassa |
| Security & Testing | 3-4 giorni | 🟡 Media |
| Deploy & Docs | 2-3 giorni | 🟢 Bassa |

**Totale stimato: 29-40 giorni** (circa 6-8 settimane)

---

## 🎯 **Priorità di Sviluppo**

1. **CRITICO**: Fase 1-2 (Database + API core)
2. **ALTO**: Fase 3-4 (Widget + Console basic)
3. **MEDIO**: Fase 5 (Integration + Events)
4. **BASSO**: Fase 6-8 (Analytics + Polish)

Una volta completate le fasi 1-4, si avrà un **MVP funzionante** dell'Agent Console.
