# Testing Scripts

Automated testing scripts for ChatbotPlatform performance optimization.

## Available Scripts

### 1. Baseline Metrics Test

Comprehensive test suite for Step 1 of the Performance Optimization Plan.

**Files**:
- `test-baseline-metrics.sh` (Linux/macOS)
- `test-baseline-metrics.ps1` (Windows PowerShell)
- `chat_latency.js` (k6 load test)

---

## test-baseline-metrics (Bash/PowerShell)

Automated script that tests all baseline metrics components:

✅ Health check  
✅ Latency middleware  
✅ Redis storage  
✅ Structured logs  
✅ Queue lag monitoring  
✅ Synthetic load test  
✅ Report generation  

### Prerequisites

**Linux/macOS**:
```bash
# Install dependencies
sudo apt-get install curl jq bc redis-tools php-cli  # Debian/Ubuntu
brew install curl jq redis php                       # macOS
```

**Windows**:
```powershell
# Install jq from: https://jqlang.github.io/jq/download/
# curl is built-in on Windows 10+
# Install Redis from: https://redis.io/download
```

### Usage

**Linux/macOS (Bash)**:

```bash
# Make executable
chmod +x scripts/test-baseline-metrics.sh

# Test local environment
./scripts/test-baseline-metrics.sh

# Test production
./scripts/test-baseline-metrics.sh --production

# Custom configuration
BASE_URL=http://localhost:8000 \
API_KEY=your-key \
TENANT_ID=5 \
./scripts/test-baseline-metrics.sh

# Help
./scripts/test-baseline-metrics.sh --help
```

**Windows (PowerShell)**:

```powershell
# Test local environment
.\scripts\test-baseline-metrics.ps1

# Test production
.\scripts\test-baseline-metrics.ps1 -Production

# Custom configuration
.\scripts\test-baseline-metrics.ps1 -ApiKey "your-key" -TenantId 5 -BaseUrl "http://localhost:8000"

# Help
.\scripts\test-baseline-metrics.ps1 -Help
```

### Output

The script generates a comprehensive JSON report in `baseline-reports/`:

```json
{
  "report_timestamp": "20251006_163000",
  "environment": "production",
  "base_url": "https://crowdmai.maia.chat",
  "tenant_id": 5,
  "queue_lag": {
    "default": {"pending_count": 0, "lag_seconds": 0},
    "scraping": {"pending_count": 5, "lag_seconds": 12},
    "embedding": {"pending_count": 120, "lag_seconds": 45}
  },
  "load_test": {
    "total_requests": 10,
    "successful_requests": 10,
    "success_rate_percent": 100,
    "avg_latency_ms": 1456.23,
    "p95_latency_ms": 2234.56
  },
  "redis_daily_stats": {
    "total_requests": 87,
    "total_latency_ms": 126793.45,
    "total_cost_usd": 0.019542
  },
  "next_steps": [
    "Review P95 latency and identify bottlenecks",
    "If embedding queue lag >30s, proceed with Step 6"
  ]
}
```

### What It Tests

#### 1. Health Check
- Verifies `/up` endpoint is accessible
- Ensures basic connectivity

#### 2. Latency Middleware
- Sends test chat completion request
- Verifies `X-Latency-Ms` and `X-Correlation-Id` headers
- Confirms middleware is active

#### 3. Redis Storage
- Checks Redis connectivity
- Verifies metrics storage in `latency:chat:{tenant_id}`
- Displays latest request data
- Shows daily aggregates

#### 4. Structured Logs
- Locates `storage/logs/latency-*.log`
- Verifies JSON format
- Counts today's entries

#### 5. Queue Lag
- Runs `php artisan metrics:queue-lag --json`
- Identifies queues with lag >30s
- Stores results for report

#### 6. Load Test
- Sends 10 requests (configurable)
- Uses realistic queries
- Calculates:
  - Avg, Min, Max, P95 latency
  - Success rate
  - Token usage
  - Cost per request
- Compares P95 against 2500ms target

---

## chat_latency.js (k6)

Advanced load testing script using Grafana k6.

### Prerequisites

Install k6:

```bash
# Windows
choco install k6

# macOS
brew install k6

# Linux
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | \
  sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

### Usage

```bash
# Basic test (20 VUs, 2 minutes)
k6 run --vus 20 --duration 2m scripts/chat_latency.js

# Production test
k6 run --vus 20 --duration 2m \
  -e BASE_URL=https://crowdmai.maia.chat \
  -e API_KEY=your-production-key \
  -e TENANT_ID=5 \
  scripts/chat_latency.js

# Custom load profile
k6 run --vus 50 --duration 5m scripts/chat_latency.js

# Save results to file
k6 run --vus 20 --duration 2m scripts/chat_latency.js \
  --out json=baseline-k6-results.json
```

### Test Scenarios

The script runs a 3-stage load test:

1. **Ramp-up** (30s): 0 → 10 VUs
2. **Steady-state** (60s): 20 VUs
3. **Ramp-down** (30s): 20 → 0 VUs

### Thresholds

- ✅ P95 latency < 2500ms
- ✅ Error rate < 5%
- ✅ Success rate > 95%

If any threshold fails, the test returns non-zero exit code.

### Output

```
📊 ===== BASELINE METRICS =====
   P95 Latency: 2234.56 ms ✅
   Avg Latency: 1456.23 ms
   Error Rate: 0.00% ✅
   Avg Tokens: 342
   Avg Cost: $0.000225
   Total Requests: 120
