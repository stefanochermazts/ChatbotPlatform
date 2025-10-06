# Baseline Metrics Guide
**Step 1 - Performance Optimization Plan**

## Overview

This guide explains how to establish baseline metrics for the ChatbotPlatform RAG system to track latency, throughput, cost, and queue performance.

## Components

### 1. Latency Middleware

**File**: `backend/app/Http/Middleware/LatencyMetrics.php`

Tracks end-to-end request latency for all API endpoints:

- Request start/end timestamps
- Token usage (prompt + completion)
- OpenAI API cost calculation
- Tenant-aware metrics
- Correlation IDs for distributed tracing

**Features**:
- ✅ Feature-flagged (`LATENCY_METRICS_ENABLED=true`)
- ✅ Multi-backend emission (Redis, Logs, Prometheus)
- ✅ Minimal overhead (<5ms)
- ✅ Error-resilient

**Metrics Collected**:
- `latency_ms`: Request duration in milliseconds
- `tokens.total_tokens`: Total tokens used
- `cost_usd`: Calculated OpenAI API cost
- `status`: HTTP status code
- `is_error`: Boolean indicating errors

### 2. Redis Storage

**Purpose**: Short-term metrics storage for UI dashboards

**Keys**:
- `latency:chat:{tenant_id}`: Last 100 requests (1 hour TTL)
- `latency:stats:{tenant_id}:today`: Daily aggregates

**Schema**:
```json
{
  "correlation_id": "uuid",
  "timestamp": "2025-10-06T16:00:00Z",
  "tenant_id": 5,
  "endpoint": "v1/chat/completions",
  "method": "POST",
  "status": 200,
  "latency_ms": 1234.56,
  "is_error": false,
  "tokens": {
    "model": "gpt-4o-mini",
    "prompt_tokens": 150,
    "completion_tokens": 200,
    "total_tokens": 350
  },
  "cost_usd": 0.000225
}
```

### 3. Structured Logging

**File**: `storage/logs/latency.log`

JSON-formatted logs with correlation IDs for traceability.

**Retention**: 7 days (daily rotation)

**Log Channel**: `latency` (see `config/logging.php`)

### 4. Queue Lag Monitoring

**Command**: `php artisan metrics:queue-lag`

Monitors Horizon queue lag and emits metrics.

**Usage**:
```bash
# Display table output
php artisan metrics:queue-lag

# JSON output
php artisan metrics:queue-lag --json

# Store in Redis
php artisan metrics:queue-lag --store
```

**Queues Monitored**:
- `default`
- `scraping`
- `parsing`
- `embedding`
- `indexing`
- `ingestion`

**Metrics**:
- `pending_count`: Number of jobs waiting
- `lag_seconds`: Age of oldest pending job
- Alert threshold: 30 seconds

### 5. Load Testing with k6

**Script**: `scripts/chat_latency.js`

Synthetic load test to establish P95 latency baseline.

**Installation**:
```bash
# Windows (Chocolatey)
choco install k6

# macOS (Homebrew)
brew install k6

# Linux
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

**Usage**:
```bash
# Development
k6 run --vus 20 --duration 2m scripts/chat_latency.js

# Production
k6 run --vus 20 --duration 2m \
  -e BASE_URL=https://your-domain.com \
  -e API_KEY=your-api-key \
  -e TENANT_ID=5 \
  scripts/chat_latency.js
```

**Test Scenarios**:
- 10 VUs for 30s (ramp-up)
- 20 VUs for 1m (steady state)
- 0 VUs for 30s (ramp-down)

**Thresholds**:
- ✅ P95 latency < 2.5s
- ✅ Error rate < 5%
- ✅ Success rate > 95%

**Output**:
- Console summary with pass/fail indicators
- `baseline-report.json` with detailed metrics

## Setup Instructions

### 1. Enable Latency Tracking

Add to `.env`:
```env
LATENCY_METRICS_ENABLED=true
```

**Development** (optional):
```env
LATENCY_METRICS_ENABLED=false
```

### 2. Clear Caches

```bash
cd backend
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

### 3. Test Middleware

