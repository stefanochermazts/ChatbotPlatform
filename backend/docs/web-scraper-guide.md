# 🕷️ Web Scraper - Guida Configurazione e Utilizzo

## 📋 Panoramica

Il Web Scraper di ChatbotPlatform permette di estrarre automaticamente contenuti da siti web e aggiungerli alla knowledge base di un tenant sotto forma di documenti Markdown.

## ⚙️ Configurazione

### 1. Accesso alla Configurazione

1. Vai su **Admin Dashboard** → **Gestisci Clienti**
2. Clicca su **"Scraper"** per il tenant desiderato
3. Compila i parametri di configurazione

### 2. Parametri di Configurazione

#### **🌐 Seed URLs** (Obbligatorio)
Liste degli URL di partenza (uno per riga):
```
https://www.example.com/
https://www.example.com/servizi/
https://www.example.com/contatti/
```

#### **🌐 Allowed Domains** (Raccomandato)
Domini permessi per limitare il crawling:
```
example.com
www.example.com
subdomain.example.com
```

#### **🗺️ Sitemap URLs** (Opzionale)
URL delle sitemap XML:
```
https://www.example.com/sitemap.xml
https://www.example.com/sitemap_pages.xml
```

#### **📝 Include Patterns** (Opzionale)
Pattern regex per includere solo URL specifici:
```
/categoria/.*
/prodotti/.*
/news/\d{4}/.*
```

#### **🚫 Exclude Patterns** (Opzionale)
Pattern regex per escludere URL:
#### **🔗 Link-only Patterns** (Opzionale)
Pattern regex delle pagine "indice" per cui vuoi solo seguire i link interni senza salvare la pagina stessa come documento. Utile per liste news, archivi, pagine di paginazione.

Esempi:
```
/news/?$
/news/page/\d+
```

Comportamento: se un URL matcha uno di questi pattern, lo scraper estrarrà i link e continuerà la ricorsione, ma non genererà un documento per quell'URL.
```
/admin/.*
/private/.*
\.pdf$
\.jpg$
```

#### **🔧 Parametri Avanzati**

- **Max Depth**: Profondità massima di crawling (default: 2)
- **Rate Limit (RPS)**: Richieste per secondo (default: 1)
- **Render JS**: Esegui JavaScript per SPA (default: false)
- **Respect Robots**: Rispetta robots.txt (default: true)

#### **🔐 Auth Headers** (Opzionale)
Headers di autenticazione:
```
Authorization: Bearer your-token-here
X-API-Key: your-api-key
Cookie: session=abc123
```

## 🚀 Esecuzione

### **Background Mode** (Raccomandato)
- Clicca **"🚀 Avvia Scraping (Background)"**
- Lo scraping viene eseguito in coda
- Controlla i log per il progresso: `storage/logs/laravel.log`

### **Sync Mode** (Test/Debug)
- Clicca **"⚡ Esegui Ora (Sincrono)"**
- Attendi il completamento (può richiedere tempo)
- Ricevi feedback immediato

## 📁 Output

### **Documenti Generati**
I contenuti vengono salvati come:

**Path**: `storage/app/public/scraped/{tenant_id}/page-title-v{version}.md`

**Format**:
```markdown
# Page Title

**URL:** https://www.example.com/page
**Scraped on:** 2024-01-15 14:30:00

---

[Contenuto estratto dalla pagina]
```

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

## 🛠️ Troubleshooting

### **Nessun Documento Creato**
1. Verifica **Seed URLs** siano accessibili
2. Controlla **Allowed Domains** includa il dominio target
3. Verifica **Include/Exclude Patterns** non blocchino tutto
4. Controlla log per errori: `tail -f storage/logs/laravel.log`

### **Rate Limiting**
Se ricevi errori 429 (Too Many Requests):
1. Riduci **Rate Limit (RPS)** a 0.5 o meno
2. Aggiungi **Auth Headers** se necessario

### **Contenuto JavaScript**
Per SPA o contenuto dinamico:
1. Abilita **Render JS**
2. Aumenta timeout (attualmente 30s)

### **Accesso Negato**
Per contenuti protetti:
1. Aggiungi **Auth Headers** appropriati
2. Usa cookies di sessione se necessario

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
Exclude Patterns: /tag/.*, /author/.*
Max Depth: 2
Rate Limit: 2 RPS
```

### **Per E-commerce**
```
Include Patterns: /prodotti/.*, /categoria/.*
Exclude Patterns: /carrello/.*, /checkout/.*, /account/.*
Max Depth: 3
Rate Limit: 1 RPS
```

## ⚡ Commands CLI

### **Eseguire Scraping da CLI**
```bash
# Background
php artisan queue:work --queue=scraping

# Diretto (per debug)
php artisan tinker
>>> $scraper = app(\App\Services\Scraper\WebScraperService::class);
>>> $result = $scraper->scrapeForTenant(1);
>>> dd($result);
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

### **1. Dopo Configurazione**
1. Salva configurazione
2. Clicca **"⚡ Esegui Ora (Sincrono)"** per test
3. Verifica messaggio di successo

### **2. Controllare Documenti**
1. Vai su **Admin** → **Gestisci Clienti** → **Documenti**
2. Cerca documenti con **"(Scraped)"** nel titolo
3. Verifica **Ingestion Status** = "completed"

### **3. Testare nel RAG**
1. Vai su **RAG Tester**
2. Seleziona il tenant
3. Fai domande sui contenuti scrapati
4. Verifica che le citazioni includano i nuovi documenti

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

Per aggiornamenti periodici, configura un cron job:

```bash
# Nel crontab
0 2 * * * php /path/to/artisan queue:work --queue=scraping --once
```

O usa Laravel Task Scheduling nel file `app/Console/Kernel.php`:

```php
$schedule->job(new RunWebScrapingJob($tenantId))->daily();
```

## 🚨 Considerazioni Legali

1. **Rispetta sempre robots.txt** (abilitato di default)
2. **Controlla Terms of Service** del sito target
3. **Non sovraccaricare** i server (rate limiting)
4. **Rispetta copyright** e licenze di contenuto
5. **Solo contenuti pubblici** accessibili senza login

## 🛡️ Sicurezza

- Le credenziali di autenticazione sono memorizzate cifrate
- Headers sensibili sono mascherati nei log
- Rate limiting previene abusi accidentali
- Whitelist domini previene scraping non autorizzato
