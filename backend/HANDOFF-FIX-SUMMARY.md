# 🔧 Handoff Operator System - Bug Fixes

**Data**: 2025-10-14  
**Issue**: Sistema handoff operatore non funzionante (notifiche non arrivano, presa controllo fallisce)

---

## 🐛 Problemi Identificati

### 1. **Evento HandoffRequested Non Emesso** ❌
**File**: `backend/app/Services/HandoffService.php`  
**Problema**: L'evento `HandoffRequested` esiste ma non viene mai emesso dopo la creazione dell'handoff.  
**Impatto**: Nessuna notifica arriva agli operatori quando viene richiesto un handoff.

**Fix Applicato**:
```php
// Riga 60 - Aggiunto dopo createHandoffSystemMessage()
event(new \App\Events\HandoffRequested($handoffRequest));
```

---

### 2. **Metodo Service Sbagliato nel Controller** ❌
**File**: `backend/app/Http/Controllers/Admin/OperatorConsoleController.php`  
**Problema**: Il controller chiama `assignHandoff()` ma il service ha `assignToOperator()`.  
**Impatto**: Errore quando l'operatore prova ad assegnarsi un handoff dalla lista.

**Fix Applicato**:
```php
// Riga 194
// PRIMA: $success = $this->handoffService->assignHandoff($handoff, $operator);
// DOPO:
$success = $this->handoffService->assignToOperator($handoff, $operator);
```

---

### 3. **Campo Database Sbagliato** ❌
**File**: `backend/app/Http/Controllers/Admin/OperatorConsoleController.php`  
**Problema**: Nel metodo `takeOverConversation()`, usa `'assigned_to'` invece di `'assigned_operator_id'`.  
**Impatto**: Pagina si ricarica senza fare nulla perché l'insert nella tabella `handoff_requests` fallisce silenziosamente.

**Fix Applicato**:
```php
// Riga 314
// PRIMA: 'assigned_to' => $operator->id,
// DOPO:
'assigned_operator_id' => $operator->id,  // ✅ Fixed
```

---

### 4. **Query SQL Malformata nella View** ❌
**File**: `backend/resources/views/admin/operator-console/handoffs.blade.php`  
**Problema**: Query usa `DB::raw('max_concurrent_conversations')` come valore invece di nome colonna.  
**Impatto**: La query fallisce e nessun operatore viene mai considerato disponibile.

**Fix Applicato**:
```php
// Riga 156
// PRIMA:
$availableOperator = User::where('is_operator', true)
    ->where('operator_status', 'available')
    ->where('current_conversations', '<', DB::raw('max_concurrent_conversations'))
    ->first();

// DOPO:
$availableOperator = User::where('is_operator', true)
    ->where('operator_status', 'available')
    ->whereRaw('current_conversations < max_concurrent_conversations')
    ->first();
```

---

## ✅ Flusso Corretto Ora

### Widget → Backend
1. Widget chiama `POST /api/v1/conversations/handoff/request`
2. `HandoffController->request()` valida e chiama `HandoffService->requestHandoff()`
3. `HandoffService` crea il record `HandoffRequest` e **emette evento** `HandoffRequested`
4. Evento broadcast su canali:
   - `tenant.{tenant_id}.operators` (private channel)
   - `handoffs.urgent` (private channel per priorità alta)

### Admin Console → Notifiche
1. Operatore autenticato si subscribe ai canali privati
2. Laravel Reverb/Pusher riceve broadcast e invia via WebSocket
3. Frontend admin riceve evento `handoff.requested` e mostra notifica

### Admin Console → Presa Controllo
1. Operatore clicca "Prendi Controllo" su conversazione
2. Form POST a `admin.operator-console.conversations.takeover`
3. `OperatorConsoleController->takeOverConversation()`:
   - Verifica che operatore sia disponibile
   - Aggiorna `ConversationSession`:
     - `status` → `'assigned'`
     - `handoff_status` → `'handoff_active'`
     - `assigned_operator_id` → ID operatore
   - Crea/aggiorna `HandoffRequest` con **campo corretto** `assigned_operator_id`
   - Incrementa `User->current_conversations`
   - Invia messaggio sistema
