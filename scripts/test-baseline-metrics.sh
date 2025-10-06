#!/bin/bash

################################################################################
# Baseline Metrics Test Script
# 
# Automated testing script for Step 1 - Performance Optimization Plan
# Tests latency tracking, queue lag, and generates baseline report
#
# Usage:
#   ./scripts/test-baseline-metrics.sh
#   ./scripts/test-baseline-metrics.sh --production
#   ./scripts/test-baseline-metrics.sh --help
#
# Requirements:
#   - curl, jq, bc, redis-cli
#   - Access to Laravel backend
#   - Valid API key in environment or .env
################################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BACKEND_DIR="$PROJECT_ROOT/backend"
REPORT_DIR="$PROJECT_ROOT/baseline-reports"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="$REPORT_DIR/baseline-report-$TIMESTAMP.json"

# Environment
ENVIRONMENT="local"
BASE_URL="${BASE_URL:-http://localhost:8000}"
API_KEY="${API_KEY:-}"
TENANT_ID="${TENANT_ID:-1}"
TEST_REQUESTS=10
SLEEP_BETWEEN_REQUESTS=2

################################################################################
# Helper Functions
################################################################################

print_header() {
    echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

check_dependencies() {
    local missing=()
    
    for cmd in curl jq bc redis-cli php; do
        if ! command -v $cmd &> /dev/null; then
            missing+=($cmd)
        fi
    done
    
    if [ ${#missing[@]} -ne 0 ]; then
        print_error "Missing required commands: ${missing[*]}"
        print_info "Install with: sudo apt-get install curl jq bc redis-tools php-cli"
        exit 1
    fi
    
    print_success "All dependencies available"
}

load_env() {
    if [ -f "$BACKEND_DIR/.env" ]; then
        # Load .env but don't override existing env vars
        set -a
        source <(grep -v '^#' "$BACKEND_DIR/.env" | grep -v '^$')
        set +a
        print_success "Loaded .env configuration"
    else
        print_warning "No .env file found at $BACKEND_DIR/.env"
    fi
    
    # Override with environment-specific values if passed
    if [ "$1" == "--production" ]; then
        ENVIRONMENT="production"
        BASE_URL="${PRODUCTION_BASE_URL:-https://crowdmai.maia.chat}"
        API_KEY="${PRODUCTION_API_KEY:-$API_KEY}"
        print_info "Testing against PRODUCTION: $BASE_URL"
    else
        print_info "Testing against LOCAL: $BASE_URL"
    fi
    
    # Validate API key
    if [ -z "$API_KEY" ]; then
        print_error "API_KEY not set. Set it in .env or export API_KEY=your-key"
        exit 1
    fi
}

################################################################################
# Test Functions
################################################################################

test_health_check() {
    print_header "1. Health Check"
    
    local response=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/up" 2>&1)
    
    if [ "$response" == "200" ]; then
        print_success "Health check passed (HTTP $response)"
        return 0
    else
        print_error "Health check failed (HTTP $response)"
        return 1
    fi
}

test_middleware() {
    print_header "2. Latency Middleware Test"
    
    local test_payload='{"model":"gpt-4o-mini","messages":[{"role":"user","content":"Test request for baseline metrics"}],"temperature":0.7,"max_tokens":100}'
    
    print_info "Sending test request..."
    
    local response=$(curl -s -i -X POST "$BASE_URL/api/v1/chat/completions" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $API_KEY" \
        -d "$test_payload" 2>&1)
    
    # Extract headers
    local latency_header=$(echo "$response" | grep -i "X-Latency-Ms:" | cut -d: -f2 | tr -d ' \r\n')
    local correlation_header=$(echo "$response" | grep -i "X-Correlation-Id:" | cut -d: -f2 | tr -d ' \r\n')
    local http_status=$(echo "$response" | grep "HTTP/" | head -1 | awk '{print $2}')
    
    if [ -n "$latency_header" ] && [ -n "$correlation_header" ]; then
        print_success "Middleware working correctly"
        print_info "  Latency: ${latency_header}ms"
        print_info "  Correlation ID: $correlation_header"
        print_info "  HTTP Status: $http_status"
        echo "$correlation_header" > /tmp/test_correlation_id
        return 0
    else
        print_error "Middleware headers not found"
        print_warning "Response preview:"
        echo "$response" | head -20
        return 1
    fi
}

test_redis_storage() {
    print_header "3. Redis Storage Test"
    
    print_info "Checking Redis connection..."
    
    if ! redis-cli ping &> /dev/null; then
        print_error "Redis not accessible"
        return 1
    fi
    
    print_success "Redis connection OK"
    
    # Check latency data
    print_info "Checking stored metrics for tenant $TENANT_ID..."
    
    local count=$(redis-cli LLEN "latency:chat:$TENANT_ID" 2>/dev/null || echo "0")
    local stats_exists=$(redis-cli EXISTS "latency:stats:$TENANT_ID:today" 2>/dev/null || echo "0")
    
    if [ "$count" -gt 0 ]; then
        print_success "Found $count requests in Redis"
        
        # Get latest request
        local latest=$(redis-cli LINDEX "latency:chat:$TENANT_ID" 0 2>/dev/null)
        if [ -n "$latest" ]; then
            print_info "Latest request:"
            echo "$latest" | jq -C '.' 2>/dev/null || echo "$latest"
        fi
    else
        print_warning "No metrics found in Redis (this is OK for first run)"
    fi
    
    if [ "$stats_exists" == "1" ]; then
        print_success "Daily stats found"
        local total_reqs=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_requests 2>/dev/null || echo "0")
        local total_latency=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_latency_ms 2>/dev/null || echo "0")
        local total_cost=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_cost_usd 2>/dev/null || echo "0")
        
        print_info "Today's stats:"
        print_info "  Total Requests: $total_reqs"
        print_info "  Total Latency: ${total_latency}ms"
        print_info "  Total Cost: \$${total_cost}"
    fi
    
    return 0
}

test_logs() {
    print_header "4. Structured Logs Test"
    
    local log_file=$(ls -t "$BACKEND_DIR/storage/logs/latency-"*.log 2>/dev/null | head -1)
    
    if [ -z "$log_file" ]; then
        print_warning "No latency log file found"
        return 1
    fi
    
    print_success "Log file found: $(basename $log_file)"
    
    # Check if log has valid JSON
    local last_line=$(tail -1 "$log_file")
    
    if echo "$last_line" | jq . &> /dev/null; then
        print_success "Log contains valid JSON"
        print_info "Latest log entry:"
        echo "$last_line" | jq -C '.' | head -15
    else
        print_warning "Log format may be incorrect"
    fi
    
    # Count entries today
    local today=$(date +%Y-%m-%d)
    local count=$(grep "$today" "$log_file" 2>/dev/null | wc -l)
    print_info "Entries today: $count"
    
    return 0
}

test_queue_lag() {
    print_header "5. Queue Lag Test"
    
    print_info "Running queue lag monitoring..."
    
    cd "$BACKEND_DIR"
    
    local output=$(php artisan metrics:queue-lag --json 2>&1)
    
    if [ $? -eq 0 ]; then
        print_success "Queue lag command executed"
        echo "$output" | jq -C '.'
        
        # Save to temp file for report
        echo "$output" > /tmp/queue_lag_result.json
        
        # Check for high lag
        local high_lag=$(echo "$output" | jq -r 'to_entries[] | select(.value.lag_seconds > 30) | .key' 2>/dev/null)
        
        if [ -n "$high_lag" ]; then
            print_warning "High lag detected in queues: $high_lag"
        else
            print_success "All queues within acceptable lag"
        fi
    else
        print_error "Queue lag command failed"
        print_info "Output: $output"
        return 1
    fi
    
    return 0
}

run_load_test() {
    print_header "6. Synthetic Load Test"
    
    print_info "Sending $TEST_REQUESTS requests with ${SLEEP_BETWEEN_REQUESTS}s interval..."
    
    local latencies=()
    local tokens=()
    local costs=()
    local errors=0
    local success=0
    
    local test_queries=(
        "What are your opening hours?"
        "Do you offer wheelchair accessibility?"
        "Tell me about your services"
        "How can I contact customer support?"
        "What are the payment methods accepted?"
    )
    
    for i in $(seq 1 $TEST_REQUESTS); do
        local query="${test_queries[$((i % ${#test_queries[@]}))]}"
        local payload=$(jq -n --arg q "$query" '{model:"gpt-4o-mini",messages:[{role:"user",content:$q}],temperature:0.7,max_tokens:200}')
        
        echo -n "  Request $i/$TEST_REQUESTS... "
        
        local start_time=$(date +%s%3N)
        local response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/v1/chat/completions" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $API_KEY" \
            -d "$payload" 2>&1)
        local end_time=$(date +%s%3N)
        
        local http_code=$(echo "$response" | tail -1)
        local body=$(echo "$response" | sed '$d')
        local latency=$((end_time - start_time))
        
        if [ "$http_code" == "200" ]; then
            echo -e "${GREEN}OK${NC} (${latency}ms)"
            success=$((success + 1))
            latencies+=($latency)
            
            # Extract tokens and cost
            local total_tokens=$(echo "$body" | jq -r '.usage.total_tokens // 0' 2>/dev/null)
            local prompt_tokens=$(echo "$body" | jq -r '.usage.prompt_tokens // 0' 2>/dev/null)
            local completion_tokens=$(echo "$body" | jq -r '.usage.completion_tokens // 0' 2>/dev/null)
            
            if [ "$total_tokens" != "0" ]; then
                tokens+=($total_tokens)
                
                # Calculate cost (gpt-4o-mini: $0.15/1M input, $0.60/1M output)
                local cost=$(echo "scale=8; ($prompt_tokens * 0.15 + $completion_tokens * 0.60) / 1000000" | bc)
                costs+=($cost)
            fi
        else
            echo -e "${RED}FAIL${NC} (HTTP $http_code)"
            errors=$((errors + 1))
        fi
        
        # Sleep between requests (except last)
        if [ $i -lt $TEST_REQUESTS ]; then
            sleep $SLEEP_BETWEEN_REQUESTS
        fi
    done
    
    # Calculate statistics
    if [ ${#latencies[@]} -gt 0 ]; then
        local avg_latency=$(echo "${latencies[@]}" | tr ' ' '\n' | awk '{sum+=$1} END {print sum/NR}')
        local min_latency=$(echo "${latencies[@]}" | tr ' ' '\n' | sort -n | head -1)
        local max_latency=$(echo "${latencies[@]}" | tr ' ' '\n' | sort -n | tail -1)
        local p95_latency=$(echo "${latencies[@]}" | tr ' ' '\n' | sort -n | awk '{all[NR]=$1} END {print all[int(NR*0.95)]}')
        
        print_success "Load test completed"
        print_info "Results:"
        print_info "  Success Rate: $success/$TEST_REQUESTS ($((success * 100 / TEST_REQUESTS))%)"
        print_info "  Avg Latency: ${avg_latency}ms"
        print_info "  Min Latency: ${min_latency}ms"
        print_info "  Max Latency: ${max_latency}ms"
        print_info "  P95 Latency: ${p95_latency}ms"
        
        if [ "$p95_latency" -lt 2500 ]; then
            print_success "P95 latency within target (<2500ms) ✅"
        else
            print_warning "P95 latency above target (${p95_latency}ms > 2500ms)"
        fi
        
        # Save results for report
        cat > /tmp/load_test_result.json << EOF
{
    "total_requests": $TEST_REQUESTS,
    "successful_requests": $success,
    "failed_requests": $errors,
    "success_rate_percent": $((success * 100 / TEST_REQUESTS)),
    "avg_latency_ms": $avg_latency,
    "min_latency_ms": $min_latency,
    "max_latency_ms": $max_latency,
    "p95_latency_ms": $p95_latency
}
EOF
        
        if [ ${#tokens[@]} -gt 0 ]; then
            local avg_tokens=$(echo "${tokens[@]}" | tr ' ' '\n' | awk '{sum+=$1} END {print sum/NR}')
            local total_cost=$(echo "${costs[@]}" | tr ' ' '\n' | awk '{sum+=$1} END {print sum}')
            local avg_cost=$(echo "$total_cost ${#costs[@]}" | awk '{print $1/$2}')
            
            print_info "  Avg Tokens: ${avg_tokens}"
            print_info "  Avg Cost: \$${avg_cost}"
            print_info "  Total Cost: \$${total_cost}"
        fi
    else
        print_error "No successful requests"
        return 1
    fi
    
    return 0
}

generate_report() {
    print_header "7. Generating Report"
    
    mkdir -p "$REPORT_DIR"
    
    # Collect all data
    local queue_lag="{}"
    if [ -f /tmp/queue_lag_result.json ]; then
        queue_lag=$(cat /tmp/queue_lag_result.json)
    fi
    
    local load_test="{}"
    if [ -f /tmp/load_test_result.json ]; then
        load_test=$(cat /tmp/load_test_result.json)
    fi
    
    # Get Redis stats
    local redis_stats="{}"
    if redis-cli ping &> /dev/null; then
        local total_reqs=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_requests 2>/dev/null || echo "0")
        local total_latency=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_latency_ms 2>/dev/null || echo "0")
        local total_cost=$(redis-cli HGET "latency:stats:$TENANT_ID:today" total_cost_usd 2>/dev/null || echo "0")
        
        redis_stats=$(jq -n \
            --arg reqs "$total_reqs" \
            --arg lat "$total_latency" \
            --arg cost "$total_cost" \
            '{total_requests: ($reqs|tonumber), total_latency_ms: ($lat|tonumber), total_cost_usd: ($cost|tonumber)}')
    fi
    
    # Generate final report
    jq -n \
        --arg ts "$TIMESTAMP" \
        --arg env "$ENVIRONMENT" \
        --arg url "$BASE_URL" \
        --arg tenant "$TENANT_ID" \
        --argjson queue "$queue_lag" \
        --argjson load "$load_test" \
        --argjson redis "$redis_stats" \
        '{
            report_timestamp: $ts,
            environment: $env,
            base_url: $url,
            tenant_id: ($tenant|tonumber),
            queue_lag: $queue,
            load_test: $load,
            redis_daily_stats: $redis,
            baseline_established: true,
            next_steps: [
                "Review P95 latency and identify bottlenecks",
                "If embedding queue lag >30s, proceed with Step 6 (Batch Embeddings)",
                "If scraping queue lag >30s, proceed with Step 3 (Optimize Scraper)",
                "If P95 >2.5s, analyze correlation IDs in logs",
                "Schedule metrics:queue-lag --store every 5 minutes"
            ]
        }' > "$REPORT_FILE"
    
    print_success "Report saved to: $REPORT_FILE"
    
    # Display summary
    print_info "\n📊 Report Summary:"
    cat "$REPORT_FILE" | jq -C '.' | head -40
    
    # Cleanup temp files
    rm -f /tmp/queue_lag_result.json /tmp/load_test_result.json /tmp/test_correlation_id
    
    return 0
}

show_next_steps() {
    print_header "Next Steps & Recommendations"
    
    # Analyze results and give recommendations
    if [ -f "$REPORT_FILE" ]; then
        local p95=$(jq -r '.load_test.p95_latency_ms // 0' "$REPORT_FILE")
        local error_rate=$(jq -r '.load_test.failed_requests // 0' "$REPORT_FILE")
        
        echo -e "${YELLOW}Based on your baseline metrics:${NC}\n"
        
        if (( $(echo "$p95 < 2500" | bc -l) )); then
            print_success "P95 latency is within target (${p95}ms < 2500ms)"
            echo "  → System is performing well"
        else
            print_warning "P95 latency exceeds target (${p95}ms > 2500ms)"
            echo "  → Priority: Investigate slow requests using correlation IDs"
            echo "  → Consider Step 5 (Chunking) or Step 8 (Retriever tuning)"
        fi
        
        echo ""
        
        # Check queue lag from report
        local has_high_lag=$(jq -r '.queue_lag | to_entries[] | select(.value.lag_seconds > 30) | .key' "$REPORT_FILE" 2>/dev/null)
        
        if [ -n "$has_high_lag" ]; then
            print_warning "High queue lag detected: $has_high_lag"
            echo "  → Priority actions:"
            for queue in $has_high_lag; do
                if [ "$queue" == "embedding" ]; then
                    echo "    • Step 6: Batch Embeddings (reduce lag + cost)"
                elif [ "$queue" == "scraping" ]; then
                    echo "    • Step 3: Optimize Scraper (async + caching)"
                elif [ "$queue" == "parsing" ]; then
                    echo "    • Step 4: Cache Parsing (SHA-256 + Redis)"
                fi
            done
        else
            print_success "All queues have acceptable lag (<30s)"
        fi
        
        echo ""
        print_info "📖 Full documentation: docs/baseline-metrics-guide.md"
        print_info "📊 Optimization plan: .artiforge/plan-rag-performance-optimization-v1.md"
    fi
}

################################################################################
# Main Execution
################################################################################

main() {
    echo -e "${BLUE}"
    echo "╔═══════════════════════════════════════════════════════════════╗"
    echo "║                                                               ║"
    echo "║         Baseline Metrics Test - Step 1                       ║"
    echo "║         Performance Optimization Plan                        ║"
    echo "║                                                               ║"
    echo "╚═══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}\n"
    
    # Check dependencies
    check_dependencies
    
    # Load environment
    load_env "$@"
    
    # Run tests
    local failed=0
    
    test_health_check || failed=$((failed + 1))
    test_middleware || failed=$((failed + 1))
    test_redis_storage || true  # Don't fail on Redis (optional)
    test_logs || true  # Don't fail on logs (optional)
    test_queue_lag || true  # Don't fail on queue (optional)
    run_load_test || failed=$((failed + 1))
    
    # Generate report even if some tests failed
    generate_report
    
    # Show recommendations
    show_next_steps
    
    # Final summary
    print_header "Test Summary"
    
    if [ $failed -eq 0 ]; then
        print_success "All critical tests passed! ✅"
        print_info "Baseline metrics established successfully"
        return 0
    else
        print_warning "$failed critical test(s) failed"
        print_info "Review errors above and check configuration"
        return 1
    fi
}

# Show help
if [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --production    Test against production environment"
    echo "  --help, -h      Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  BASE_URL        API base URL (default: http://localhost:8000)"
    echo "  API_KEY         API authentication key (required)"
    echo "  TENANT_ID       Tenant ID to test (default: 1)"
    echo ""
    echo "Examples:"
    echo "  $0                                    # Test local"
    echo "  $0 --production                       # Test production"
    echo "  API_KEY=xxx TENANT_ID=5 $0            # Custom config"
    exit 0
fi

# Run main
main "$@"

