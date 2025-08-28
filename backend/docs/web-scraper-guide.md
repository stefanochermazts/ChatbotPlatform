# 🕷️ Web Scraper - Configurazione Avanzata e Best Practices

> **📖 Documentazione Principale**: Per una panoramica completa della gestione documenti e scraping, vedi [`doc-documenti.md`](./doc-documenti.md)

## 📋 Panoramica

Questa guida fornisce configurazioni avanzate, best practices e troubleshooting dettagliato per il Web Scraper di ChatbotPlatform.

## ⚙️ Configurazioni Avanzate

> **ℹ️ Parametri Base**: Per configurazione base (Seed URLs, Include/Exclude patterns, etc.) vedi [`doc-documenti.md`](./doc-documenti.md)

#### **🔗 Link-only Patterns** (Opzionale)
Pattern regex delle pagine "indice" per cui vuoi solo seguire i link interni senza salvare la pagina stessa come documento. **Ottimizzazione importante** per liste news, archivi, categorie, pagine di paginazione.

**Esempi comuni:**
```
/news/?$                  # Homepage news
/news/page/\d+           # Paginazione news  
/categoria/.*            # Pagine categoria
/archivio.*              # Archivi
/tag/.*                  # Pagine tag
```

**Comportamento:**
- ✅ **Estrae link** dalla pagina e li aggiunge alla coda di scraping
- ✅ **Continua ricorsione** seguendo i link trovati  
- ❌ **Non salva documento** per l'URL che matcha il pattern
- 🎯 **Risultato**: Solo gli articoli/contenuti finali vengono salvati, non le pagine di navigazione

**Benefici:**
- Riduce documenti "rumore" (liste, menu, indici)
- Migliora qualità della knowledge base
- Ottimizza risorse di storage e processing

#### **🔧 Parametri Avanzati**

- **Max Depth**: Profondità massima di crawling (default: 2)
- **Rate Limit (RPS)**: Richieste per secondo (default: 1)
- **Render JS**: Esegui JavaScript per SPA (default: false)
- **Respect Robots**: Rispetta robots.txt (default: true)

#### **🧠 Salta URL già noti** (Ottimizzazione)
- Se attivo, lo scraper non salva pagine per cui esiste già un documento con lo stesso `source_url`.
- Opzione correlata: **Recrawl dopo (giorni)**. Se impostata, le pagine verranno comunque riesaminate dopo N giorni (controllando `last_scraped_at`).
- Vantaggi: riduce richieste e parsing ripetuti di elementi comuni (menu, footer, layout).

#### **📚 Knowledge Base target** (Opzionale)
- Se impostata, i documenti creati dallo scraper verranno associati automaticamente alla KB selezionata.
- Se non impostata, verrà usata la KB di default del tenant.

#### **🧩 Multi‑Scraper per Tenant**
- Puoi creare più scraper per lo stesso tenant, ognuno con:
  - **Nome scraper**: Nome identificativo per distinguere le configurazioni
  - **Regole di ambito**: seed/include/exclude/link‑only/domains specifiche
  - **KB target**: Knowledge Base di destinazione per i documenti
  - **Frequenza (minuti)**: Intervallo di esecuzione automatica (es: 60, 1440)
  - **Flag Abilitato**: Attiva/disattiva esecuzione schedulata
- Nella UI trovi l'elenco "Scraper esistenti" per:
  - **Selezionare** e caricare configurazione nel form
  - **Eliminare** scraper non più necessari
  - **Visualizzare stato** (Attivo/Disattivo, frequenza)
- I pulsanti di esecuzione utilizzeranno lo scraper correntemente caricato nel form.

#### **🔐 Auth Headers** (Opzionale)
Headers di autenticazione:
```
Authorization: Bearer your-token-here
X-API-Key: your-api-key
Cookie: session=abc123
```

## 🔄 Gestione Multi-Scraper Avanzata

> **ℹ️ Esecuzione Base**: Per modalità esecuzione base e output documenti vedi [`doc-documenti.md`](./doc-documenti.md)

### **Configurazione Multi-Scraper per Tenant**
- **Creare nuovo scraper**: Lascia il form vuoto e imposta un nome, poi clicca "Salva Configurazione"
- **Modificare scraper esistente**: Clicca su uno scraper nella lista "Scraper esistenti" per caricarlo nel form
- **Eliminare scraper**: Usa il pulsante 🗑️ "Elimina" accanto al nome dello scraper
- **Eseguire scraper specifico**: I pulsanti eseguono lo scraper attualmente caricato/selezionato nel form

### **Sistema di Versioning Intelligente**

#### **🔍 Deduplicazione Automatica**
Lo scraper evita documenti duplicati usando:
- **Hash SHA256** del contenuto estratto
- **Controllo URL** per identificare pagine già processate
- **Timestamp** ultimo scraping per tracking

#### **📊 Comportamenti per URL**

**🆕 Prima volta**: 
- Crea nuovo documento con versione v1
- Hash contenuto salvato nel database