4. Redirect a dettaglio conversazione con successo

---

## 🧪 Come Testare

### Test 1: Notifica Handoff
1. Apri Admin Console come operatore (es. Stefano)
2. In un'altra tab/browser, apri il Widget chatbot
3. Nel widget, chiedi: "Voglio parlare con un operatore"
4. Clicca sul pulsante handoff nel widget
5. **EXPECTED**: Toast notification appare nell'Admin Console con il nuovo handoff

### Test 2: Presa Controllo
1. Admin Console → "Richieste Handoff" o "Conversazioni"
2. Trova conversazione con `handoff_status = 'handoff_requested'`
3. Clicca "Prendi Controllo"
4. **EXPECTED**: 
   - Redirect a pagina dettaglio conversazione
   - Messaggio successo verde
   - Interfaccia chat operatore visibile
   - Messaggio sistema nel thread: "L'operatore X ha preso in carico..."

### Test 3: Assegnazione da Lista Handoff
1. Admin Console → "Richieste Handoff"
2. Trova handoff pendente
3. Clicca "Assegna a {Nome Operatore}"
4. **EXPECTED**:
   - Messaggio successo verde
   - Handoff rimosso dalla lista pending
   - Operatore vede conversazione nella sua lista "Assigned"

---

## 🔍 Debug Commands

### Verifica Evento Broadcasted
```bash
# Tailing logs per vedere se evento viene emesso
tail -f storage/logs/laravel.log | grep "handoff.requested"
```

### Verifica Database
```sql
-- Check handoff requests
SELECT id, status, assigned_operator_id, requested_at, trigger_type
FROM handoff_requests
WHERE tenant_id = 1
ORDER BY requested_at DESC
LIMIT 10;

-- Check operatori disponibili
SELECT id, name, is_operator, operator_status, 
       current_conversations, max_concurrent_conversations
FROM users
WHERE is_operator = true;
```

### Test Manuale Broadcast (Tinker)
```php
php artisan tinker

$handoff = App\Models\HandoffRequest::latest()->first();
event(new App\Events\HandoffRequested($handoff));
```

---

## 📋 Files Modificati

1. ✅ `backend/app/Services/HandoffService.php` (riga 60)
2. ✅ `backend/app/Http/Controllers/Admin/OperatorConsoleController.php` (righe 194, 314)
3. ✅ `backend/resources/views/admin/operator-console/handoffs.blade.php` (riga 156)

---

## ⚠️ Note Importanti

### Broadcasting Configuration
- Verifica che `BROADCAST_DRIVER=reverb` (o `pusher`) in `.env`
- Verifica che Reverb/Pusher sia attivo: `php artisan reverb:start` (dev) o supervisord (prod)
- Channel autorizzati in `routes/channels.php`:
  - `tenant.{tenantId}.operators` → Solo operatori
  - `handoffs.urgent` → Solo operatori

### Operator Permissions
L'operatore deve avere:
- `is_operator = true`
- `operator_status = 'available'`
- `current_conversations < max_concurrent_conversations`

### Multitenancy
Tutti i broadcast e le query sono **tenant-scoped**. L'operatore vede solo handoff dei tenant a cui ha accesso.

---

## 🎯 Expected Behavior After Fix

✅ Quando viene richiesto handoff dal widget → Notifica toast arriva in Admin Console  
✅ Quando operatore clicca "Prendi Controllo" → Conversazione assegnata correttamente  
✅ Quando operatore clicca "Assegna a X" → Handoff assegnato senza errori  
✅ Nessun reload silenzioso senza azione  
✅ Messaggi di successo/errore chiari  

---

**Tested**: In attesa di test in produzione  
**Status**: ✅ Ready for testing

