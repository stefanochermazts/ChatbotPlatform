# 🪟 Queue Workers su Windows (Dev)

## ⚠️ Importante: Horizon Non Supporta Windows

Laravel Horizon richiede estensioni Unix (`ext-pcntl`, `ext-posix`) **non disponibili su Windows**.

**Soluzione:** Usa il **queue worker standard** di Laravel che funziona perfettamente su Windows!

## 🚀 Avvio Queue Workers in Dev (Windows)

### Metodo 1: Worker Singolo Multi-Code (Consigliato)

Avvia un worker che processa tutte le code:

```powershell
cd backend
php artisan queue:work --queue=scraping,ingestion,embeddings,indexing,default --tries=3 --timeout=300
```

**Parametri:**
- `--queue`: Lista code da processare (ordine priorità)
- `--tries=3`: Retry automatici
- `--timeout=300`: Timeout 5 minuti per job

### Metodo 2: Worker Multipli (Parallelismo Reale)

Apri **3 finestre PowerShell** separate:

**Finestra 1 - Scraping:**
```powershell
cd C:\laragon\www\ChatbotPlatform\backend
php artisan queue:work --queue=scraping --tries=2 --timeout=300
```

**Finestra 2 - Ingestion:**
```powershell
cd C:\laragon\www\ChatbotPlatform\backend
php artisan queue:work --queue=ingestion,embeddings --tries=3 --timeout=1800
```

**Finestra 3 - Default:**
```powershell
cd C:\laragon\www\ChatbotPlatform\backend
php artisan queue:work --queue=indexing,default --tries=3 --timeout=600
```

### Metodo 3: Script Batch Automatico

Usa lo script già presente:

```powershell
.\backend\start-multiple-workers.bat
```

Oppure PowerShell:

```powershell
.\backend\start-queue-workers.ps1
```

## 📊 Monitoraggio Code

### Verifica Job in Coda

```powershell
php artisan queue:monitor
```

### Verifica Job Falliti

```powershell
php artisan queue:failed
```

### Pulisci Job Falliti

```powershell
php artisan queue:flush
```

### Retry Job Falliti

```powershell
php artisan queue:retry all
```

## 🧪 Test Scraping Parallelo

### Test 1: Job Singolo

```powershell
php artisan tinker
```

```php
// In tinker
dispatch(new \App\Jobs\ScrapeUrlJob(
    'https://www.comune.palmanova.ud.it/it/amministrazione-179014',
    0,
    9, // config_id
    9, // tenant_id
    'test_' . uniqid()
));

// Verifica nei log
exit
```

```powershell
# Guarda i log
tail -n 50 storage/logs/laravel.log | Select-String "SCRAPE-JOB"
```

### Test 2: Scraping Completo Parallelo

1. **Assicurati che il worker sia attivo**
2. **Vai nell'interfaccia admin** → Scraper
3. **Lancia scraping** dal pulsante "Scrape Now"
4. **Guarda i log** per vedere job dispatchati

```powershell
# Monitor log real-time
Get-Content storage/logs/laravel.log -Wait -Tail 20 | Select-String "PARALLEL-"
```

## 🎯 Verifica Modalità Attiva

Nei log vedrai:

**Modalità Parallela (Worker Attivo):**
```
🚀 [PARALLEL-MODE] Avvio scraping parallelo
📤 [PARALLEL-SCRAPE] Job dispatchato per URL
🚀 [SCRAPE-JOB-START] Inizio scraping URL
```

**Modalità Sequenziale (Nessun Worker):**
```
📝 [SEQUENTIAL-MODE] Avvio scraping sequenziale
```

## 🔧 Configurazione .env

Per forzare modalità parallela anche senza Horizon:

```env
# .env
SCRAPER_PARALLEL_MODE=true
```

## ⚡ Performance su Windows

Con **3 worker** attivi in parallelo:

| Operazione | Tempo Sequenziale | Tempo Parallelo |
|------------|-------------------|-----------------|
| 100 URL scraping | ~140 min | ~50 min |
| 100 doc ingestion | ~50 min | ~17 min |

## 🐛 Troubleshooting

### Worker Non Processa Job

```powershell
# Verifica Redis
redis-cli ping  # Deve rispondere "PONG"

# Riavvia worker
# Ctrl+C per fermare, poi rilancia
php artisan queue:work ...
```

### Job Bloccati

```powershell
# Pulisci tutte le code
php artisan queue:clear redis

# Restart worker
```

### Code Sempre Piene

Aumenta numero di worker (apri più finestre PowerShell)

## 📚 Alternative per Produzione

**In produzione Linux**, usa:
- ✅ **Laravel Horizon** (consigliato)
- ✅ **Supervisor** per gestire worker
- ✅ **Systemd** come alternativa

Vedi: `backend/docs/horizon-setup-guide.md`

## 🎓 Best Practices Windows Dev

1. ✅ Usa almeno 2 worker (scraping + ingestion)
2. ✅ Tieni finestre PowerShell aperte durante sviluppo
3. ✅ Restart worker dopo modifiche al codice
4. ✅ Monitora log per verificare funzionamento
5. ✅ Usa Laragon Task Manager per gestire processi

---

**✅ Queue Workers Attivi!** Ora il sistema usa scraping parallelo anche su Windows! 🚀

