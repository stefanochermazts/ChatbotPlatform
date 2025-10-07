#!/bin/bash
# Baseline Metrics Test Script (Bash - Simplified)
# Quick test for Step 1 - Performance Optimization Plan

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
PRODUCTION=false
API_KEY=""
TENANT_ID=1

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --production)
            PRODUCTION=true
            shift
            ;;
        --api-key)
            API_KEY="$2"
            shift 2
            ;;
        --tenant-id)
            TENANT_ID="$2"
            shift 2
            ;;
        *)
            echo -e "${RED}[ERROR] Unknown parameter: $1${NC}"
            exit 1
            ;;
    esac
done

# Set base URL
if [ "$PRODUCTION" = true ]; then
    BASE_URL="https://crowdmai.maia.chat"
else
    BASE_URL="https://chatbotplatform.test:8443"
fi

echo -e "\n${CYAN}=== Baseline Metrics Test ===${NC}"
echo -e "${CYAN}Environment: $([ "$PRODUCTION" = true ] && echo "Production" || echo "Local")${NC}"
echo -e "${CYAN}Base URL: $BASE_URL${NC}\n"

# Load API key from .env if not provided
if [ -z "$API_KEY" ]; then
    if [ -f "backend/.env" ]; then
        API_KEY=$(grep "^API_KEY=" backend/.env | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    fi
fi

if [ -z "$API_KEY" ]; then
    echo -e "${RED}[ERROR] API_KEY not set. Use --api-key parameter or set in .env${NC}"
    exit 1
fi

# Test 1: Health Check
echo -e "\n${YELLOW}[1/5] Health Check...${NC}"
HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" "$BASE_URL/up" --max-time 10)
if [ "$HTTP_CODE" = "200" ]; then
    echo -e "  ${GREEN}[OK] Health check passed${NC}"
else
    echo -e "  ${RED}[ERROR] Health check failed (HTTP $HTTP_CODE)${NC}"
    exit 1
fi

# Test 2: Latency Middleware
echo -e "\n${YELLOW}[2/5] Testing Latency Middleware...${NC}"
RESPONSE=$(curl -k -s -w "\n%{http_code}\n%{header_json}" -X POST "$BASE_URL/api/v1/chat/completions" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $API_KEY" \
    -d '{
        "model": "gpt-4o-mini",
        "messages": [{"role": "user", "content": "Test baseline metrics"}],
        "temperature": 0.7,
        "max_tokens": 100
    }' --max-time 30)

HTTP_CODE=$(echo "$RESPONSE" | tail -n 2 | head -n 1)
HEADERS=$(echo "$RESPONSE" | tail -n 1)

if [ "$HTTP_CODE" = "200" ]; then
    LATENCY=$(echo "$HEADERS" | grep -o '"x-latency-ms":\["[^"]*"\]' | cut -d '"' -f4 || echo "N/A")
    CORRELATION=$(echo "$HEADERS" | grep -o '"x-correlation-id":\["[^"]*"\]' | cut -d '"' -f4 || echo "N/A")
    
    if [ "$LATENCY" != "N/A" ] && [ "$CORRELATION" != "N/A" ]; then
        echo -e "  ${GREEN}[OK] Middleware active${NC}"
        echo -e "  ${CYAN}Latency: $LATENCY ms${NC}"
        echo -e "  ${CYAN}Correlation ID: $CORRELATION${NC}"
    else
        echo -e "  ${YELLOW}[WARNING] Middleware headers not found${NC}"
    fi
else
    echo -e "  ${RED}[ERROR] Request failed (HTTP $HTTP_CODE)${NC}"
fi

# Test 3: Redis
echo -e "\n${YELLOW}[3/5] Testing Redis Storage...${NC}"
if command -v redis-cli &> /dev/null; then
    REDIS_PING=$(redis-cli ping 2>&1 || echo "ERROR")
    if [ "$REDIS_PING" = "PONG" ]; then
        echo -e "  ${GREEN}[OK] Redis connection OK${NC}"
        COUNT=$(redis-cli LLEN "latency:chat:$TENANT_ID" 2>&1 || echo "0")
        echo -e "  ${CYAN}Found $COUNT metrics in Redis${NC}"
    else
        echo -e "  ${YELLOW}[WARNING] Redis not accessible (optional)${NC}"
    fi
else
    echo -e "  ${YELLOW}[WARNING] redis-cli not found (optional)${NC}"
fi

# Test 4: Queue Lag
echo -e "\n${YELLOW}[4/5] Testing Queue Lag...${NC}"
cd backend
QUEUE_OUTPUT=$(php artisan metrics:queue-lag --json 2>&1 || echo "{}")
cd ..

if [ "$QUEUE_OUTPUT" != "{}" ]; then
    echo -e "  ${GREEN}[OK] Queue lag command executed${NC}"
    echo "$QUEUE_OUTPUT" | jq -r 'to_entries[] | "  [\(if .value.lag_seconds > 30 then "WARNING" else "OK" end)] \(.key): \(.value.lag_seconds) seconds lag"' 2>/dev/null || echo "  $QUEUE_OUTPUT"
else
    echo -e "  ${YELLOW}[WARNING] Queue lag check failed (optional)${NC}"
fi

# Test 5: Quick Load Test
echo -e "\n${YELLOW}[5/5] Running Quick Load Test (5 requests)...${NC}"
LATENCIES=()
SUCCESS=0

for i in {1..5}; do
    echo -n "  Request $i/5..."
    
    START=$(date +%s%3N)
    HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/v1/chat/completions" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $API_KEY" \
        -d "{
            \"model\": \"gpt-4o-mini\",
            \"messages\": [{\"role\": \"user\", \"content\": \"Test request $i\"}],
            \"temperature\": 0.7,
            \"max_tokens\": 100
        }" --max-time 30)
    END=$(date +%s%3N)
    
    LATENCY=$((END - START))
    
    if [ "$HTTP_CODE" = "200" ]; then
        LATENCIES+=($LATENCY)
        SUCCESS=$((SUCCESS + 1))
        echo -e " ${GREEN}OK ($LATENCY ms)${NC}"
    else
        echo -e " ${RED}FAILED${NC}"
    fi
    
    [ $i -lt 5 ] && sleep 1
