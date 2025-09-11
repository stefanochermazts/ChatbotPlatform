#!/bin/bash

# ⚙️ Setup Auto-Start for ChatBot Platform Production
# Configura automaticamente l'avvio di tutti i servizi dopo reboot

set -e

echo "⚙️ ChatBot Platform - Setup Auto-Start Services"
echo "==============================================="

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

# Controlla se siamo nel progetto Laravel
if [[ ! -f "artisan" ]]; then
    log_error "Esegui questo script dalla directory backend del progetto Laravel!"
    exit 1
fi

PROJECT_PATH=$(pwd)
log_info "Progetto: $PROJECT_PATH"

echo ""
log_info "🐳 Configurazione Docker Auto-Start..."

# 1. DOCKER ENGINE
log_info "Abilitazione Docker Engine..."
sudo systemctl enable docker
sudo systemctl start docker
log_success "Docker Engine abilitato per auto-start"

# 2. REDIS SERVER
echo ""
log_info "🔴 Configurazione Redis Auto-Start..."
if systemctl list-units --type=service | grep -q redis-server; then
    sudo systemctl enable redis-server
    sudo systemctl start redis-server
    log_success "Redis Server abilitato per auto-start"
else
    log_warning "Redis Server non trovato, potrebbe essere installato diversamente"
    # Prova varianti comuni
    for redis_service in redis redis.service; do
        if systemctl list-units --type=service | grep -q $redis_service; then
            sudo systemctl enable $redis_service
            sudo systemctl start $redis_service
            log_success "$redis_service abilitato per auto-start"
            break
        fi
    done
fi

# 3. POSTGRESQL
echo ""
log_info "🐘 Configurazione PostgreSQL Auto-Start..."
if systemctl list-units --type=service | grep -q postgresql; then
    sudo systemctl enable postgresql
    sudo systemctl start postgresql
    log_success "PostgreSQL abilitato per auto-start"
else
    log_warning "PostgreSQL non trovato come servizio systemd"
fi

# 4. WEB SERVER
echo ""
log_info "🌐 Configurazione Web Server Auto-Start..."
if systemctl list-units --type=service | grep -q apache2; then
    sudo systemctl enable apache2
    sudo systemctl start apache2
    log_success "Apache2 abilitato per auto-start"
elif systemctl list-units --type=service | grep -q nginx; then
    sudo systemctl enable nginx
    sudo systemctl start nginx
    log_success "Nginx abilitato per auto-start"
else
    log_warning "Nessun web server (Apache/Nginx) trovato"
fi

# 5. SUPERVISOR (Code Laravel)
echo ""
log_info "⚡ Configurazione Supervisor Auto-Start..."
if command -v supervisorctl >/dev/null 2>&1; then
    sudo systemctl enable supervisor
    sudo systemctl start supervisor
    log_success "Supervisor abilitato per auto-start"
    
    # Controlla configurazione worker Laravel
    if [[ -f "/etc/supervisor/conf.d/chatbot-worker.conf" ]]; then
        log_success "Configurazione worker Laravel trovata"
        sudo supervisorctl reread
        sudo supervisorctl update
        sudo supervisorctl start chatbot-worker:*
    else
        log_warning "Configurazione worker Laravel non trovata"
        log_info "Esegui: ./deploy-supervisor.sh"
    fi
else
    log_error "Supervisor non installato! Installa con: sudo apt install supervisor"
fi

# 6. DOCKER CONTAINERS (Milvus)
echo ""
log_info "🚀 Configurazione Docker Containers Auto-Restart..."

# Trova container Milvus
milvus_containers=$(docker ps -a --format "{{.Names}}" | grep -i milvus || true)

if [[ -n "$milvus_containers" ]]; then
    for container in $milvus_containers; do
        log_info "Configurazione restart policy per: $container"
        docker update --restart=unless-stopped "$container"
        
        # Avvia se non già running
        if ! docker ps --format "{{.Names}}" | grep -q "^$container$"; then
            docker start "$container"
        fi
        
        log_success "$container configurato per auto-restart"
    done
else
    log_warning "Nessun container Milvus trovato"
    log_info "Se usi docker-compose, verifica restart policy nel file YAML"
fi

