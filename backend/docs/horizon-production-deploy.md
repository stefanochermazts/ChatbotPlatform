# 🚀 Deploy Horizon in Produzione (crowdmai.maia.chat)

## ⚠️ Problema Attuale

**URL:** https://crowdmai.maia.chat/horizon  
**Errore:** 403 Forbidden

## ✅ Soluzione Completa

### **Step 1: Connetti al Server**

```bash
ssh utente@server-produzione
cd /var/www/chatbot/backend  # o percorso corretto del progetto
```

### **Step 2: Installa Horizon**

```bash
# Installa Horizon (ignora requisiti ext-pcntl/posix se su Linux)
composer require laravel/horizon

# Pubblica configurazione e assets
php artisan horizon:install
php artisan vendor:publish --tag=horizon-assets

# Ottimizza per produzione
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### **Step 3: Verifica File Modificati**

Assicurati che questi file siano presenti nel repository e deployati:

#### **1. HorizonServiceProvider**
File: `backend/app/Providers/HorizonServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            // ⚠️ MODIFICA: Aggiungi l'email del tuo admin
            return in_array($user->email, [
                'admin@chatbot.local',
                'admin@maia.chat',
                'tuo-email@example.com',  // <-- Aggiungi qui
            ]);
        });
    }
}
```

#### **2. Registrazione Provider**
File: `backend/bootstrap/providers.php`

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,  // <-- Questa riga
];
```

### **Step 4: Deploy Codice**

```bash
# Pull ultime modifiche da Git
git pull origin main

# Installa dipendenze
composer install --no-dev --optimize-autoloader

# Rigenera cache
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Migra database se necessario
php artisan migrate --force
```

### **Step 5: Configura Supervisor**

```bash
# Crea configurazione Supervisor per Horizon
sudo nano /etc/supervisor/conf.d/chatbot-horizon.conf
```

**Contenuto file:**

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

**Avvia Supervisor:**

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chatbot-horizon
```

### **Step 6: Verifica Accesso**

1. **Login** su https://crowdmai.maia.chat/login con un account admin
2. **Vai a** https://crowdmai.maia.chat/horizon
3. **Dovresti vedere** la dashboard Horizon! 🎉

### **Step 7: Test Funzionamento**

Nella dashboard Horizon dovresti vedere:

- ✅ **Workers attivi** (5 scraping, 5 ingestion, etc.)
- ✅ **Jobs pending/processing**
- ✅ **Metriche real-time**

## 🔧 Troubleshooting

### Errore: "Class HorizonServiceProvider not found"

```bash
# Rigenera autoload
composer dump-autoload
php artisan config:clear
```

### Ancora 403 dopo deploy

1. **Verifica di essere loggato** come admin
2. **Controlla email** nel `HorizonServiceProvider`:

```bash
php artisan tinker --execute="echo auth()->user()?->email;"
```

3. **Aggiungi l'email** nel gate di `HorizonServiceProvider`

### Worker Non Partono

```bash
# Verifica Supervisor
sudo supervisorctl status chatbot-horizon

# Restart Horizon
sudo supervisorctl restart chatbot-horizon

# Verifica log
tail -f storage/logs/horizon.log
```

### Redis Non Raggiungibile

```bash
# Testa Redis
redis-cli ping  # Deve rispondere "PONG"

# Verifica config in .env
cat .env | grep REDIS
```

## 📊 Monitoring

### Dashboard Horizon
```
https://crowdmai.maia.chat/horizon
```

### Log Files
```bash
# Horizon
tail -f storage/logs/horizon.log

# Laravel
tail -f storage/logs/laravel.log

# Scraping
tail -f storage/logs/scraper-$(date +%Y-%m-%d).log
```

### Comandi Utili

```bash
# Status Horizon
php artisan horizon:list

# Pulisci code
php artisan horizon:clear

# Pausa worker
php artisan horizon:pause

# Riprendi worker
php artisan horizon:continue

# Termina gracefully
php artisan horizon:terminate
```

## 🎯 Configurazione Email Admin

**⚠️ IMPORTANTE:** Modifica `HorizonServiceProvider.php` per aggiungere le email degli admin che possono accedere!

```php
return in_array($user->email, [
    'admin@chatbot.local',
    'admin@maia.chat',
    'stefano@example.com',  // <-- Aggiungi qui
    'altro-admin@example.com',
]);
```

Dopo la modifica:

```bash
git add app/Providers/HorizonServiceProvider.php
git commit -m "feat: update Horizon admin emails"
git push origin main

# Sul server
git pull
php artisan config:clear
```

## ✅ Checklist Deploy

- [ ] Horizon installato (`composer require laravel/horizon`)
- [ ] Assets pubblicati (`php artisan horizon:install`)
- [ ] `HorizonServiceProvider.php` creato e registrato
- [ ] Email admin configurate nel gate
- [ ] Codice deployato in produzione
- [ ] Cache pulita (`config:clear`, `route:clear`)
- [ ] Supervisor configurato e attivo
- [ ] Login come admin funzionante
- [ ] Dashboard `/horizon` accessibile
- [ ] Worker processano job

---

**Dopo questi step, Horizon sarà accessibile e funzionante in produzione!** 🚀

