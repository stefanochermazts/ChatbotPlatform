#!/bin/bash

# 🚀 Deploy Supervisor Configuration for ChatBot Platform Queues
# Questo script configura Supervisor per gestire le code Laravel in produzione

set -e

echo "🚀 ChatBot Platform - Supervisor Queue Setup"
echo "============================================="

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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

# Controlla se siamo nel progetto
if [[ ! -f "artisan" ]]; then
    log_error "Esegui questo script dalla directory backend del progetto Laravel!"
    exit 1
fi

# Ottieni percorso assoluto
PROJECT_PATH=$(pwd)
USER_NAME=$(whoami)

log_info "Configurazione Supervisor per ChatBot Platform"
log_info "Progetto: $PROJECT_PATH"
log_info "Utente: $USER_NAME"

# 1. Verifica Supervisor installato
if ! command -v supervisorctl &> /dev/null; then
    log_error "Supervisor non installato. Installa con: sudo apt install supervisor"
    exit 1
fi

log_success "Supervisor è installato"

# 2. Crea directory log se non esiste
mkdir -p storage/logs
chmod 775 storage/logs

# 3. Crea configurazione personalizzata per questo progetto
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/chatbot-worker.conf"

log_info "Creazione configurazione Supervisor..."

sudo tee $SUPERVISOR_CONFIG > /dev/null <<EOF
[program:chatbot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --queue=default,ingestion,embeddings,indexing,evaluation
directory=$PROJECT_PATH
autostart=true
autorestart=true
startretries=3
user=$USER_NAME
numprocs=2
redirect_stderr=true
stdout_logfile=$PROJECT_PATH/storage/logs/worker.log
stdout_logfile_maxbytes=100MB
stdout_logfile_backups=2
stopwaitsecs=3600
EOF

log_success "Configurazione creata: $SUPERVISOR_CONFIG"

# 4. Ricarica configurazione Supervisor
log_info "Ricaricamento configurazione Supervisor..."
sudo supervisorctl reread
sudo supervisorctl update

# 5. Avvia i worker
log_info "Avvio worker Laravel..."
sudo supervisorctl start chatbot-worker:*

# 6. Verifica stato
log_info "Verifica stato worker..."
sudo supervisorctl status chatbot-worker:*

# 7. Abilita auto-start di Supervisor
sudo systemctl enable supervisor

log_success "🎉 Setup Supervisor completato!"

echo ""
log_info "📋 COMANDI UTILI:"
echo "  • Stato worker:     sudo supervisorctl status chatbot-worker:*"
echo "  • Riavvia worker:   sudo supervisorctl restart chatbot-worker:*"
echo "  • Stop worker:      sudo supervisorctl stop chatbot-worker:*"
echo "  • Start worker:     sudo supervisorctl start chatbot-worker:*"
echo "  • Log worker:       tail -f $PROJECT_PATH/storage/logs/worker.log"
echo "  • Reload config:    sudo supervisorctl reread && sudo supervisorctl update"

echo ""
log_warning "📝 DEPLOY FUTURE:"
echo "  Dopo ogni deploy, riavvia i worker con:"
echo "  sudo supervisorctl restart chatbot-worker:*"

echo ""
log_info "🔍 MONITORING:"
echo "  • Log worker: tail -f storage/logs/worker.log"
echo "  • Queue status: php artisan queue:work --once --verbose"
echo "  • Failed jobs: php artisan queue:failed"
echo "  • Queue stats: php artisan queue:monitor"

echo ""
log_success "Worker Laravel configurati e avviati! ✨"