# Trova altri container correlati (etcd, minio)
other_containers=$(docker ps -a --format "{{.Names}}" | grep -E "(etcd|minio)" || true)

if [[ -n "$other_containers" ]]; then
    for container in $other_containers; do
        log_info "Configurazione restart policy per: $container"
        docker update --restart=unless-stopped "$container"
        
        if ! docker ps --format "{{.Names}}" | grep -q "^$container$"; then
            docker start "$container"
        fi
        
        log_success "$container configurato per auto-restart"
    done
fi

# 7. SERVIZIO MILVUS PERSONALIZZATO (se esiste)
if [[ -f "/etc/systemd/system/milvus.service" ]]; then
    echo ""
    log_info "🎯 Configurazione Milvus Service Auto-Start..."
    sudo systemctl enable milvus
    sudo systemctl start milvus
    log_success "Milvus service abilitato per auto-start"
fi

# 8. VERIFICA FINALE
echo ""
log_info "🔍 Verifica finale configurazione..."

# Test servizi
services_to_check=("docker" "redis-server" "postgresql" "supervisor")

for service in "${services_to_check[@]}"; do
    if systemctl is-enabled "$service" >/dev/null 2>&1; then
        if systemctl is-active "$service" >/dev/null 2>&1; then
            log_success "$service: ✓ Abilitato e Running"
        else
            log_warning "$service: ✓ Abilitato ma non Running"
        fi
    else
        # Prova varianti
        case $service in
            "redis-server")
                if systemctl is-enabled redis >/dev/null 2>&1; then
                    log_success "redis: ✓ Abilitato"
                else
                    log_error "$service: ❌ Non abilitato"
                fi
                ;;
            *)
                log_error "$service: ❌ Non abilitato"
                ;;
        esac
    fi
done

# 9. CREA SCRIPT DI HEALTH CHECK POST-REBOOT
echo ""
log_info "📝 Creazione script di health check post-reboot..."

cat > "$PROJECT_PATH/post-reboot-check.sh" << 'EOF'
#!/bin/bash

# Health Check dopo Reboot
echo "🔍 Post-Reboot Health Check - $(date)"
echo "======================================"

echo ""
echo "🐳 Docker:"
sudo systemctl status docker --no-pager -l

echo ""
echo "🔴 Redis:" 
redis-cli ping || echo "Redis non risponde"

echo ""
echo "🐘 PostgreSQL:"
sudo -u postgres psql -c "SELECT version();" 2>/dev/null || echo "PostgreSQL non raggiungibile"

echo ""
echo "🚀 Milvus:"
docker ps | grep -i milvus || echo "Container Milvus non trovati"

echo ""
echo "⚡ Supervisor:"
sudo supervisorctl status chatbot-worker:* || echo "Worker Laravel non attivi"

echo ""
echo "🌐 Web Server:"
curl -I http://localhost >/dev/null 2>&1 && echo "Web server OK" || echo "Web server non risponde"

echo ""
echo "📊 Sistema:"
uptime
free -h | head -2
df -h | head -2

echo ""
echo "✅ Health check completato - $(date)"
EOF

chmod +x "$PROJECT_PATH/post-reboot-check.sh"

log_success "Script health check creato: $PROJECT_PATH/post-reboot-check.sh"

# 10. SUMMARY
echo ""
log_success "🎉 Setup Auto-Start completato!"

echo ""
log_info "📋 SERVIZI CONFIGURATI PER AUTO-START:"
echo "  ✅ Docker Engine"
echo "  ✅ Redis Server"  
echo "  ✅ PostgreSQL Database"
echo "  ✅ Web Server (Apache/Nginx)"
echo "  ✅ Supervisor (Laravel Queues)"
echo "  ✅ Container Docker con restart policy"

echo ""
log_warning "🧪 TEST REBOOT:"
echo "  1. Esegui: sudo reboot"
echo "  2. Dopo reboot: ./post-reboot-check.sh"
echo "  3. Verifica: ./check-auto-start.sh"

echo ""
log_info "🔧 MONITORAGGIO CONTINUO:"
echo "  • Health check: ./post-reboot-check.sh"
echo "  • Auto-start status: ./check-auto-start.sh"
echo "  • Queue monitoring: ./monitor-queues.sh"

echo ""
log_success "Sistema configurato per resilienza automatica! 🚀"