done

# Results
echo -e "\n${CYAN}=== Results ===${NC}"

if [ ${#LATENCIES[@]} -gt 0 ]; then
    # Calculate stats
    SUM=0
    MIN=${LATENCIES[0]}
    MAX=${LATENCIES[0]}
    
    for lat in "${LATENCIES[@]}"; do
        SUM=$((SUM + lat))
        [ $lat -lt $MIN ] && MIN=$lat
        [ $lat -gt $MAX ] && MAX=$lat
    done
    
    AVG=$((SUM / ${#LATENCIES[@]}))
    
    # P95 (simplified: just use max for 5 samples)
    IFS=$'\n' SORTED=($(sort -n <<<"${LATENCIES[*]}"))
    P95_INDEX=$(( ${#SORTED[@]} * 95 / 100 ))
    P95=${SORTED[$P95_INDEX]}
    
    echo -e "${CYAN}Success Rate: $SUCCESS/5 ($((SUCCESS * 100 / 5))%)${NC}"
    echo -e "${CYAN}Avg Latency: $AVG ms${NC}"
    echo -e "${CYAN}Min Latency: $MIN ms${NC}"
    echo -e "${CYAN}Max Latency: $MAX ms${NC}"
    echo -e "${CYAN}P95 Latency: $P95 ms${NC}"
    
    if [ $P95 -lt 2500 ]; then
        echo -e "\n${GREEN}[OK] P95 latency within target (<2500ms)${NC}"
    else
        echo -e "\n${YELLOW}[WARNING] P95 latency above target (${P95}ms > 2500ms)${NC}"
    fi
fi

echo -e "\n${CYAN}=== Next Steps ===${NC}"
echo "1. Review P95 latency and queue lag above"
echo "2. If embedding queue lag >30s, proceed with Step 6 (Batch Embeddings)"
echo "3. If P95 >2.5s, analyze correlation IDs in logs"
echo "4. See full documentation: docs/baseline-metrics-guide.md"

echo -e "\n${CYAN}=== Test Complete ===${NC}\n"
