#!/bin/bash

# 🚀 Milvus Production Setup Script for ChatBot Platform
# Questo script installa e configura Milvus in produzione su Ubuntu/Debian

set -e

echo "🚀 ChatBot Platform - Milvus Production Setup"
echo "=============================================="

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

# 2. Installa dipendenze
log_info "Installazione dipendenze..."
sudo apt install -y \
    python3 \
    python3-pip \
    python3-venv \
    curl \
    wget \
    docker.io \
    docker-compose \
    git

# 3. Verifica Python
log_info "Verifica installazione Python..."
python3 --version
pip3 --version

# 4. Installa PyMilvus
log_info "Installazione PyMilvus..."
pip3 install --user pymilvus

# 5. Verifica Docker
log_info "Configurazione Docker..."
sudo systemctl enable docker
sudo systemctl start docker
sudo usermod -aG docker $USER

log_warning "Dopo l'aggiunta al gruppo docker, dovrai fare logout/login"

# 6. Scarica Milvus Standalone
log_info "Download Milvus Standalone..."
cd /tmp
curl -sfL https://raw.githubusercontent.com/milvus-io/milvus/master/scripts/standalone_embed.sh -o standalone_embed.sh

# 7. Installa Milvus
log_info "Installazione Milvus..."
bash standalone_embed.sh start

# 8. Verifica installazione
log_info "Verifica installazione Milvus..."
sleep 10

# Test connessione
python3 -c "
try:
    from pymilvus import connections
    connections.connect('default', host='localhost', port='19530')
    print('✅ Milvus connessione OK!')
    connections.disconnect('default')
except Exception as e:
    print('❌ Errore connessione:', str(e))
    exit(1)
"

log_success "Milvus installato e funzionante!"

# 9. Configurazione auto-start
log_info "Configurazione auto-start..."

# Crea script di avvio
sudo tee /etc/systemd/system/milvus.service > /dev/null <<EOF
[Unit]
Description=Milvus Vector Database
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=true
ExecStart=/bin/bash /opt/milvus/start.sh
ExecStop=/bin/bash /opt/milvus/stop.sh
User=$USER

[Install]
WantedBy=multi-user.target
EOF

# Crea directory e script
sudo mkdir -p /opt/milvus
sudo tee /opt/milvus/start.sh > /dev/null <<'EOF'
#!/bin/bash
cd /tmp
curl -sfL https://raw.githubusercontent.com/milvus-io/milvus/master/scripts/standalone_embed.sh -o standalone_embed.sh
bash standalone_embed.sh start
EOF

sudo tee /opt/milvus/stop.sh > /dev/null <<'EOF'
#!/bin/bash
cd /tmp
curl -sfL https://raw.githubusercontent.com/milvus-io/milvus/master/scripts/standalone_embed.sh -o standalone_embed.sh
bash standalone_embed.sh stop
EOF

sudo chmod +x /opt/milvus/*.sh

# Abilita servizio
sudo systemctl daemon-reload
sudo systemctl enable milvus

log_success "Auto-start configurato!"

# 10. Configurazione .env
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

# 11. Comandi utili
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

# Log Milvus
sudo journalctl -u milvus -f

# Test connessione
python3 -c "from pymilvus import connections; connections.connect('default', host='localhost', port='19530'); print('OK!')"

# Setup Laravel collection
cd /path/to/your/laravel/backend
php artisan milvus:setup

EOF

echo ""
log_success "🎉 Milvus Production Setup completato!"
log_info "Riavvia il sistema o fai logout/login per applicare i gruppi Docker"
log_warning "Non dimenticare di configurare il file .env come mostrato sopra"

echo ""
log_info "Next steps:"
echo "1. Riavvia il sistema: sudo reboot"
echo "2. Configura .env nel progetto Laravel"
echo "3. Esegui: php artisan milvus:setup"
echo "4. Testa: php artisan milvus:setup --check"
