#!/bin/bash

# 🚀 Milvus Production Setup Script for ChatBot Platform (FIXED)
# Questo script installa e configura Milvus in produzione su Ubuntu/Debian
# VERSIONE CORRETTA per risolvere conflitti Docker

set -e

echo "🚀 ChatBot Platform - Milvus Production Setup (Fixed)"
echo "====================================================="

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Funzioni helper
log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Controlla se siamo root
if [[ $EUID -eq 0 ]]; then
   log_error "Non eseguire questo script come root!"
   exit 1
fi

# Controlla sistema operativo
if [[ ! -f /etc/lsb-release ]] && [[ ! -f /etc/debian_version ]]; then
    log_error "Questo script è per Ubuntu/Debian. Sistema non supportato."
    exit 1
fi

log_info "Rilevato sistema Ubuntu/Debian"

# 1. Aggiorna sistema
log_info "Aggiornamento sistema..."
sudo apt update
sudo apt upgrade -y

# 2. RISOLUZIONE CONFLITTO DOCKER
log_info "Risoluzione conflitti Docker esistenti..."

# Rimuovi pacchetti Docker conflittuali
log_warning "Rimozione pacchetti Docker esistenti che potrebbero causare conflitti..."
sudo apt remove -y \
    docker \
    docker-engine \
    docker.io \
    containerd \
    runc \
    docker-ce \
    docker-ce-cli \
    containerd.io \
    docker-buildx-plugin \
    docker-compose-plugin 2>/dev/null || true

# Pulisci pacchetti
sudo apt autoremove -y
sudo apt autoclean

# 3. Installa dipendenze base
log_info "Installazione dipendenze base..."
sudo apt install -y \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    python3 \
    python3-pip \
    python3-venv \
    wget \
    git

# 4. Installa Docker dal repository ufficiale
log_info "Installazione Docker dal repository ufficiale..."

# Aggiungi chiave GPG Docker
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

# Aggiungi repository Docker
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Aggiorna e installa Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# 5. Verifica Python
log_info "Verifica installazione Python..."
python3 --version
pip3 --version

# 6. Installa PyMilvus
log_info "Installazione PyMilvus..."
pip3 install --user pymilvus

# 7. Verifica Docker
log_info "Configurazione Docker..."
sudo systemctl enable docker
sudo systemctl start docker
sudo usermod -aG docker $USER

log_warning "Dopo l'aggiunta al gruppo docker, dovrai fare logout/login"

# Test Docker
log_info "Test Docker..."
sudo docker run hello-world

# 8. INSTALLAZIONE MILVUS STANDALONE
log_info "Installazione Milvus Standalone..."

# Crea directory di lavoro
mkdir -p /tmp/milvus-setup
cd /tmp/milvus-setup

# Scarica Milvus Standalone
log_info "Download Milvus Standalone..."
wget https://github.com/milvus-io/milvus/releases/download/v2.4.15/milvus-standalone-docker-compose.yml -O docker-compose.yml

# Crea directory dati persistenti
sudo mkdir -p /opt/milvus/volumes/{etcd,minio,milvus}
sudo chown -R $USER:$USER /opt/milvus

# Modifica docker-compose per usare directory persistenti
sed -i 's|./volumes/|/opt/milvus/volumes/|g' docker-compose.yml

# Avvia Milvus
log_info "Avvio Milvus..."
sudo docker compose up -d

# Attendi che Milvus sia pronto
log_info "Attendo che Milvus sia pronto (60 secondi)..."
sleep 60

# 9. Verifica installazione Milvus
log_info "Verifica installazione Milvus..."

# Test connessione
python3 -c "
try:
    from pymilvus import connections, utility
    connections.connect('default', host='localhost', port='19530')
    print('✅ Milvus connessione OK!')
    print('📊 Server info:', utility.get_server_version())
    connections.disconnect('default')
except Exception as e:
    print('❌ Errore connessione:', str(e))
    exit(1)
"

log_success "Milvus installato e funzionante!"

# 10. Configurazione auto-start
log_info "Configurazione auto-start con systemd..."

# Crea servizio systemd
sudo tee /etc/systemd/system/milvus.service > /dev/null <<EOF
[Unit]
Description=Milvus Vector Database
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=true
ExecStart=/usr/bin/docker compose -f /opt/milvus/docker-compose.yml up -d
ExecStop=/usr/bin/docker compose -f /opt/milvus/docker-compose.yml down
WorkingDirectory=/opt/milvus
User=root

[Install]
WantedBy=multi-user.target
EOF

# Copia docker-compose in directory persistente
sudo cp docker-compose.yml /opt/milvus/

# Abilita servizio
sudo systemctl daemon-reload
sudo systemctl enable milvus

log_success "Auto-start configurato!"

# 11. Verifica servizio
log_info "Test servizio systemd..."
sudo systemctl start milvus
sleep 10
sudo systemctl status milvus --no-pager

# 12. Test finale
log_info "Test finale connessione..."
python3 -c "
try:
    from pymilvus import connections, utility
    connections.connect('default', host='localhost', port='19530')
    print('✅ Milvus servizio OK!')
    collections = utility.list_collections()
    print('📂 Collections:', collections)
    connections.disconnect('default')
except Exception as e:
    print('❌ Errore test finale:', str(e))
"

# 13. Configurazione .env
log_info "Generazione configurazione .env..."

cat << 'EOF'

📝 CONFIGURAZIONE .ENV per Laravel:
=====================================

Aggiungi queste variabili al tuo file .env:

# Milvus Configuration
MILVUS_HOST=127.0.0.1
MILVUS_PORT=19530
MILVUS_COLLECTION=kb_chunks_v1
MILVUS_PYTHON_PATH=python3
MILVUS_PARTITIONS_ENABLED=true

# RAG Vector Settings
RAG_VECTOR_DRIVER=milvus
RAG_VECTOR_METRIC=cosine
RAG_VECTOR_TOP_K=100

# Milvus Index Settings  
MILVUS_INDEX_TYPE=HNSW
MILVUS_HNSW_M=16
MILVUS_HNSW_EF_CONSTRUCTION=200
MILVUS_HNSW_EF=96

EOF

# 14. Comandi utili
cat << 'EOF'

🛠️  COMANDI UTILI:
==================

# Avvia Milvus
sudo systemctl start milvus

# Ferma Milvus  
sudo systemctl stop milvus

# Status Milvus
sudo systemctl status milvus

# Restart Milvus
sudo systemctl restart milvus

# Log Milvus containers
sudo docker compose -f /opt/milvus/docker-compose.yml logs -f

# Test connessione
python3 -c "from pymilvus import connections; connections.connect('default', host='localhost', port='19530'); print('OK!')"

# Setup Laravel collection
cd /path/to/your/laravel/backend
php artisan milvus:setup

# Status containers
sudo docker ps

# Spazio disco utilizzato
sudo du -sh /opt/milvus/volumes

EOF

echo ""
log_success "🎉 Milvus Production Setup completato!"
log_info "Logout/login per applicare i gruppi Docker, oppure esegui: newgrp docker"
log_warning "Non dimenticare di configurare il file .env come mostrato sopra"

echo ""
log_info "Next steps:"
echo "1. Logout/login o esegui: newgrp docker"
echo "2. Configura .env nel progetto Laravel"
echo "3. Esegui: php artisan milvus:setup"
echo "4. Testa: php artisan milvus:setup --check"

# Cleanup
cd /
rm -rf /tmp/milvus-setup

log_success "Setup completato! 🚀"
