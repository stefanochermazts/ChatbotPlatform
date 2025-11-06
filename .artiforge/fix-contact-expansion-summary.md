# Fix: Contact Expansion Feature - Summary

## Problema Identificato

Il widget restituiva TUTTE le informazioni di contatto (telefoni, email, indirizzi) invece di quelle specifiche per l'entità cercata (es. "polizia locale").

### Esempio:
- **Query**: "telefono polizia locale"
- **Comportamento corretto (RAG tester)**: 1 telefono della polizia locale
- **Comportamento errato (widget)**: 8 telefoni di vari servizi

## Causa Root

Feature `contact_expansion` nel file `backend/config/rag.php` era **forzata a TRUE** (linea 115):

```php
'contact_expansion' => true, // FORZATO ATTIVO
```

Questa feature, implementata in `KbSearchService::executeContactInfoExpansion()`, cerca TUTTE le informazioni di contatto per l'entità invece di quelle specifiche richieste.

## Soluzione Implementata

### 1. Disabilitato default feature (opt-in)
File: `backend/config/rag.php`

```php
// Prima (FORZATO ATTIVO)
'contact_expansion' => true,

// Dopo (OPT-IN via env)
'contact_expansion' => filter_var(env('RAG_CONTACT_EXPANSION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
```

### 2. Aggiunto log per debugging
File: `backend/app/Services/RAG/KbSearchService.php`

```php
Log::info("🎯 [INTENT] Contact expansion check", [
    'tenant_id' => $tenantId,
    'intent_type' => $intentType,
    'contact_expansion_enabled' => $contactExpansionEnabled,
    'is_contact_intent' => in_array($intentType, ['phone', 'email', 'address']),
    'will_expand' => $contactExpansionEnabled && in_array($intentType, ['phone', 'email', 'address']),
]);
```

### 3. Cleared config cache

```bash
php artisan config:clear
```

## Risultato Atteso

Dopo il fix:
- Widget e RAG tester si comportano identicamente
- Query "telefono polizia locale" → 1 telefono specifico della polizia locale
- Query "email polizia locale" → 1 email specifica della polizia locale
- Query "indirizzo polizia locale" → 1 indirizzo specifico della polizia locale

## Come Riattivare (se necessario)

Se in futuro si vuole riattivare questa feature per un tenant specifico:

1. **Via .env globale**:
   ```bash
   RAG_CONTACT_EXPANSION_ENABLED=true
   ```

2. **Via configurazione tenant**:
   ```php
   $tenant->rag_settings = [
       'features' => [
           'contact_expansion' => true,
       ],
   ];
   ```

## Test di Verifica

1. RAG Tester: "telefono polizia locale" → verifica 1 solo risultato
2. Widget: "telefono polizia locale" → verifica stesso risultato del RAG tester
3. Verifica log per confermare `contact_expansion_enabled: false`

---

**Data fix**: 2025-01-27  
**File modificati**:
- `backend/config/rag.php`
- `backend/app/Services/RAG/KbSearchService.php`

