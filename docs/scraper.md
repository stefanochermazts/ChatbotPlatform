# 🕷️ Web Scraper - Documentazione Completa

## 📋 Panoramica

Il Web Scraper di ChatbotPlatform permette di estrarre automaticamente contenuti da siti web e aggiungerli alla knowledge base di un tenant sotto forma di documenti Markdown con **sistema di deduplicazione intelligente**.

### 🎯 Caratteristiche Principali

- ✅ **Estrazione automatica** di contenuti HTML
- ✅ **Deduplicazione intelligente** con hash SHA256
- ✅ **Versioning automatico** per contenuti modificati
- ✅ **Rate limiting** per rispettare i server
- ✅ **Pattern filtering** con regex avanzati
- ✅ **Supporto sitemap XML** per efficienza
- ✅ **Autenticazione** con headers personalizzati
- ✅ **Esecuzione sincrona/asincrona**

---

## 🚀 Quick Start

### 1. **Accesso Configurazione**
```
Admin Dashboard → Gestisci Clienti → [Tenant] → "Scraper"
```

### 2. **Configurazione Minima**
```
🚀 Seed URLs: https://www.esempio.it/
🌐 Allowed Domains: esempio.it
💾 Salva Configurazione
⚡ Esegui Ora (Sincrono)
```

### 3. **Verifica Risultati**
```
Admin → Documenti → Cerca "(Scraped)"
RAG Tester → Fai domande sui contenuti
```

---

## ⚙️ Configurazione Dettagliata

### 🚀 **Seed URLs** (Obbligatorio)

**Cosa inserire:** Gli URL da cui iniziare il crawling (uno per riga)

**Esempi:**
```
https://www.comune.esempio.it/
https://www.comune.esempio.it/servizi/
https://www.comune.esempio.it/uffici/
https://www.azienda.it/prodotti/
https://blog.esempio.it/articoli/
```

**Best Practices:**
- Inizia con le pagine principali
- Includi sezioni importanti direttamente
- Evita URL con parametri dinamici

### 🌐 **Allowed Domains** (Raccomandato)

**Cosa inserire:** Domini da cui è permesso scaricare contenuti

**Esempi:**
```
comune.esempio.it
www.comune.esempio.it
portale.comune.esempio.it
subdomain.azienda.it
```

**⚠️ Attenzione:** Se vuoto, accetta tutti i domini (pericoloso!)

### 🗺️ **Sitemap URLs** (Opzionale)

**Cosa inserire:** URL delle sitemap XML per crawling efficiente

**Esempi:**
```
https://www.comune.esempio.it/sitemap.xml
https://www.comune.esempio.it/sitemap_pages.xml
https://blog.esempio.it/post-sitemap.xml
```

**💡 Vantaggi:** Le sitemap accelerano notevolmente il processo

### ✅ **Include Patterns** (Opzionale)

**Cosa inserire:** Pattern regex per includere solo URL specifici

**Esempi Comuni:**
```
/servizi/.*           # Tutte le pagine in /servizi/
/uffici/.*            # Tutte le pagine in /uffici/
/news/\d{4}/.*        # News con anno (es. /news/2024/)
/prodotti/categoria/.*  # Categoria prodotti specifica
```

**Esempi PA:**
```
/amministrazione/.*
/cittadino/.*
/imprese/.*
/trasparenza/.*
```

### 🚫 **Exclude Patterns** (Raccomandato)

**Cosa inserire:** Pattern regex per escludere URL indesiderati

**Esempi Comuni:**
```
/admin/.*             # Area amministrativa
/login.*              # Pagine di login
/search.*             # Pagine di ricerca
/download/.*          # File di download
\.pdf$                # File PDF
\.doc$                # File Word
\.xls$                # File Excel
```

**Esempi PA:**
```
/bandi/.*             # Bandi di gara
/albo.*               # Albo pretorio
/determine/.*         # Determine/delibere
/concorsi/.*          # Concorsi pubblici
/calendario.*         # Eventi calendario
```

**Esempi E-commerce:**
```
/carrello.*           # Carrello acquisti
/checkout.*           # Checkout
/account.*            # Area utente
/wishlist.*           # Lista desideri
```

### 🔢 **Max Depth**

**Cosa significa:** Quanti livelli di link seguire dal seed URL

**Valori consigliati:**
- `1` = Solo pagine linkate direttamente
- `2` = Link + link dai link (raccomandato)
- `3` = Più profondo (può essere lento)
- `4+` = Solo per siti molto strutturati

**⚠️ Attenzione:** Valori alti = molte pagine

### ⚡ **Rate Limit (RPS)**

