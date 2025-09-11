#!/bin/bash

# 🔍 Monitor ChatBot Platform Queues
# Script per monitorare lo stato delle code Laravel

echo "🔍 ChatBot Platform - Queue Monitoring"
echo "======================================"

# Colori
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}📊 SUPERVISOR STATUS${NC}"
sudo supervisorctl status chatbot-worker:*

echo ""
echo -e "${BLUE}📈 QUEUE STATISTICS${NC}"
cd /home/crowdmai/public_html/backend

# Job in attesa per queue
echo "Jobs in attesa:"
php artisan queue:monitor default ingestion embeddings indexing evaluation

echo ""
echo -e "${BLUE}❌ FAILED JOBS${NC}"
php artisan queue:failed

echo ""
echo -e "${BLUE}📋 WORKER PROCESSES${NC}"
ps aux | grep "queue:work" | grep -v grep

echo ""
echo -e "${BLUE}🔗 NETWORK CONNECTIONS${NC}"
echo "Redis connections:"
netstat -an | grep :6379 | wc -l

echo "Database connections:"
netstat -an | grep :5432 | wc -l

echo ""
echo -e "${BLUE}💾 MEMORY USAGE${NC}"
free -h

echo ""
echo -e "${BLUE}📁 DISK USAGE${NC}"
df -h | grep -E "(/$|/home)"

echo ""
echo -e "${BLUE}📝 RECENT WORKER LOGS (last 20 lines)${NC}"
tail -20 storage/logs/worker.log

echo ""
echo -e "${GREEN}✅ Monitoring completato!${NC}"