**🔄 Contenuto modificato**:
- Aggiorna documento esistente con nuova versione (v2, v3...)
- File precedente viene sostituito
- Re-ingestion automatica per aggiornare embedding

**⏭️ Contenuto invariato**:
- Skip creazione documento
- Aggiorna solo timestamp `last_scraped_at`
- Nessuna ri-elaborazione

#### **📈 Statistiche Scraping**
Il sistema traccia:
- **Nuovi**: Documenti mai visti prima
- **Aggiornati**: Contenuto modificato dalla versione precedente  
- **Invariati**: Contenuto identico, skip automatico

### **Ingestion Automatica**
- I documenti vengono automaticamente processati
- Chunking e embedding vengono generati
- I contenuti diventano disponibili nel RAG
- **Solo i documenti nuovi/modificati** vengono re-processati

## 🛠️ Troubleshooting Avanzato

> **ℹ️ Troubleshooting Base**: Per problemi comuni vedi [`doc-documenti.md`](./doc-documenti.md) sezione scraping

### **Contenuto JavaScript e SPA**
Per SPA o contenuto dinamico:
1. Abilita **Render JS**
2. Aumenta timeout (attualmente 30s)
3. Verifica che il contenuto sia presente dopo il rendering

### **Rate Limiting Avanzato**
Se ricevi errori 429 (Too Many Requests):
1. Riduci **Rate Limit (RPS)** a 0.5 o meno
2. Aggiungi **Auth Headers** se necessario
3. Implementa backoff esponenziale
4. Monitora response headers per limiti specifici

### **Accesso Negato e Autenticazione**
Per contenuti protetti:
1. Aggiungi **Auth Headers** appropriati
2. Usa cookies di sessione se necessario
3. Implementa rotazione token se applicabile

## 🎯 Best Practices

### **Per Siti Istituzionali/PA**
```
Seed URLs: homepage + pagine principali
Allowed Domains: solo dominio ufficiale
Include Patterns: /servizi/.*, /info/.*, /orari/.*
Exclude Patterns: /admin/.*, /login/.*, \.pdf$
Max Depth: 3
Rate Limit: 1 RPS
```

### **Per Blog/News**
```
Seed URLs: homepage + sitemap
Include Patterns: /\d{4}/.* (articoli con date)
Link-only Patterns: /news/?$, /categoria/.*, /page/\d+
Exclude Patterns: /tag/.*, /author/.*
Max Depth: 2
Rate Limit: 2 RPS
Skip Known URLs: true
Recrawl Days: 7 (per articoli aggiornati)
```

### **Per E-commerce**
```
Include Patterns: /prodotti/.*, /categoria/.*
Link-only Patterns: /categorie/?$, /catalogo.*
Exclude Patterns: /carrello/.*, /checkout/.*, /account/.*
Max Depth: 3
Rate Limit: 1 RPS
Skip Known URLs: true
Recrawl Days: 30 (per aggiornamenti prezzi/stock)
```

## ⚡ Commands CLI

### **Eseguire Scraping da CLI**
```bash
# Background
php artisan queue:work --queue=scraping

# Diretto (per debug)
php artisan tinker
>>> $scraper = app(\App\Services\Scraper\WebScraperService::class);
>>> $result = $scraper->scrapeForTenant(1, /* scraper_config_id opzionale */ null);
>>> dd($result);
```

### **Esecuzione di scraper dovuti (scheduling)**
```bash
# Dry‑run (mostra cosa verrebbe dispatchato)
php artisan scraper:run-due --dry-run

# Solo per un tenant
php artisan scraper:run-due --tenant=5

# Solo uno scraper specifico
php artisan scraper:run-due --id=123
```

### **Gestione Documenti Obsoleti**
```bash
# Pulizia documenti scraped più vecchi di 30 giorni (dry-run)
php artisan scraper:clean-old --dry-run

# Pulizia effettiva
php artisan scraper:clean-old --days=30

# Pulizia solo per un tenant specifico
php artisan scraper:clean-old --tenant=1 --days=7

# Personalizza retention period
php artisan scraper:clean-old --days=60
```

### **Monitoraggio**
```bash
# Log in tempo reale
tail -f storage/logs/laravel.log | grep -i scraping

# Stato queue
php artisan queue:monitor scraping

# Statistiche deduplicazione
php artisan tinker
>>> $stats = \App\Models\Document::where('source', 'web_scraper')->selectRaw('
    COUNT(*) as total,
    AVG(scrape_version) as avg_version,
    MAX(scrape_version) as max_version
')->first();
>>> dd($stats);
```

## 🔍 Verificare il Funzionamento

> **ℹ️ Verifica Base**: Per steps di verifica base vedi [`doc-documenti.md`](./doc-documenti.md) sezione testing

### **3. Testare nel RAG Avanzato**
1. Vai su **RAG Tester** ([`doc-rag-tester.md`](./doc-rag-tester.md))
2. Seleziona il tenant
3. Fai domande sui contenuti scrapati
4. Verifica che le citazioni includano i nuovi documenti
5. Testa con query complesse e intent specifici

