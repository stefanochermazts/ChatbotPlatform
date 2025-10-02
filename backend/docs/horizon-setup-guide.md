# 🚀 Guida Setup Horizon per Scraping Parallelo

## 📋 Panoramica

Il sistema ora supporta **scraping parallelo** tramite Laravel Horizon, permettendo di:
- **5 URL scrappati contemporaneamente** (invece di 1)
- **5 documenti in ingestion parallela** (invece di 1)
- **Velocità 5x** rispetto alla modalità sequenziale
- **Monitoraggio real-time** tramite dashboard Horizon

## 🎯 Configurazione Horizon

### Code Configurate

| Coda | Worker | Uso | Timeout |
|------|--------|-----|---------|
| `scraping` | 5 (prod) / 2 (local) | Scraping singoli URL | 5 min |
| `ingestion` | 5 (prod) / 3 (local) | Ingestion documenti | 30 min |
| `embeddings` | 3 (prod) / 2 (local) | Generazione embeddings | 15 min |
| `indexing` | 2 (prod) / 1 (local) | Indicizzazione vettori | 10 min |
| `default` | 2 (prod) / 1 (local) | Job generici | 5 min |

### File di Configurazione

**File:** `backend/config/horizon.php`

La configurazione è già completa e pronta per produzione!

## 🚀 Avvio in Produzione

### Opzione 1: Supervisor (Consigliato)

#### 1. Installa Supervisor

```bash
sudo apt-get update
sudo apt-get install supervisor
```

#### 2. Crea Configurazione Supervisor

```bash
sudo nano /etc/supervisor/conf.d/chatbot-horizon.conf
```

#### 3. Contenuto File

```ini
[program:chatbot-horizon]
process_name=%(program_name)s
command=php /var/www/chatbot/backend/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/chatbot/backend/storage/logs/horizon.log
stopwaitsecs=3600
```

**⚠️ Modifica i percorsi** secondo la tua installazione!

#### 4. Avvia Supervisor

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chatbot-horizon
```

#### 5. Verifica Stato

```bash
sudo supervisorctl status chatbot-horizon
```

### Opzione 2: Systemd

```bash
sudo nano /etc/systemd/system/chatbot-horizon.service
```

```ini
[Unit]
Description=ChatBot Horizon
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/chatbot/backend
ExecStart=/usr/bin/php /var/www/chatbot/backend/artisan horizon
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable chatbot-horizon
sudo systemctl start chatbot-horizon
sudo systemctl status chatbot-horizon
```

## 💻 Avvio in Sviluppo (Locale)

### Windows (Laragon)

```powershell
# Avvia Horizon manualmente
cd C:\laragon\www\ChatbotPlatform\backend
php artisan horizon
```

### Linux/Mac

```bash
cd /path/to/ChatbotPlatform/backend
php artisan horizon
```

## 📊 Dashboard Horizon

Accedi alla dashboard per monitorare code e job:

```
http://your-domain.com/horizon
```

**⚠️ In produzione proteggi con middleware auth!**

In `backend/config/horizon.php`:

```php
'middleware' => ['web', 'auth', 'admin'],  // Aggiungi auth
```

## 🔧 Comandi Utili

### Verifica Code

```bash
php artisan queue:monitor scraping,ingestion,embeddings,indexing
```

### Pulisci Code

```bash
php artisan horizon:clear
```

### Pausa/Riprendi

```bash
php artisan horizon:pause
php artisan horizon:continue
```

### Termina Worker

```bash
php artisan horizon:terminate
```

### Statistiche

```bash
php artisan horizon:list
```

## 🎛️ Modalità Scraping

### Modalità Parallela (Default in Produzione)

Automaticamente attiva in ambiente `production`. 

Per forzarla anche in locale, aggiungi a `.env`:

```env
SCRAPER_PARALLEL_MODE=true
```

### Modalità Sequenziale

Per tornare alla modalità sequenziale:

```env
SCRAPER_PARALLEL_MODE=false
```

Oppure passa `useParallel: false` al job:

```php
RunWebScrapingJob::dispatch($tenantId, $configId, useParallel: false);
```

## 📈 Performance Attese

### Scraping 100 URL

| Modalità | Tempo | Worker |
|----------|-------|--------|
| Sequenziale | ~140 minuti | 1 |
| Parallela (2 worker) | ~70 minuti | 2 |
| Parallela (5 worker) | ~28 minuti | 5 |

*Calcolo basato su 1.4 min/URL (render JS + estrazione)*

### Ingestion 100 Documenti

| Modalità | Tempo | Worker |
|----------|-------|--------|
| Sequenziale | ~50 minuti | 1 |
| Parallela (3 worker) | ~17 minuti | 3 |
| Parallela (5 worker) | ~10 minuti | 5 |

*Calcolo basato su 30 sec/documento (chunking + embeddings + Milvus)*

## 🔍 Monitoring e Debug

### Log Scraping

```bash
tail -f backend/storage/logs/scraper-$(date +%Y-%m-%d).log
```

### Log Horizon

```bash
tail -f backend/storage/logs/horizon.log
```

### Log Laravel

```bash
tail -f backend/storage/logs/laravel.log | grep "PARALLEL-"
```

### Metriche Chiave

Dashboard Horizon mostra:
- ✅ **Jobs/sec** - Throughput worker
- ✅ **Queue Wait Time** - Tempo di attesa in coda
- ✅ **Job Duration** - Durata media job
- ✅ **Failed Jobs** - Job falliti

## ⚠️ Troubleshooting

### Horizon Non Si Avvia

```bash
# Verifica Redis
redis-cli ping  # Deve rispondere "PONG"

# Verifica configurazione
php artisan config:cache

# Restart Supervisor
sudo supervisorctl restart chatbot-horizon
```

### Job Bloccati in Coda

```bash
# Verifica worker attivi
php artisan horizon:list

# Pulisci code
php artisan horizon:clear
php artisan queue:clear redis

# Restart
php artisan horizon:terminate
sudo supervisorctl restart chatbot-horizon
```

### Timeout Troppo Frequenti

Aumenta timeout in `horizon.php`:

```php
'supervisor-scraping' => [
    'timeout' => 600,  // 10 minuti invece di 5
    // ...
],
```

### Worker Non Processano

```bash
# Verifica permessi
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/

# Verifica Redis
redis-cli monitor

# Restart tutto
sudo supervisorctl restart all
```

## 🎓 Best Practices

### 1. Monitoring Attivo

- ✅ Controlla dashboard Horizon giornalmente
- ✅ Configura alerting su failed jobs
- ✅ Monitora uso memoria worker

### 2. Scaling Worker

In `horizon.php`, aumenta `maxProcesses` se:
- Code sempre piene
- Wait time alto (>60s)
- Server ha risorse disponibili

### 3. Retry Strategy

Job falliti vengono ritentati automaticamente:
- **ScrapeUrlJob**: 2 tentativi, backoff esponenziale
- **IngestUploadedDocumentJob**: 3 tentativi

### 4. Manutenzione

```bash
# Pulisci job vecchi (settimanale)
php artisan horizon:clear

# Restart worker (dopo deploy)
php artisan horizon:terminate
```

## 📚 Risorse

- **Laravel Horizon Docs**: https://laravel.com/docs/horizon
- **Supervisor Docs**: http://supervisord.org/
- **Redis Queue Docs**: https://laravel.com/docs/queues

---

**✅ Setup Completato!** Ora il tuo sistema è pronto per scraping parallelo ad alte performance! 🚀

