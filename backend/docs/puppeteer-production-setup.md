# 🌐 Setup Produzione Puppeteer - Guida Completa

## 📋 Requisiti Sistema

### **Node.js & NPM**
```bash
# Verifica versioni minime
node --version  # >= 18.x
npm --version   # >= 9.x

# Ubuntu/Debian
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# CentOS/RHEL
curl -fsSL https://rpm.nodesource.com/setup_20.x | sudo bash -
sudo yum install -y nodejs
```

### **Dipendenze Sistema per Puppeteer**
```bash
# Ubuntu/Debian (aggiornato per Ubuntu 22.04/24.04)
sudo apt-get update
sudo apt-get install -y \
    ca-certificates \
    fonts-liberation \
    libappindicator3-1 \
    libasound2t64 \
    libatk-bridge2.0-0t64 \
    libatk1.0-0t64 \
    libc6 \
    libcairo-gobject2 \
    libcairo2 \
    libcups2t64 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgcc-s1 \
    libgdk-pixbuf2.0-0 \
    libglib2.0-0t64 \
    libgtk-3-0t64 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libstdc++6 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxrandr2 \
    libxrender1 \
    libxss1 \
    libxtst6 \
    lsb-release \
    wget \
    xdg-utils

# Alternativa semplificata (Ubuntu 20.04 e precedenti)
# Se i pacchetti sopra non funzionano, usa:
# sudo apt-get install -y \
#     fonts-liberation \
#     libasound2 \
#     libatk-bridge2.0-0 \
#     libdrm2 \
#     libgtk-3-0 \
#     libnss3 \
#     lsb-release \
#     xdg-utils \
#     wget

# CentOS/RHEL
sudo yum install -y \
    alsa-lib \
    atk \
    cairo-gobject \
    cups-libs \
    dbus-glib \
    fontconfig \
    GConf2 \
    gdk-pixbuf2 \
    gtk3 \
    libX11 \
    libXcomposite \
    libXcursor \
    libXdamage \
    libXext \
    libXfixes \
    libXi \
    libXrandr \
    libXrender \
    libXScrnSaver \
    libXtst \
    nss \
    pango \
    xorg-x11-fonts-100dpi \
    xorg-x11-fonts-75dpi \
    xorg-x11-fonts-Type1 \
    xorg-x11-utils
```

## 🚀 Installazione Puppeteer

### **Nel progetto Laravel (directory backend/)**
```bash
cd /path/to/your/project/backend
npm install puppeteer

# Verifica installazione
node -e "const puppeteer = require('puppeteer'); console.log('✅ Puppeteer OK');"
```

### **Test Funzionalità**
```bash
# Test semplice
node -e "
const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();
  await page.goto('https://www.google.com');
  console.log('✅ Puppeteer functional test PASSED');
  await browser.close();
})().catch(err => {
  console.error('❌ Puppeteer test FAILED:', err.message);
  process.exit(1);
});
"
```

## 🔒 Configurazione Sicurezza

### **User e Permessi**
```bash
# Crea user dedicato per web scraping (opzionale ma raccomandato)
sudo useradd -r -s /bin/false scraper
sudo usermod -a -G www-data scraper

# Permessi directory
sudo chown -R www-data:www-data /path/to/your/project/storage/app/temp
sudo chmod 755 /path/to/your/project/storage/app/temp
```

### **Sandboxing (Produzione Sicura)**
```bash
# Nel file .env di produzione
PUPPETEER_ARGS="--no-sandbox,--disable-setuid-sandbox,--disable-dev-shm-usage"
```

## 📊 Monitoraggio e Performance

### **Limiti Risorsa (systemd/limits)**
```bash
# /etc/systemd/system/your-app.service
[Service]
# Limits per Puppeteer
LimitNOFILE=65536
LimitNPROC=4096
MemoryMax=2G
```

### **Monitoring Script**
```bash
#!/bin/bash
# /usr/local/bin/check-puppeteer.sh

cd /path/to/your/project/backend

# Test Puppeteer
if node -e "
const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({headless: true, args: ['--no-sandbox']});
  await browser.close();
})();
" 2>/dev/null; then
  echo "✅ Puppeteer OK"
  exit 0
else
  echo "❌ Puppeteer FAILED"
  exit 1
fi
```

