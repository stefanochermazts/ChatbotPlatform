# TODO: Agent Console & Human Handoff Implementation

## 📋 **Roadmap completa per implementazione Agent Console**

Basato su: [analisi-funzionale-agent.md](./analisi-funzionale-agent.md)

---

## 🗄️ **FASE 1: Database & Modelli**

### 1.1 Migrazioni Database
- [ ] **Migration**: Tabella `conversation_sessions`
  - `id`, `tenant_id`, `user_id`, `widget_session_id`, `status` (bot_active/handoff_pending/human_active/closed)
  - `started_at`, `last_activity_at`, `closed_at`
  - `assigned_operator_id` (nullable)
  - Indici: `tenant_id`, `status`, `assigned_operator_id`

- [ ] **Migration**: Tabella `conversation_messages`
  - `id`, `conversation_session_id`, `message_type` (user/bot/operator/system)
  - `sender_id` (user_id o operator_id), `content`, `sent_at`
  - `metadata` (JSON: confidence, citations, etc.)
  - Indici: `conversation_session_id`, `sent_at`

- [ ] **Migration**: Tabella `handoff_requests`
  - `id`, `conversation_session_id`, `requested_at`, `status` (pending/accepted/declined)
  - `requested_by` (user/auto_system), `assigned_to` (operator_id), `resolved_at`
  - `priority` (low/medium/high), `reason` (user_request/low_confidence/escalation)

- [ ] **Migration**: Aggiorna tabella `users` per ruoli operatori
  - Aggiungere `role` enum: `admin`, `customer`, `operator`, `supervisor`
  - Aggiungere `operator_tenant_ids` (JSON) per autorizzazioni granulari

### 1.2 Modelli Eloquent
- [ ] **Model**: `ConversationSession` con relazioni
  - `belongsTo(Tenant)`, `belongsTo(User, 'assigned_operator_id')`
  - `hasMany(ConversationMessage)`, `hasMany(HandoffRequest)`
  - Scope: `active()`, `pendingHandoff()`, `assignedTo($operatorId)`

- [ ] **Model**: `ConversationMessage` con cast JSON
  - `belongsTo(ConversationSession)`, `belongsTo(User, 'sender_id')`
  - Cast: `metadata` → `array`
  - Scope: `byType($type)`, `recent()`

- [ ] **Model**: `HandoffRequest`
  - `belongsTo(ConversationSession)`, `belongsTo(User, 'assigned_to')`
  - Scope: `pending()`, `forTenant($tenantId)`

### 1.3 Policies Autorizzazione
- [ ] **Policy**: `ConversationSessionPolicy`
  - `viewAny()`: solo operatori/supervisori
  - `view()`: solo conversazioni del proprio tenant (operatori) o tutte (supervisori)
  - `takeControl()`, `release()`: solo operatori autorizzati

- [ ] **Policy**: `HandoffRequestPolicy`
  - `accept()`, `decline()`: solo operatori del tenant giusto

---

## ⚙️ **FASE 2: Backend API & Services**

### 2.1 Services Core
- [ ] **Service**: `ConversationTrackingService`
  - `startSession($tenantId, $userId, $widgetSessionId): ConversationSession`
  - `logMessage($sessionId, $type, $senderId, $content, $metadata = [])`
  - `getActiveSession($widgetSessionId): ?ConversationSession`

- [ ] **Service**: `HandoffService`
  - `requestHandoff($sessionId, $reason, $priority = 'medium'): HandoffRequest`
  - `acceptHandoff($requestId, $operatorId): bool`
  - `releaseToBot($sessionId, $operatorId): bool`
  - `detectHandoffTriggers($userMessage): bool` (pattern matching)

- [ ] **Service**: `OperatorNotificationService`
  - `notifyNewHandoffRequest($request)`
  - `notifySessionUpdate($session)`
  - Integrazione con Laravel Echo/WebSocket

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