```bash
# Make a test request
curl -X POST http://localhost:8000/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-api-key" \
  -d '{
    "model": "gpt-4o-mini",
    "messages": [{"role": "user", "content": "Hello"}]
  }'

# Check response headers
# X-Latency-Ms: 1234.56
# X-Correlation-Id: uuid

# Check Redis
redis-cli
> LRANGE latency:chat:1 0 -1
> HGETALL latency:stats:1:today
```

### 4. Monitor Queue Lag

```bash
# Run once
php artisan metrics:queue-lag

# Schedule (add to crontab or Task Scheduler)
*/5 * * * * cd /path/to/backend && php artisan metrics:queue-lag --store
```

### 5. Run Load Test

```bash
# Install k6 (if not already)
choco install k6  # Windows
brew install k6   # macOS

# Run baseline test
k6 run --vus 20 --duration 2m scripts/chat_latency.js

# Check report
cat baseline-report.json
```

### 6. View Logs

```bash
# Tail latency log
tail -f backend/storage/logs/latency-*.log

# Parse JSON logs
tail -f backend/storage/logs/latency-*.log | jq .

# Filter by tenant
tail -f backend/storage/logs/latency-*.log | jq 'select(.tenant_id == 5)'

# Calculate average latency
tail -100 backend/storage/logs/latency-*.log | jq '.latency_ms' | awk '{sum+=$1; n++} END {print sum/n}'
```

## Prometheus Integration (TODO - Step 2)

The middleware includes placeholder code for Prometheus metrics. To complete this:

1. Install `promphp/prometheus_client_php`:
```bash
composer require promphp/prometheus_client_php
```

2. Create `/metrics` endpoint in `routes/api.php`:
```php
Route::get('/metrics', [MetricsController::class, 'prometheus']);
```

3. Uncomment Prometheus code in `LatencyMetrics::emitToPrometheus()`

4. Configure Prometheus scraper:
```yaml
scrape_configs:
  - job_name: 'chatbot-platform'
    scrape_interval: 15s
    static_configs:
      - targets: ['localhost:8000']
    metrics_path: '/api/metrics'
```

## OpenAI Pricing Reference

Current pricing (as of 2025):

| Model | Input (per 1M tokens) | Output (per 1M tokens) |
|-------|----------------------|------------------------|
| gpt-4o | $5.00 | $15.00 |
| gpt-4o-mini | $0.15 | $0.60 |
| gpt-4-turbo | $10.00 | $30.00 |
| text-embedding-3-small | $0.02 | - |
| text-embedding-3-large | $0.13 | - |

Update `LatencyMetrics::PRICING` array when prices change.

## Expected Baseline Results

**Before Optimization**:
- P95 Latency: TBD (establish baseline)
- Avg Tokens: 300-500
- Avg Cost: $0.0002-0.0005
- Error Rate: <5%
- Queue Lag: Variable (check current state)

**Target After Optimization** (Step 15):
- P95 Latency: ≤ 2.5s
- Cost Reduction: 30-50%
- Queue Lag: <10s
- Error Rate: <2%

## Troubleshooting

### Middleware Not Running

Check:
```bash
php artisan route:list --middleware=LatencyMetrics
```

Verify in `bootstrap/app.php`:
```php
$middleware->api(append: [
    \App\Http\Middleware\LatencyMetrics::class,
]);
```

### Redis Connection Issues

Test connection:
```bash
php artisan tinker
> Redis::ping()
> Redis::set('test', 'value')
> Redis::get('test')
```

### k6 Test Fails

Check:
1. API key is valid
2. Base URL is correct
3. `/up` health endpoint is accessible
4. CORS is configured for test origin

### High Latency in Baseline

If P95 > 2.5s, investigate:
1. Queue lag (`php artisan metrics:queue-lag`)
2. Database slow queries (enable query log)
3. External API timeouts (OpenAI, Milvus)
4. Network latency (ping test)

## Next Steps

Once baseline is established:

1. **Analyze bottlenecks** using correlation IDs in logs
2. **Compare metrics** before/after each optimization step
3. **Set up alerts** for regression (P95 > 3s, error rate > 5%)
4. **Proceed to Step 2**: Distributed Tracing with OpenTelemetry

---

**See Also**:
- [Performance Optimization Plan](.artiforge/plan-rag-performance-optimization-v1.md)
- [RAG Configuration](rag.md)
- [Horizon Setup](horizon-setup-guide.md)