## 🐳 Docker Setup (se applicabile)

### **Dockerfile**
```dockerfile
# Nel tuo Dockerfile
FROM node:20-slim

# Installa dipendenze Chrome
RUN apt-get update && apt-get install -y \
    ca-certificates \
    fonts-liberation \
    libappindicator3-1 \
    libasound2 \
    libatk-bridge2.0-0 \
    libdrm2 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    lsb-release \
    xdg-utils \
    wget \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# Installa Puppeteer
WORKDIR /app/backend
COPY backend/package*.json ./
RUN npm ci --only=production

# User non-root per sicurezza
RUN groupadd -r pptruser && useradd -r -g pptruser -G audio,video pptruser \
    && mkdir -p /home/pptruser/Downloads \
    && chown -R pptruser:pptruser /home/pptruser
USER pptruser

# Variabili ambiente
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=false
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable
```

## 🔧 Troubleshooting Comune

### **Errore: "Package has no installation candidate" (Ubuntu 22.04+)**
```bash
# Problema: Nomi pacchetti cambiati nelle nuove versioni Ubuntu
# Soluzione: Usa comando aggiornato sopra, oppure installa pacchetti essenziali:

sudo apt-get install -y \
    fonts-liberation \
    libasound2t64 \
    libatk-bridge2.0-0t64 \
    libgtk-3-0t64 \
    libnss3 \
    lsb-release \
    xdg-utils

# Se ancora problemi, installa Chrome direttamente (include dipendenze):
wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
echo "deb http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt-get update
sudo apt-get install -y google-chrome-stable
```

### **Errore: "Chrome not found"**
```bash
# Soluzione 1: Installa Chrome
wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
echo "deb http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt-get update
sudo apt-get install -y google-chrome-stable

# Soluzione 2: Usa Chromium (alternativa)
sudo apt-get install -y chromium-browser
```

### **Errore: "Failed to launch chrome"**
```bash
# Aggiungi flags di sicurezza nel script Puppeteer
args: [
  '--no-sandbox',
  '--disable-setuid-sandbox',
  '--disable-dev-shm-usage',
  '--disable-extensions',
  '--disable-gpu',
  '--no-first-run'
]
```

### **Errore: "Out of memory"**
```bash
# Aumenta memoria disponibile
# In /etc/systemd/system/your-app.service
[Service]
MemoryMax=4G

# Oppure nel processo PHP
ini_set('memory_limit', '2G');
```

### **Errore: "Permission denied"**
```bash
# Fix permessi
sudo chown -R www-data:www-data /path/to/project/storage
sudo chmod -R 755 /path/to/project/storage/app/temp
```

## 📈 Performance Tuning

### **Configurazione Ottimale (config/logging.php)**
```php
'scraper' => [
    'driver' => 'daily',
    'path' => storage_path('logs/scraper.log'),
    'level' => 'info', // Ridotto in produzione
    'days' => 7,       // Riduci retention
],
```

### **Puppeteer Production Args**
```javascript
// Nel JavaScriptRenderer.php
browser = await puppeteer.launch({
  headless: true,
  args: [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-extensions',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-default-apps',
    '--disable-background-timer-throttling',
    '--disable-backgrounding-occluded-windows',
    '--disable-renderer-backgrounding',
    '--disable-features=TranslateUI',
    '--disable-ipc-flooding-protection'
  ],
  timeout: 60000
});
```

## ✅ Checklist Pre-Deploy

- [ ] Node.js >= 18.x installato
- [ ] Dipendenze sistema Chrome installate  
- [ ] Puppeteer installato in `backend/node_modules/`
- [ ] Test basic funzionante: `node test_puppeteer.cjs`
- [ ] Directory `storage/app/temp/` esistente e writable
- [ ] Permessi corretti (www-data)
- [ ] Memoria sufficiente (min 2GB raccomandati)
- [ ] Firewall configurato per outbound HTTP/HTTPS

## 📞 Support

Se hai problemi con il setup, controlla:

1. **Log scraper**: `tail -f storage/logs/scraper-$(date +%Y-%m-%d).log`
2. **Log Laravel**: `tail -f storage/logs/laravel.log`  
3. **Test manuale**: `cd backend && node test_puppeteer.cjs`
4. **Risorse sistema**: `free -h && df -h`
