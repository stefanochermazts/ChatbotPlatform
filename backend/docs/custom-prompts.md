# Prompt Personalizzati per Tenant

## Panoramica

Ogni tenant/cliente può ora personalizzare i messaggi di sistema e i template del contesto per definire più precisamente come il chatbot dovrebbe comportarsi e come estrarre informazioni dalla knowledge base.

## Funzionalità

### 1. Prompt di Sistema Personalizzato (`custom_system_prompt`)

- **Campo**: `custom_system_prompt` (TEXT, nullable)
- **Scopo**: Definisce la personalità, il tono e il comportamento generale del chatbot
- **Posizione**: Viene inserito come primo messaggio `system` nella conversazione
- **Esempio**:
  ```
  Sei un assistente specializzato per il customer service della nostra azienda. 
  Rispondi sempre in modo cortese e professionale. 
  Se non trovi informazioni specifiche, indirizza l'utente al nostro supporto tecnico.
  ```

### 2. Template del Contesto KB (`custom_context_template`)

- **Campo**: `custom_context_template` (TEXT, nullable)
- **Scopo**: Personalizza come viene presentato il contesto della knowledge base all'LLM
- **Placeholder**: Usa `{context}` per inserire il contenuto effettivo della KB
- **Esempio**:
  ```
  Utilizza queste informazioni dalla nostra documentazione per rispondere alla domanda:
  {context}
  
  Fornisci sempre le citazioni alle fonti quando possibile.
  ```

## Configurazione

### Via Admin Panel

1. Accedi al pannello admin
2. Vai su "Clienti" → "Modifica" per il tenant desiderato
3. Compila i campi:
   - **Prompt di sistema personalizzato**: Il messaggio che definisce il comportamento del chatbot
   - **Template del contesto KB**: Come presentare le informazioni della knowledge base

### Via Database

```sql
-- Aggiungere prompt personalizzato
UPDATE tenants 
SET custom_system_prompt = 'Sei un assistente specializzato...',
    custom_context_template = 'Usa queste info per rispondere: {context}'
WHERE id = 1;
```

## Comportamento del Sistema

### Ordine dei Messaggi

La conversazione viene strutturata nel seguente ordine:

1. **Sistema personalizzato** (se configurato): `custom_system_prompt`
2. **Contesto KB** (se disponibile): messaggio generato da `custom_context_template` o template di default
3. **Messaggi utente/assistant**: La conversazione effettiva

### Fallback

- Se `custom_system_prompt` è vuoto/null: nessun messaggio di sistema aggiuntivo
- Se `custom_context_template` è vuoto/null: usa il template di default `"Contesto della knowledge base (compresso):\n{context}"`

## Esempi di Utilizzo

### Customer Service
```
Sistema: Sei un assistente del customer service. Rispondi sempre con empatia e professionalità.
Contesto: Ecco le informazioni dai nostri manuali di supporto: {context}
```

### E-commerce
```
Sistema: Sei un consulente di vendita esperto. Aiuta i clienti a trovare i prodotti giusti.
Contesto: Informazioni sui prodotti disponibili: {context}
```

### Supporto Tecnico
```
Sistema: Sei un tecnico specializzato. Fornisci soluzioni step-by-step chiare e precise.
Contesto: Documentazione tecnica rilevante: {context}
```

## API

### Endpoint Chat Completions

Il comportamento è trasparente per l'API `/v1/chat/completions`. I prompt personalizzati vengono automaticamente applicati in base al tenant identificato tramite API key.

### Esempio di Request/Response

La richiesta rimane identica:
```json
{
  "model": "gpt-4o-mini",
  "messages": [
    {"role": "user", "content": "Come posso resettare la password?"}
  ]
}
```

Internamente, il sistema costruisce:
```json
{
  "model": "gpt-4o-mini", 
  "messages": [
    {"role": "system", "content": "Sei un assistente del customer service..."},
    {"role": "system", "content": "Ecco le informazioni dai nostri manuali: [contenuto KB]"},
    {"role": "user", "content": "Come posso resettare la password?"}
  ]
}
```

## Migrazione

Per aggiornare un ambiente esistente:

```bash
php artisan migrate
```

Questo aggiunge i campi `custom_system_prompt` e `custom_context_template` alla tabella `tenants`.

## Best Practice

1. **Prompt di Sistema**:
   - Mantienilo conciso (max 4000 caratteri)
   - Definisci chiaramente il tono e lo stile
   - Includi istruzioni specifiche per il dominio

2. **Template Contesto**:
   - Usa sempre il placeholder `{context}`
   - Mantienilo sotto i 2000 caratteri
   - Istruisci l'LLM su come usare le informazioni

3. **Testing**:
   - Testa i prompt con diverse tipologie di domande
   - Verifica che le citazioni funzionino correttamente
   - Monitora la qualità delle risposte
