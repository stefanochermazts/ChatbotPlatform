# Fix: Errore 422 Agent Console - Fallback Session ID

## Problema

Il widget generava errori 422 (Unprocessable Content) quando provava a inviare messaggi all'agent console:

```
:8443/api/v1/conversations/messages/send:1  Failed to load resource: the server responded with a status of 422 (Unprocessable Content)
🚨 Failed to send message to agent console: 422
```

### Causa Root

Quando `ConversationTracker.startSession()` falliva (errore di rete, configurazione errata, ecc.), invece di bloccare l'invio, creava un **session ID fallback**:

```javascript
// BUGGY CODE
catch (error) {
  console.error('🚨 Failed to start agent session:', error);
  this.agentSessionId = 'fallback_' + Date.now(); // ❌ "fallback_1730818901000"
  return { sessionId: this.agentSessionId, status: 'bot_only' };
}
```

Questo ID fallback **NON esisteva** nel database, quindi:

1. Widget prova a inviare messaggio con `session_id: "fallback_1730818901000"`
2. Backend valida con `exists:conversation_sessions,session_id`
3. Validation fallisce → 422 error
4. Widget mostra l'errore nel console

### Perché "la seconda volta funziona"?

Probabilmente perché:
- Al secondo tentativo il server ha qualche cache o
- Dopo il primo fallimento, qualche altro flusso crea correttamente la sessione
- O l'utente ricarica la pagina e la sessione viene ripristinata da localStorage

## Soluzione Implementata

### 1. Non creare fallback session ID

```javascript
// FIXED CODE
catch (error) {
  console.error('🚨 Failed to start agent session:', error);
  // 🔧 FIX: Don't create fallback ID - it will cause 422 errors
  // The widget will still work for chatbot, just not for agent console tracking
  this.agentSessionId = null;
  return null;
}
```

### 2. Track session creation failure

Aggiunto flag `sessionStartFailed` nel constructor:

```javascript
constructor(options = {}) {
  this.agentSessionId = null;
  this.handoffStatus = 'bot_only';
  this.operatorInfo = null;
  this.sessionStartFailed = false; // 🔧 NEW: Track if session creation failed
  // ...
}
```

### 3. Evita invii se sessione non creata

```javascript
async sendMessage(content, senderType = 'user') {
  // 🔧 FIX: Only try to start session if we don't have one AND it's not already failed
  if (!this.agentSessionId && !this.sessionStartFailed) {
    const result = await this.startSession();
    if (!result || !result.sessionId) {
      console.warn('🚨 Agent session start failed, skipping agent console integration');
      this.sessionStartFailed = true;
      return; // Don't try to send to agent console
    }
  }

  // 🔧 FIX: Don't try to send if session creation failed
  if (!this.agentSessionId || this.sessionStartFailed) {
    console.log('🎯 Agent console disabled, skipping message send to agent console');
    return;
  }
  
  // ... proceed with send
}
```

### 4. Stesso fix per requestHandoff

```javascript
async requestHandoff(reason = 'user_request', priority = 'normal') {
  // 🔧 FIX: Don't attempt if session creation already failed
  if (!this.agentSessionId && !this.sessionStartFailed) {
    const result = await this.startSession();
    if (!result || !result.sessionId) {
      this.sessionStartFailed = true;
      return false;
    }
  }
  
  // 🔧 FIX: Can't request handoff if session creation failed
  if (this.sessionStartFailed) {
    console.warn('🚨 Cannot request handoff: Agent console session unavailable');
    return false;
  }
  
  // ... proceed with handoff
}
```

## Risultato

✅ **Widget funziona normalmente**: Il chatbot continua a funzionare anche se l'agent console non è disponibile
✅ **Nessun errore 422**: Non vengono più inviati messaggi con session ID inesistenti
✅ **Comportamento graceful**: Fallisce silenziosamente con log informativi invece di errori 422
✅ **Performance migliorata**: Evita tentativi ripetuti di creare sessioni quando fallisce

### Logs Previsti

Se `startSession()` fallisce:
```
🚨 Failed to start agent session: [error details]
🚨 No agent session active, starting one...
🚨 Agent session start failed, skipping agent console integration
🎯 Agent console disabled, skipping message send to agent console
```

Se tutto funziona:
```
🎯 Agent session started: [session-id]
🎯 Message sent to agent console: [message details]
```

## Files Modificati

- `backend/public/widget/js/chatbot-widget.js`:
  - Constructor: Aggiunto `this.sessionStartFailed = false`
  - `startSession()`: Restituisce `null` invece di fallback ID
  - `sendMessage()`: Controlla `sessionStartFailed` prima di inviare
  - `requestHandoff()`: Controlla `sessionStartFailed` prima di richiedere handoff

## Testing

1. ✅ Test normale: Widget funziona con agent console attivo
2. ✅ Test fallimento: Se `/conversations/start` fallisce, nessun errore 422
3. ✅ Test graceful degradation: Chatbot funziona anche senza agent console

---

**Data fix**: 2025-01-27  
**Issue**: Errore 422 ripetuto su `/api/v1/conversations/messages/send`  
**Root cause**: Fallback session ID non esistente nel database  
**Status**: ✅ Risolto