**Cosa significa:** Richieste per secondo al server target

**Valori consigliati:**
- `0.5` = Molto lento, siti piccoli/sensibili
- `1` = Standard, sicuro per la maggior parte
- `1.5` = Veloce, siti robusti
- `2-3` = Molto veloce, solo siti enterprise

**⚠️ Attenzione:** Troppo alto = rischio ban IP

### 🔧 **Opzioni Avanzate**

#### 🚀 **Render JavaScript**
- **Quando usare:** Siti SPA (React, Vue, Angular)
- **Cosa fa:** Esegue JavaScript per caricare contenuto dinamico
- **⚠️ Attenzione:** Molto più lento

#### 🤖 **Rispetta robots.txt**
- **Raccomandato:** Sempre attivo per etica
- **Cosa fa:** Segue le regole del file robots.txt
- **⚠️ Disattiva solo:** Se necessario e autorizzato

### 🔐 **Auth Headers** (Opzionale)

**Quando usare:** Per siti che richiedono autenticazione/autorizzazione

**Esempi:**
```
Authorization: Bearer your-token-here
X-API-Key: your-api-key-123
Cookie: sessionid=abc123; csrftoken=xyz789
User-Agent: YourBot/1.0 (+https://example.com/bot)
X-Forwarded-For: 192.168.1.100
```

**⚠️ Sicurezza:** Non condividere mai credenziali sensibili

---

## 📁 Sistema di Deduplicazione

### 🔍 **Come Funziona**

Il sistema usa **hash SHA256** del contenuto estratto per rilevare modifiche:

```
URL: https://comune.it/orari
Contenuto: "Orari: Lunedì 9-12"
Hash: abc123...
→ Documento: orari-v1.md
```

### 📊 **Comportamenti per URL**

#### 🆕 **Prima volta**
```
URL mai visto → Crea documento v1 + salva hash
```

#### 🔄 **Contenuto modificato**
```
Hash diverso → Aggiorna a v2 + re-ingestion automatica
```

#### ⏭️ **Contenuto identico**
```
Hash uguale → Skip documento + aggiorna timestamp
```

### 📈 **Statistiche Tracking**

Il sistema restituisce statistiche dettagliate:
```
"15 URLs visitati, 8 documenti processati"
"(Nuovi: 3, Aggiornati: 2, Invariati: 3)"
```

### 📄 **Formato Output**

**Path:** `storage/app/public/scraped/{tenant_id}/page-title-v{version}.md`

**Contenuto:**
```markdown
# Titolo Pagina

**URL:** https://www.esempio.it/pagina
**Scraped on:** 2024-01-15 14:30:00

---

[Contenuto estratto dalla pagina]
```

---

## 🚀 Modalità di Esecuzione

### 🚀 **Background Mode** (Raccomandato)

**Quando usare:**
- Siti grandi (>50 pagine)
- Scraping periodici/automatici
- Quando non vuoi attendere

⚠️ **PREREQUISITO CRITICO**: Devi avviare il worker per la coda `scraping`!

**Windows (Laragon):**
```bash
# Usa lo script automatico
backend\start-scraping-worker.bat

# Oppure manualmente
php artisan queue:work --queue=scraping --tries=3 --timeout=1800
```

**Linux/Mac:**
```bash
# Usa lo script automatico  
./backend/start-scraping-worker.sh

# Oppure manualmente
nohup php artisan queue:work --queue=scraping --tries=3 --timeout=1800 &
```

**Come monitorare:**
```bash
# Log in tempo reale
tail -f storage/logs/laravel.log | grep -i scraping

# Test worker (deve rispondere immediatamente)
php artisan queue:work --queue=scraping --once
```

### ⚡ **Sync Mode** (Test/Debug)

**Quando usare:**
- Test configurazione
- Siti piccoli (<20 pagine)
- Debug problemi

**⚠️ Limitazioni:**
- Blocca browser fino al completamento
- Timeout dopo ~5 minuti

---

## 📋 Esempi di Configurazione

### 🏛️ **Sito Comunale**

```
🚀 Seed URLs:
https://www.comune.esempio.it/
https://www.comune.esempio.it/servizi/

🌐 Allowed Domains:
comune.esempio.it

✅ Include Patterns:
/servizi/.*
/uffici/.*
/orari/.*
/contatti/.*

🚫 Exclude Patterns:
/admin/.*
/bandi/.*
/albo/.*
\.pdf$

⚙️ Parametri:
Max Depth: 2
Rate Limit: 1 RPS
Respect Robots: ✓
```

### 📰 **Sito News/Blog**

