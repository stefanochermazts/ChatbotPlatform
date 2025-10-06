# 🚀 Quick Start - Baseline Metrics Test

## In 5 Minuti

### Windows (PowerShell)

```powershell
# 1. Setup
cd C:\laragon\www\ChatbotPlatform\backend
code .env  # Aggiungi: LATENCY_METRICS_ENABLED=true

# 2. Run test locale
cd ..
.\scripts\test-baseline-metrics.ps1

# 3. View report
cat .\baseline-reports\baseline-report-*.json | jq .
```

### Linux/macOS (Bash)

```bash
# 1. Setup
cd ~/ChatbotPlatform/backend
nano .env  # Aggiungi: LATENCY_METRICS_ENABLED=true

# 2. Make executable & run
cd ..
chmod +x scripts/test-baseline-metrics.sh
./scripts/test-baseline-metrics.sh

# 3. View report
cat baseline-reports/baseline-report-*.json | jq .
```

---

## Test Produzione

### 1. Deploy

```bash
# Sul server
cd ~/public_html/backend
git pull origin main
php artisan config:cache
sudo supervisorctl restart chatbot-horizon
```

### 2. Abilita Metrics

Aggiungi al `.env` di produzione:
```env
LATENCY_METRICS_ENABLED=true
PRODUCTION_BASE_URL=https://crowdmai.maia.chat
PRODUCTION_API_KEY=your-production-key
```

### 3. Run Test

```bash
# Dal tuo PC (remoto)
.\scripts\test-baseline-metrics.ps1 -Production
```

O direttamente sul server:
```bash
cd ~/public_html
./scripts/test-baseline-metrics.sh --production
```

---

## Cosa Aspettarsi

### Output Console

```
╔═══════════════════════════════════════════════════════════════╗
║         Baseline Metrics Test - Step 1                       ║
╚═══════════════════════════════════════════════════════════════╝

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  1. Health Check
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Health check passed (HTTP 200)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  2. Latency Middleware Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ℹ️  Sending test request...
✅ Middleware working correctly
ℹ️    Latency: 1456.23ms
ℹ️    Correlation ID: uuid-xxxxx
ℹ️    HTTP Status: 200

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  3. Redis Storage Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Redis connection OK
✅ Found 10 requests in Redis
ℹ️    Latest request:
{
  "correlation_id": "uuid-xxxxx",
  "latency_ms": 1234.56,
  "cost_usd": 0.000225
}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  4. Queue Lag Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Queue lag command executed
{
  "scraping": {"pending_count": 5, "lag_seconds": 12},
  "embedding": {"pending_count": 120, "lag_seconds": 45}
}
⚠️  High lag detected in queues: embedding

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  5. Synthetic Load Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Request 1/10... OK (1234ms)
  Request 2/10... OK (1456ms)
  ...
  Request 10/10... OK (1123ms)

✅ Load test completed
ℹ️  Results:
ℹ️    Success Rate: 10/10 (100%)
ℹ️    Avg Latency: 1456.23ms
ℹ️    P95 Latency: 2234.56ms
✅ P95 latency within target (<2500ms) ✅
ℹ️    Avg Tokens: 342
ℹ️    Avg Cost: $0.000225

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Test Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ All critical tests passed! ✅
ℹ️  Baseline metrics established successfully
```

### Report JSON

File: `baseline-reports/baseline-report-YYYYMMDD_HHMMSS.json`

```json
{
  "report_timestamp": "20251006_163000",
  "environment": "production",
  "base_url": "https://crowdmai.maia.chat",
  "tenant_id": 5,
  "queue_lag": {
    "embedding": {
      "pending_count": 120,
      "lag_seconds": 45
    }
  },
  "load_test": {
    "p95_latency_ms": 2234.56,
    "avg_latency_ms": 1456.23,
    "success_rate_percent": 100
  },
  "next_steps": [
    "If embedding queue lag >30s, proceed with Step 6"
  ]
}
```

---

## Interpretazione Risultati

### ✅ Tutto OK

```
P95 Latency: 2234.56 ms ✅
Queue Lag: <30s for all queues ✅
Error Rate: 0% ✅
```

**→ Prossimo Step**: Proceed with Step 2 (Distributed Tracing)

### ⚠️ Embedding Lag Alto

```
embedding: 120 pending, 45s lag ⚠️
```

**→ Prossimo Step**: **Step 6 - Batch Embeddings** (HIGH PRIORITY)
- Riduce lag da 45s → <10s
- Riduce costo 30-40%
- Impact: 🔥🔥🔥

### ⚠️ P95 > 2.5s

```
P95 Latency: 3456.78 ms ❌
```

**→ Prossimo Step**: **Step 5 - Standardize Chunking** o **Step 8 - Retriever Tuning**
- Ottimizza token budget
- Tune hybrid retrieval
- Impact: 🔥🔥

---

## Troubleshooting Rapido

### ❌ "LATENCY_METRICS_ENABLED not set"

```bash
cd backend
echo "LATENCY_METRICS_ENABLED=true" >> .env
php artisan config:cache
```

### ❌ "API_KEY not set"

```bash
# Get key from database
cd backend
php artisan tinker
>>> \App\Models\Tenant::find(1)->getWidgetApiKey()
>>> exit

# Set in environment
export API_KEY="your-key"  # Linux/macOS
$env:API_KEY="your-key"    # PowerShell
```

### ❌ "Redis connection failed"

```bash
# Check Redis
redis-cli ping  # Should return PONG

# Start Redis
sudo systemctl start redis       # Linux
brew services start redis        # macOS
# Windows: Services → Redis → Start
```

### ❌ "jq: command not found"

```bash
# Install jq
sudo apt-get install jq          # Linux
brew install jq                  # macOS
# Windows: https://jqlang.github.io/jq/download/
```

---

## Prossimi Passi

Dopo aver eseguito il test:

1. ✅ **Rivedi report** in `baseline-reports/`
2. ✅ **Identifica bottleneck** (queue lag, P95 latency)
3. ✅ **Scegli prossimo step**:
   - Embedding lag >30s → **Step 6** (Batch Embeddings)
   - Scraping lag >30s → **Step 3** (Optimize Scraper)
   - P95 >2.5s → **Step 5** (Chunking) o **Step 8** (Retriever)
4. ✅ **Commit baseline** per confronto futuro:
   ```bash
   git add baseline-reports/
   git commit -m "docs: Baseline metrics before optimization"
   git push
   ```
5. ✅ **Procedi con ottimizzazione** usando il piano in `.artiforge/`

---

## Help & Documentation

- 📖 **Script Details**: `scripts/README.md`
- 📊 **Baseline Guide**: `docs/baseline-metrics-guide.md`
- 🎯 **Full Plan**: `.artiforge/plan-rag-performance-optimization-v1.md`
- 🔧 **RAG Config**: `docs/rag.md`

---

**Domande?** Controlla la documentazione o esegui:
```bash
./scripts/test-baseline-metrics.sh --help  # Linux/macOS
.\scripts\test-baseline-metrics.ps1 -Help  # Windows
```

