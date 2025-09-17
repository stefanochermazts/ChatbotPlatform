# Smart Content Extraction - Sistema Intelligente

## Panoramica

Il sistema di Smart Content Extraction è un approccio generico e configurabile per estrarre automaticamente il contenuto principale da siti web, senza hard-coding per domini specifici.

## Caratteristiche Principali

### 🧠 Riconoscimento Automatico Pattern
- **Configurabile**: Pattern definiti in `config/scraper-patterns.php`
- **Prioritizzato**: Pattern ordinati per priorità di riconoscimento
- **Estendibile**: Facile aggiunta di nuovi pattern CMS/piattaforme

### 🎯 Pattern Supportati

#### 1. CMS Italiani PA
- `testolungo` - Contenitori di testo lungo (Umbraco, Drupal)
- `descrizione-modulo` - Descrizioni moduli PA

#### 2. CMS Standard
- `content-main`, `main-content` - Contenitori contenuto principale
- `article-body`, `post-content`, `entry-content` - Corpi articolo

#### 3. HTML5 Semantico
- `<main>`, `<article>` - Elementi semantici standard

#### 4. CMS Specifici
- WordPress: `entry-content`, `post-content`
- Drupal: `field-type-text`, `node-content`

## Funzionamento

### 1. Analisi Intelligente
```php
// Rilevamento automatico container semantici
if ($this->hasSemanticContentContainers($analysis)) {
    return 'manual_dom_primary'; // Usa smart extraction
}
```

### 2. Estrazione Pattern-Based
```php
foreach ($contentPatterns as $pattern) {
    if (preg_match($pattern['regex'], $html, $matches)) {
        $cleanContent = $this->processExtractedContent($matches[1]);
        if (strlen($cleanContent) >= $pattern['min_length']) {
            return ['title' => ..., 'content' => $cleanContent];
        }
    }
}
```

### 3. Pulizia Intelligente
- Rimozione automatica di nav, sidebar, footer
- Preservazione di contenuto semantico
- Normalizzazione testo e encoding

## Configurazione

### Aggiungere Nuovi Pattern

Modifica `config/scraper-patterns.php`:

```php
'content_patterns' => [
    [
        'name' => 'nuovo_cms_pattern',
        'regex' => '/<div[^>]*class="[^"]*nuovo-contenuto[^"]*"[^>]*>(.*?)<\/div>/is',
        'description' => 'Nuovo pattern CMS',
        'min_length' => 100,
        'priority' => 1  // Alta priorità
    ],
    // ... altri pattern
]
```

### Aggiungere Indicatori Semantici

```php
'semantic_indicators' => [
    'testolungo',
    'nuovo-indicator',
    'custom-content',
    // ... altri indicatori
]
```

## Vantaggi

### ✅ Genericità
- **Nessun hard-coding** per domini specifici
- **Funziona automaticamente** per siti simili
- **Estendibile** senza modifiche al codice

### ✅ Manutenibilità
- **Configurazione centralizzata** in un file
- **Pattern riutilizzabili** per CMS simili
- **Debug dettagliato** con log strutturati

### ✅ Scalabilità
- **Aggiunta pattern** senza deploy
- **Supporto multi-CMS** automatico
- **Performance ottimizzata** con priorità

## Esempi di Utilizzo

### Sito PA Italiano
```
Pattern riconosciuto: testolungo_content
Descrizione: Contenitore testo lungo (CMS italiani)
Contenuto estratto: 2105 caratteri
✅ Successo automatico
```

### Sito WordPress
```
Pattern riconosciuto: wordpress_content
Descrizione: Contenuto WordPress
Contenuto estratto: 1850 caratteri
✅ Successo automatico
```

### Sito HTML5 Semantico
```
Pattern riconosciuto: main_semantic
Descrizione: Elemento HTML5 main
Contenuto estratto: 1200 caratteri
✅ Successo automatico
```

## Log e Debug

Il sistema fornisce log dettagliati:

```log
[2025-09-17] local.INFO: 🎯 Smart extraction successful {
    "pattern": "testolungo_content",
    "description": "Contenitore testo lungo (CMS italiani)",
    "content_length": 2105,
    "content_preview": "Da LUNEDÌ 29 SETTEMBRE..."
}
```

## Fallback Strategy

Se nessun pattern smart funziona:
1. **Readability.php** per contenuto articoli
2. **Manual DOM** per tabelle/strutture
3. **Body extraction** come ultimo resort

Questo garantisce che il sistema funzioni sempre, anche per siti non ancora supportati.
