# Fix: Source Link Format in Context Builder

## Problema Identificato

Il widget mostrava link alle fonti in formato non valido:
```
[Fonte: www.comune.sancesareo.rm.it]
```

Questo causava:
1. Link non cliccabile (non è markdown valido)
2. Parentesi quadra inclusa nell'URL durante il masking
3. URL malformato: `http://www.comune.sancesareo.rm.it]` (con `]` alla fine)

### Esempio dal log widget:
```
🔧 Replacing standalone placeholder: ###URLMASK0### → http://www.comune.sancesareo.rm.it]
```

## Causa Root

File: `backend/app/Services/RAG/ContextBuilder.php` (linea 76)

```php
// FORMATO ERRATO
$sourceInfo = "\n[Fonte: ".$c['document_source_url'].']';
```

Questo genera: `[Fonte: URL]` che **non è un link markdown valido**.

## Formato Markdown Valido

Un link markdown deve essere:
```markdown
[testo del link](URL)
```

Non:
```markdown
[testo: URL]
```

## Soluzione Implementata

File: `backend/app/Services/RAG/ContextBuilder.php`

```php
// Prima (ERRATO)
$sourceInfo = "\n[Fonte: ".$c['document_source_url'].']';

// Dopo (CORRETTO)
// 🔧 FIX: Use valid markdown link format [text](url) instead of [text: url]
$sourceInfo = "\n\n[Fonte](".$c['document_source_url'].')';
```

### Risultato
Ora genera: `[Fonte](https://www.comune.sancesareo.rm.it)` che è un link markdown valido.

## Risultato Atteso

Dopo il fix:
- Link "Fonte" cliccabile correttamente
- URL corretto senza caratteri spurii
- Parser markdown del widget processa correttamente il link
- Nessuna parentesi quadra alla fine dell'URL

## Test di Verifica

1. Query nel widget: "telefono polizia locale"
2. Verificare che il link "Fonte" alla fine sia cliccabile
3. Verificare che porti all'URL corretto senza `]` alla fine
4. Nel log widget: `🔧 Replacing standalone placeholder` non dovrebbe più includere `]`

---

**Data fix**: 2025-01-27  
**File modificato**: `backend/app/Services/RAG/ContextBuilder.php`