```
🚀 Seed URLs:
https://blog.esempio.it/

🗺️ Sitemap:
https://blog.esempio.it/sitemap.xml

✅ Include Patterns:
/articoli/.*
/\d{4}/.*
/categoria/.*

🚫 Exclude Patterns:
/tag/.*
/author/.*
/search.*

⚙️ Parametri:
Max Depth: 1
Rate Limit: 2 RPS
```

### 🏢 **Sito Aziendale**

```
🚀 Seed URLs:
https://azienda.it/servizi/
https://azienda.it/prodotti/

✅ Include Patterns:
/servizi/.*
/prodotti/.*
/soluzioni/.*

🚫 Exclude Patterns:
/carrello.*
/account.*
/checkout.*
/admin/.*

⚙️ Parametri:
Max Depth: 3
Rate Limit: 1.5 RPS
```

---

## ⚡ Comandi CLI

### **Eseguire Scraping da CLI**

```bash
# Worker background
php artisan queue:work --queue=scraping

# Esecuzione diretta (debug)
php artisan tinker
>>> $scraper = app(\App\Services\Scraper\WebScraperService::class);
>>> $result = $scraper->scrapeForTenant(1);
>>> dd($result);
```

### **Gestione Documenti Obsoleti**

```bash
# Dry-run (simulazione)
php artisan scraper:clean-old --dry-run

# Pulizia documenti >30 giorni
php artisan scraper:clean-old --days=30

# Solo tenant specifico
php artisan scraper:clean-old --tenant=1 --days=7

# Retention personalizzato
php artisan scraper:clean-old --days=60
```

### **Monitoraggio**

```bash
# Log scraping
tail -f storage/logs/laravel.log | grep -E "(scraping|Documento)"

# Stato queue
php artisan queue:monitor scraping

# Statistiche database
php artisan tinker
>>> $stats = \App\Models\Document::where('source', 'web_scraper')
    ->selectRaw('COUNT(*) as total, AVG(scrape_version) as avg_version')
    ->first();
>>> dd($stats);
```

---

## 🔍 Verificare il Funzionamento

### **1. Dopo Configurazione**
1. Salva configurazione
2. Clicca **"⚡ Esegui Ora (Sincrono)"** per test
3. Verifica messaggio di successo

### **2. Controllare Documenti**
1. Vai su **Admin** → **Gestisci Clienti** → **Documenti**
2. Cerca documenti con **"(Scraped)"** nel titolo
3. Verifica **Ingestion Status** = "completed"
4. Controlla link URL sorgente e versioning

### **3. Testare nel RAG**
1. Vai su **RAG Tester**
2. Seleziona il tenant
3. Fai domande sui contenuti scrapati
4. Verifica che le citazioni includano i nuovi documenti

---

## 🛠️ Troubleshooting

### **❌ Nessun Documento Creato**

**Possibili cause:**
1. **Seed URLs** non accessibili
2. **Allowed Domains** troppo restrittivi
3. **Include Patterns** che escludono tutto
4. **Exclude Patterns** troppo aggressivi

**Soluzioni:**
```bash
# Controlla log
tail -f storage/logs/laravel.log | grep -i scraping

# Test URL manuale
curl -I https://www.esempio.it/

# Verifica robots.txt
curl https://www.esempio.it/robots.txt
```

### **⚠️ Rate Limiting (429 Errors)**

**Sintomi:**
- Errori "Too Many Requests"
- Blocco IP temporaneo

**Soluzioni:**
1. Riduci **Rate Limit** a 0.5 RPS
2. Aggiungi pausa tra richieste
3. Usa **Auth Headers** se disponibili
4. Contatta amministratore sito

### **🐌 Scraping Molto Lento**

**Possibili cause:**
1. **Rate Limit** troppo basso
2. **Render JS** attivato inutilmente
3. **Max Depth** troppo alto
4. Server target lento

**Soluzioni:**
```
# Ottimizzazioni
Rate Limit: Aumenta gradualmente
Render JS: Disattiva se non necessario
Max Depth: Riduci a 1-2
Sitemap: Usa se disponibile
```

### **🚫 Contenuto Dinamico Non Estratto**

**Sintomi:**
- Pagine con contenuto vuoto/incompleto
- SPA che non caricano

**Soluzioni:**
1. Attiva **Render JavaScript**
2. Aumenta timeout requests
3. Aggiungi headers specifici
4. Usa sitemap se disponibile

### **🔐 Accesso Negato (403/401)**

**Sintomi:**
- Errori di autenticazione
- Pagine protette

**Soluzioni:**
```
# Auth Headers esempi
Authorization: Bearer token-here
Cookie: session=abc123
User-Agent: Mozilla/5.0 (compatible; Bot)
X-Forwarded-For: IP-ADDRESS
```

