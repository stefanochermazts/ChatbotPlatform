# Analisi funzionale – Agent Console & Human Handoff

## 1. Scopo

Estendere la piattaforma chatbot con un **pannello di controllo per operatori umani**, che consenta:

* Visualizzazione in tempo reale di tutte le conversazioni tra utenti e chatbot.
* Subentro manuale di un operatore nella chat (handoff).
* Esclusione temporanea del chatbot durante l’intervento umano.
* Riconsegna del controllo al chatbot una volta conclusa l’interazione.

## 2. Attori

* **Utente finale**: interagisce via widget/chatbot.
* **Chatbot (AI)**: risponde normalmente alle richieste.
* **Operatore umano**: interviene su segnalazione o richiesta esplicita dell’utente.
* **Supervisore/Admin**: gestisce permessi e monitora le performance.

## 3. Architettura generale

* **Frontend utente** (widget web/app): mostra conversazione e segnala quando risponde un operatore invece dell’AI.
* **Backend API**: gestisce routing messaggi, stato della sessione (AI vs Human), log conversazioni, notifiche.
* **Agent Console (frontend operatori)**: dashboard multitenant dove operatori vedono le chat aperte, possono prendere in carico e rilasciare.
* **Event bus/queue**: notifica in tempo reale lo stato delle conversazioni (es. WebSocket, Laravel Echo, Redis pub/sub).

## 4. Caso d’uso principale – Human Handoff

### Scenario

1. L’utente conversa normalmente con il chatbot.
2. L’utente scrive un messaggio come “Vorrei parlare con un operatore”.
3. Il sistema riconosce la richiesta e mette la conversazione in stato **“handoff pending”**.
4. L’operatore, tramite l’Agent Console, vede la chat in elenco e clicca “Prendi in carico”.
5. Lo stato diventa **“human active”**: tutti i messaggi utente vanno solo all’operatore.
6. Il chatbot smette di rispondere (messo in pausa per quella conversazione).
7. Operatore e utente chattano direttamente.
8. L’operatore clicca “Rilascia al bot” → stato torna **“bot active”**.
9. Il chatbot riprende la gestione della conversazione.

### Varianti

* **Auto-handoff**: il sistema può suggerire un passaggio a operatore (es. confidenza < soglia, messaggi di frustrazione).
* **Riassegnazione**: un supervisore può spostare la chat da un operatore a un altro.

## 5. Funzionalità del pannello operatori

* Elenco conversazioni in tempo reale (filtri: tenant, stato, priorità).
* Notifiche nuove richieste di handoff.
* Vista dettagliata della chat con cronologia (messaggi bot/utente/operatori).
* Pulsanti “Prendi in carico” / “Rilascia al bot”.
* Indicatori di stato (bot attivo, handoff pending, human attivo).
* Statistiche base: tempo medio di presa in carico, durata interazioni umane, % chat concluse dal bot.

## 6. Requisiti non funzionali

* **Realtime**: aggiornamento <1s per nuove chat.
* **Affidabilità**: nessuna perdita di messaggi in transizione (bot↔human).
* **Scalabilità**: supporto decine di operatori simultanei.
* **Audit**: log completo di chi ha preso in carico e quando.
* **UX**: console responsive, accessibile (WCAG 2.1 AA).

## 7. Sicurezza & Ruoli

* Solo utenti con ruolo **Operatore** o **Supervisore** accedono alla console.
* Autorizzazioni granulari: un operatore può intervenire solo su conversazioni del proprio tenant.
* Supervisore può vedere tutte le chat, riassegnare, generare report.
* Tutte le azioni di handoff loggate con timestamp.

## 8. Benefici attesi

* Maggior soddisfazione utente (possibilità di parlare con umani).
* Riduzione dei casi di frustrazione/abbandono.
* Monitoraggio qualità chatbot.
* Supporto a casi complessi o sensibili non gestibili dall’AI.

## 9. Criteri di accettazione (MVP)

* Operatore può vedere nuove richieste in <1s.
* Una volta preso in carico, il bot non deve più rispondere finché la chat non è rilasciata.
* Tutti i messaggi (bot/utente/operatore) loggati e distinguibili.
* Utente finale deve vedere chiaramente quando risponde un operatore vs il bot.