## 📊 Monitoraggio Prestazioni

### **Metriche Chiave**
- **URLs visited**: Pagine effettivamente scrapate
- **Documents saved**: Documenti creati con successo
- **Ingestion success rate**: % documenti processati correttamente

### **Ottimizzazioni**
- **Rate limiting** appropriato per evitare ban
- **Content filtering** per qualità dei dati
- **Scheduling** per aggiornamenti periodici

## 🔄 Aggiornamenti Automatici

Per aggiornamenti periodici, usa lo scheduler di Laravel per eseguire gli scraper dovuti:

```bash
# Nel crontab di sistema (esegue lo scheduler ogni minuto)
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Kernel (già configurato per eseguire ogni 5 minuti):

```php
$schedule->command('scraper:run-due')->everyFiveMinutes()->withoutOverlapping();
```

## 🚨 Considerazioni Legali

1. **Rispetta sempre robots.txt** (abilitato di default)
2. **Controlla Terms of Service** del sito target
3. **Non sovraccaricare** i server (rate limiting)
4. **Rispetta copyright** e licenze di contenuto
5. **Solo contenuti pubblici** accessibili senza login

## 🧠 Configurazione Intent RAG

Il sistema supporta intent configurabili per ogni tenant che influenzano come i documenti scrapati vengono utilizzati nel RAG:

### **Intent Disponibili**
- **📞 Telefono**: Ricerca numeri di telefono, centralino, call center
- **📧 Email**: Ricerca indirizzi email, PEC, posta istituzionale  
- **📍 Indirizzo**: Ricerca indirizzi, sede legale, ubicazione
- **🕐 Orari**: Ricerca orari apertura, ricevimento, sportello

### **Configurazione Avanzata**
- **Intent abilitati**: Attiva/disattiva singoli intent per tenant
- **Keywords extra**: Parole chiave aggiuntive per ogni intent (JSON)
- **KB scope mode**: 
  - `relaxed`: Fallback su tutto il tenant se KB vuota
  - `strict`: Solo KB selezionata
- **Soglia intent**: Punteggio minimo per attivazione (0-1)

Esempio configurazione:
```bash
php artisan db:seed --class=TenantIntentConfigSeeder
```

### **Scoping Knowledge Base** 
Gli intent ora rispettano la KB selezionata automaticamente dal RAG, permettendo ricerche specifiche per contesto (es: orari solo per una specifica sede).

## 🛡️ Sicurezza

- Le credenziali di autenticazione sono memorizzate cifrate
- Headers sensibili sono mascherati nei log
- Rate limiting previene abusi accidentali
- Whitelist domini previene scraping non autorizzato
- Multi-tenant isolation garantito per tutte le operazioni

---

## 🔗 **Documentazione Correlata**

- **[`doc-scraper.md`](./doc-scraper.md)** - **📖 DOCUMENTAZIONE PRINCIPALE**: Architettura completa web scraper
- **[`doc-documenti.md`](./doc-documenti.md)** - Documentazione completa gestione documenti
- **[`doc-rag-tester.md`](./doc-rag-tester.md)** - Testing e debug del sistema RAG
- **[`doc-widget.md`](./doc-widget.md)** - Configurazione e personalizzazione widget
- **[`doc-clienti.md`](./doc-clienti.md)** - Gestione tenant e configurazioni RAG

---

## 📝 Esempio Configurazione Completa

### **Scenario: Sito PA con News e Servizi**

**Obiettivo**: Scraper separati per diverse sezioni del sito con KB dedicate

#### **Scraper 1: "Servizi Istituzionali"**
```
Nome: Servizi PA
KB Target: Servizi e Informazioni
Frequenza: 1440 minuti (1 volta al giorno)
Abilitato: ✅

Seed URLs:
https://www.comune.example.it/servizi/
https://www.comune.example.it/uffici/

Include Patterns:
/servizi/.*
/uffici/.*
/contatti.*

Exclude Patterns:
/admin/.*
\.pdf$

Skip Known URLs: ✅
Recrawl Days: 30
```

#### **Scraper 2: "News e Comunicazioni"** 
```
Nome: News Comunale
KB Target: News e Comunicazioni  
Frequenza: 360 minuti (6 volte al giorno)
Abilitato: ✅

Seed URLs:
https://www.comune.example.it/news/

Include Patterns:
/news/\d{4}/.*
/comunicazioni/.*

Link-only Patterns:
/news/?$
/news/page/\d+
/comunicazioni/?$

Skip Known URLs: ✅
Recrawl Days: 7
```

#### **Risultato**
- **KB Servizi**: Contenuti stabili aggiornati mensilmente
- **KB News**: Contenuti dinamici aggiornati 4 volte al giorno  
- **RAG automatico**: Seleziona KB corretta in base alla domanda
- **Ottimizzazione**: No duplicati, no pagine indice, scheduling intelligente
