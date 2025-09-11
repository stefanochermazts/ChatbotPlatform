#!/bin/bash

# 🔄 Check Auto-Start Services for ChatBot Platform
# Verifica che tutti i servizi critici si avviino automaticamente dopo reboot

echo "🔄 ChatBot Platform - Auto-Start Services Check"
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

check_service_enabled() {
    local service_name=$1
    local display_name=$2
    
    if systemctl is-enabled $service_name >/dev/null 2>&1; then
        if systemctl is-active $service_name >/dev/null 2>&1; then
            log_success "$display_name: ENABLED + RUNNING"
        else
            log_warning "$display_name: ENABLED but NOT RUNNING"
        fi
    else
        log_error "$display_name: NOT ENABLED for auto-start"
    fi
}

echo ""
log_info "Controllo servizi sistemici..."

# 1. DOCKER
echo ""
echo "🐳 DOCKER:"
check_service_enabled "docker" "Docker Engine"

# 2. REDIS  
echo ""
echo "🔴 REDIS:"
check_service_enabled "redis-server" "Redis Server"

# 3. POSTGRESQL
echo ""
echo "🐘 POSTGRESQL:"
check_service_enabled "postgresql" "PostgreSQL Database"

# 4. SUPERVISOR (Code Laravel)
echo ""
echo "⚡ SUPERVISOR (Laravel Queues):"
check_service_enabled "supervisor" "Supervisor Process Manager"

# 5. APACHE/NGINX
echo ""
echo "🌐 WEB SERVER:"
if systemctl list-units --type=service | grep -q apache2; then
    check_service_enabled "apache2" "Apache2 Web Server"
elif systemctl list-units --type=service | grep -q nginx; then
    check_service_enabled "nginx" "Nginx Web Server"
else
    log_warning "Nessun web server rilevato (Apache/Nginx)"
fi

# 6. MILVUS (Docker Container)
echo ""
echo "🚀 MILVUS:"
log_info "Controllo container Docker Milvus..."

if docker ps -a --format "table {{.Names}}\t{{.Status}}" | grep -i milvus >/dev/null 2>&1; then
    milvus_status=$(docker ps -a --format "table {{.Names}}\t{{.Status}}" | grep -i milvus)
    if echo "$milvus_status" | grep -q "Up"; then
        log_success "Milvus container: RUNNING"
    else
        log_error "Milvus container: EXISTS but NOT RUNNING"
    fi
    
    # Controlla restart policy
    restart_policy=$(docker inspect --format='{{.HostConfig.RestartPolicy.Name}}' $(docker ps -a --format "{{.Names}}" | grep -i milvus | head -n1) 2>/dev/null)
    if [[ "$restart_policy" == "always" ]] || [[ "$restart_policy" == "unless-stopped" ]]; then
        log_success "Milvus restart policy: $restart_policy (auto-restart abilitato)"
    else
        log_error "Milvus restart policy: $restart_policy (auto-restart NON abilitato)"
    fi
else
    log_error "Nessun container Milvus trovato"
fi

# 7. VERIFICA SERVIZIO MILVUS PERSONALIZZATO
if systemctl list-units --type=service | grep -q milvus; then
    echo ""
    echo "🎯 MILVUS SERVICE:"
    check_service_enabled "milvus" "Milvus Custom Service"
fi

echo ""
log_info "📋 RIEPILOGO CONFIGURAZIONE AUTO-START"

echo ""
echo "📝 SERVIZI CHE DEVONO ESSERE ENABLED:"
echo "  • docker          ✓ Gestisce container Milvus"
echo "  • redis-server    ✓ Cache e code Laravel" 
echo "  • postgresql      ✓ Database principale"
echo "  • supervisor      ✓ Code Laravel worker"
echo "  • apache2/nginx   ✓ Web server"
echo "  • milvus (opt)    ✓ Servizio Milvus standalone"

echo ""
echo "🐳 CONTAINER DOCKER CHE DEVONO ESSERE restart=always:"
echo "  • milvus-*        ✓ Vector database"
echo "  • etcd            ✓ Milvus metadata"
echo "  • minio           ✓ Milvus storage"

echo ""
log_info "🔧 COMANDI PER ABILITARE AUTO-START:"

echo ""
echo "# Abilita servizi sistemici:"
echo "sudo systemctl enable docker"
echo "sudo systemctl enable redis-server" 
echo "sudo systemctl enable postgresql"
echo "sudo systemctl enable supervisor"
echo "sudo systemctl enable apache2  # o nginx"

echo ""
echo "# Configura restart policy container Docker:"
echo "docker update --restart=always \$(docker ps -a --format '{{.Names}}' | grep -i milvus)"

echo ""
echo "# Test reboot (ATTENZIONE!):"
echo "sudo reboot"

echo ""
log_info "🚀 Esegui setup-auto-start.sh per configurare automaticamente tutto"