---

## 📊 Monitoraggio e Metriche

### **Metriche Chiave**
- **URLs visited**: Pagine effettivamente processate
- **Documents saved**: Documenti creati con successo
- **Deduplication stats**: Nuovi/Aggiornati/Invariati
- **Error rate**: Percentuale di errori
- **Processing time**: Tempo di elaborazione

### **Dashboard Informazioni**

Nella lista documenti troverai:
```
📄 Titolo Documento (Scraped)
🌐 https://source-url.com → Link sorgente
🔄 v2 • Aggiornato 2 giorni fa → Versioning
🕷️ Scraped 1 settimana fa → Prima volta
```

### **Health Check**

```bash
# Verifica sistema
php artisan tinker
>>> $health = [
    'milvus' => app(\App\Services\RAG\MilvusClient::class)->health(),
    'queue' => \Illuminate\Support\Facades\Queue::size('scraping'),
    'last_scraped' => \App\Models\Document::where('source', 'web_scraper')
        ->latest('last_scraped_at')->value('last_scraped_at')
];
>>> dd($health);
```

---

## 🔄 Aggiornamenti Automatici

### **Scheduling Laravel**

Nel file `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Scraping giornaliero
    $schedule->job(new RunWebScrapingJob(1))->daily();
    
    // Pulizia settimanale
    $schedule->command('scraper:clean-old --days=30')->weekly();
}
```

### **Cron Job Sistema**
```crontab
# Scraping ogni 6 ore
0 */6 * * * cd /path/to/project && php artisan queue:work --queue=scraping --once

# Pulizia mensile
0 0 1 * * cd /path/to/project && php artisan scraper:clean-old --days=90
```

---

## 🛡️ Sicurezza e Compliance

### **Best Practices**

1. **Rispetta sempre robots.txt** (abilitato di default)
2. **Controlla Terms of Service** del sito target
3. **Non sovraccaricare** i server (rate limiting)
4. **Rispetta copyright** e licenze di contenuto
5. **Solo contenuti pubblici** accessibili senza login

### **Protezione Dati**

- Le credenziali sono memorizzate cifrate
- Headers sensibili sono mascherati nei log
- Rate limiting previene abusi accidentali
- Whitelist domini previene scraping non autorizzato

### **Conformità GDPR**

- Non raccogliere dati personali sensibili
- Implementare retention policies
- Documentare fonte e scopo dei dati
- Rispettare diritti di cancellazione

---

## 🎯 FAQ

### **Q: Quanto spesso devo eseguire lo scraping?**
A: Dipende dalla frequenza di aggiornamento del sito. Siti statici: settimanale. News: giornaliero. PA: ogni 2-3 giorni.

### **Q: Il sistema rileva automaticamente le modifiche?**
A: Sì, usa hash SHA256 del contenuto. Solo pagine modificate vengono re-processate.

### **Q: Posso scrapare siti con login?**
A: Sì, usando Auth Headers con cookie/token di sessione. Ma rispetta sempre i ToS.

### **Q: Quanto spazio occupa?**
A: Dipende dal contenuto. ~1-5KB per pagina di testo. I file sono in formato Markdown compresso.

### **Q: Posso limitare a sezioni specifiche?**
A: Sì, usa Include Patterns con regex specifici (es. `/servizi/.*`).

### **Q: Come gestisco siti molto grandi?**
A: Usa Background Mode, aumenta Rate Limit gradualmente, usa Sitemap, filtra con Pattern.

---

## 🆘 Supporto

### **Log Files**
```bash
# Errori generali
tail -f storage/logs/laravel.log

# Solo scraping
tail -f storage/logs/laravel.log | grep -i scraping

# Queue jobs
tail -f storage/logs/laravel.log | grep -i queue
```

### **Debug Avanzato**
```bash
# Test configurazione
php artisan tinker
>>> $config = \App\Models\ScraperConfig::where('tenant_id', 1)->first();
>>> dd($config->toArray());

# Test singolo URL
>>> $scraper = app(\App\Services\Scraper\WebScraperService::class);
>>> $result = $scraper->scrapeForTenant(1);
>>> dd($result);
```

### **Performance Tuning**
```bash
# Ottimizza queue worker
php artisan queue:work --queue=scraping --memory=512 --timeout=300

# Monitor memoria
php artisan queue:work --queue=scraping --memory=256
```

---

**🚀 Il Web Scraper è ora completamente configurato e pronto per l'uso!**

Per supporto tecnico o domande specifiche, consulta i log di sistema o contatta l'amministratore della piattaforma.