================================
```

Also generates `baseline-report.json` with detailed metrics.

---

## Interpreting Results

### P95 Latency

| Result | Status | Action |
|--------|--------|--------|
| < 2000ms | 🟢 Excellent | Continue with other optimizations |
| 2000-2500ms | 🟡 Acceptable | Monitor, proceed with plan |
| 2500-3500ms | 🟠 Warning | Priority: Step 5 (Chunking) or Step 8 (Retriever) |
| > 3500ms | 🔴 Critical | Immediate investigation needed |

### Queue Lag

| Queue | Lag | Status | Priority Step |
|-------|-----|--------|---------------|
| embedding | >30s | ⚠️ High | Step 6: Batch Embeddings |
| scraping | >30s | ⚠️ High | Step 3: Optimize Scraper |
| parsing | >30s | ⚠️ High | Step 4: Cache Parsing |
| any | <10s | ✅ Good | Continue monitoring |

### Error Rate

| Rate | Status | Action |
|------|--------|--------|
| 0% | 🟢 Perfect | Excellent stability |
| < 2% | 🟡 Good | Monitor patterns |
| 2-5% | 🟠 Warning | Investigate errors |
| > 5% | 🔴 Critical | Fix before optimization |

### Cost

Baseline cost helps measure optimization impact:

- **Target**: 30-50% reduction after Step 6 (Batch Embeddings)
- **Track**: Daily cost trend
- **Alert**: Unexpected spikes (>2x baseline)

---

## Troubleshooting

### Script Fails on Health Check

**Problem**: `/up` endpoint returns non-200

**Solution**:
```bash
# Check if server is running
curl -I http://localhost:8000/up

# Check Laravel logs
tail -f backend/storage/logs/laravel.log

# Verify .env configuration
grep APP_URL backend/.env
```

### Middleware Headers Not Found

**Problem**: `X-Latency-Ms` header missing

**Solution**:
```bash
# Verify feature flag is enabled
grep LATENCY_METRICS_ENABLED backend/.env

# Should be:
# LATENCY_METRICS_ENABLED=true

# Clear caches
cd backend
php artisan config:clear
php artisan cache:clear
php artisan config:cache

# Verify middleware registration
php artisan route:list | grep LatencyMetrics
```

### Redis Connection Failed

**Problem**: `redis-cli ping` fails

**Solution**:
```bash
# Check Redis is running
redis-cli ping
# Expected: PONG

# Start Redis
sudo systemctl start redis  # Linux
brew services start redis   # macOS
# Windows: Start Redis service

# Verify .env
grep REDIS_HOST backend/.env
```

### Queue Lag Command Fails

**Problem**: `php artisan metrics:queue-lag` errors

**Solution**:
```bash
# Check Horizon is installed
cd backend
composer show laravel/horizon

# If missing:
composer require laravel/horizon

# Verify Horizon is running (production)
sudo supervisorctl status chatbot-horizon

# Start Horizon (local)
php artisan horizon
```

### API Key Invalid

**Problem**: HTTP 401 Unauthorized

**Solution**:
```bash
# Get valid API key from database
cd backend
php artisan tinker
>>> \App\Models\Tenant::find(1)->getWidgetApiKey()

# Or create new user/tenant
php artisan tinker
>>> $tenant = \App\Models\Tenant::find(1);
>>> $tenant->api_key = \Str::random(64);
>>> $tenant->save();
>>> echo $tenant->api_key;
```

### k6 Not Found

**Problem**: `command not found: k6`

**Solution**:
```bash
# Install k6 (see Prerequisites above)

# Verify installation
k6 version

# If still not found, add to PATH
export PATH=$PATH:/usr/local/bin  # Linux/macOS
```

---

## Scheduling Automated Tests

### Cron (Linux/macOS)

```bash
# Edit crontab
crontab -e

# Run test daily at 2 AM
0 2 * * * cd /path/to/project && ./scripts/test-baseline-metrics.sh --production >> /var/log/baseline-test.log 2>&1

# Run queue lag every 5 minutes
*/5 * * * * cd /path/to/backend && php artisan metrics:queue-lag --store >> /dev/null 2>&1
```

### Task Scheduler (Windows)

```powershell
# Create scheduled task
$action = New-ScheduledTaskAction -Execute "powershell.exe" `
  -Argument "-File C:\path\to\scripts\test-baseline-metrics.ps1 -Production"

$trigger = New-ScheduledTaskTrigger -Daily -At 2am

Register-ScheduledTask -TaskName "BaselineMetricsTest" `
  -Action $action -Trigger $trigger
```

---

## Next Steps

After running baseline tests:

1. **Review report** in `baseline-reports/`
2. **Identify bottlenecks** using queue lag and P95 latency
3. **Choose next optimization step**:
   - High embedding lag → **Step 6** (Batch Embeddings)
   - High scraping lag → **Step 3** (Optimize Scraper)
   - High P95 latency → **Step 5** (Chunking) or **Step 8** (Retriever)
4. **Commit baseline** to Git for comparison
5. **Proceed with optimization plan**

---

## Related Documentation

- [Baseline Metrics Guide](../docs/baseline-metrics-guide.md)
- [Performance Optimization Plan](../.artiforge/plan-rag-performance-optimization-v1.md)
- [RAG Configuration](../docs/rag.md)
- [Horizon Setup](../docs/horizon-setup-guide.md)

