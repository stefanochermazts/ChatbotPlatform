# Pattern di Estrazione Personalizzati per Tenant

## Panoramica

Il sistema ora supporta **pattern di estrazione personalizzati per ogni tenant**, permettendo di configurare regole specifiche per estrarre contenuto da siti web particolari senza modificare il codice.

## Funzionalità Implementate

### 🎯 **Configurazione per Tenant**
- Ogni tenant può definire pattern personalizzati nella sezione scraper
- Pattern specifici del tenant hanno **priorità assoluta** sui pattern globali
- Validazione automatica JSON e regex
- Backup automatico su pattern globali se quelli custom falliscono

### 🧠 **Sistema Intelligente Ibrido**
- **Pattern Tenant** (priorità 1): Regole specifiche configurate dall'admin
- **Pattern Globali** (priorità 2): Pattern automatici per CMS comuni
- **Fallback** (priorità 3): Readability.php per contenuto generico

## Come Configurare Pattern Personalizzati

### 1. **Accesso alla Configurazione**
1. Vai in **Admin → Tenants**
2. Clicca su **🕷️ Scraper** per il tenant desiderato
3. Scorri fino alla sezione **"🧠 Pattern di Estrazione Personalizzati"**

### 2. **Formato JSON**
```json
[
  {
    "name": "contenuto_principale",
    "regex": "<div[^>]*class=\"[^\"]*main-content[^\"]*\"[^>]*>(.*?)</div>",
    "description": "Contenuto principale del sito",
    "min_length": 120,
    "priority": 1
  },
  {
    "name": "articolo_news",
    "regex": "<article[^>]*class=\"[^\"]*news-item[^\"]*\"[^>]*>(.*?)</article>",
    "description": "Articoli di news",
    "min_length": 100,
    "priority": 2
  }
]
```

### 3. **Campi Pattern**

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| `name` | Identificatore univoco | `"contenuto_principale"` |
| `regex` | Espressione regolare per trovare contenuto | `"<div[^>]*class=\"[^\"]*content[^\"]*\"[^>]*>(.*?)</div>"` |
| `description` | Descrizione leggibile | `"Contenuto principale CMS"` |
| `min_length` | Lunghezza minima contenuto estratto | `100` |
| `priority` | Priorità (1 = massima) | `1` |

## Esempi Pratici

### **Sito Comunale con CMS Personalizzato**
```json
[
  {
    "name": "contenuto_pagina",
    "regex": "<div[^>]*class=\"[^\"]*page-content[^\"]*\"[^>]*>(.*?)</div>",
    "description": "Contenuto pagine comunali",
    "min_length": 150,
    "priority": 1
  },
  {
    "name": "notizie_corpo",
    "regex": "<section[^>]*class=\"[^\"]*news-body[^\"]*\"[^>]*>(.*?)</section>",
    "description": "Corpo delle notizie",
    "min_length": 100,
    "priority": 2
  }
]
```

### **E-commerce con Descrizioni Prodotti**
```json
[
  {
    "name": "descrizione_prodotto",
    "regex": "<div[^>]*class=\"[^\"]*product-description[^\"]*\"[^>]*>(.*?)</div>",
    "description": "Descrizioni prodotti",
    "min_length": 80,
    "priority": 1
  },
  {
    "name": "specifiche_tecniche",
    "regex": "<div[^>]*class=\"[^\"]*tech-specs[^\"]*\"[^>]*>(.*?)</div>",
    "description": "Specifiche tecniche",
    "min_length": 50,
    "priority": 2
  }
]
```

### **Blog/News con Layout Personalizzato**
```json
[
  {
    "name": "articolo_completo",
    "regex": "<main[^>]*class=\"[^\"]*article-main[^\"]*\"[^>]*>(.*?)</main>",
    "description": "Articolo completo",
    "min_length": 200,
    "priority": 1
  }
]
```

## Vantaggi del Sistema

### ✅ **Per Singoli Clienti**
- **Personalizzazione**: Pattern specifici per il loro CMS
- **Override**: Precedenza sui pattern automatici
- **Test**: Modalità test per verificare prima del deploy
- **Manutenzione**: No dipendenza da sviluppatori per nuovi pattern

### ✅ **Per la Piattaforma**
- **Scalabilità**: Nessun hard-coding per domini specifici
- **Manutenibilità**: Pattern configurabili senza modifiche codice
- **Genericità**: Sistema funziona per qualsiasi CMS/piattaforma
- **Fallback**: Sempre funzionante anche senza pattern custom

## Come Testare Pattern

### 1. **Test con Comando Debug**
```bash
# Prima configura i pattern nella UI, poi testa
php artisan scraper:debug-markdown "https://sito-target.it/pagina-test"
```

### 2. **Test con Progressive Scraping**
```bash
# Test limitato a poche pagine
php artisan scraper:progressive TENANT_ID --max-urls=3 --test-mode
```

### 3. **Monitoring dei Log**
```bash
# Verifica nei log quale pattern viene utilizzato
tail -f storage/logs/laravel.log | grep "Smart extraction"
```

## Risoluzione Problemi

### ❌ **Pattern Non Funziona**
- **Controlla sintassi JSON**: Usa un validatore JSON online
- **Testa regex**: Usa tool come regex101.com
- **Verifica HTML**: Controlla che la classe CSS esista nel sito
- **Check logs**: Cerca errori nei log Laravel

### ❌ **Contenuto Vuoto**
- **Min_length troppo alto**: Riduci il valore `min_length`
- **Regex troppo specifica**: Rendi la regex meno stringente
- **Contenuto dinamico**: Potrebbe servire `render_js: true`

### ❌ **Performance Lenta**
- **Troppe regex**: Limita a 3-5 pattern essenziali
- **Regex complesse**: Semplifica dove possibile
- **Priority ordering**: Metti pattern più comuni con priorità bassa

## Architettura Tecnica

### **Flusso di Estrazione**
1. **Caricamento Pattern**: Tenant custom + pattern globali
2. **Ordinamento**: Per priorità (1 = primo)
3. **Test Sequenziale**: Prova pattern uno per uno
4. **Validazione**: Controlla lunghezza minima
5. **Fallback**: Se tutto fallisce, usa Readability.php

### **Database Schema**
```sql
-- Tabella scraper_configs
ALTER TABLE scraper_configs 
ADD COLUMN extraction_patterns JSON;
```

### **Validazione Controller**
- Parsing JSON con `JSON_THROW_ON_ERROR`
- Validazione regex con `@preg_match()`
- Sanitizzazione campi obbligatori
- Log dettagliati per debug

Questa implementazione garantisce **massima flessibilità** per ogni tenant pur mantenendo **compatibilità completa** con il sistema automatico esistente.
