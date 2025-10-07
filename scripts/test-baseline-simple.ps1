# Baseline Metrics Test Script (PowerShell - Simplified)
# Quick test for Step 1 - Performance Optimization Plan

param(
    [switch]$Production,
    [string]$ApiKey = "",
    [int]$TenantId = 1
)

$ErrorActionPreference = "Stop"

# Configuration
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$BackendDir = Join-Path $ProjectRoot "backend"
$BaseUrl = if ($Production) { "https://crowdmai.maia.chat" } else { "https://chatbotplatform.test:8443" }

Write-Host "`n=== Baseline Metrics Test ===" -ForegroundColor Cyan
Write-Host "Environment: $(if ($Production) {'Production'} else {'Local'})" -ForegroundColor Cyan
Write-Host "Base URL: $BaseUrl`n" -ForegroundColor Cyan

# Load API key from .env if not provided
if (-not $ApiKey) {
    $envFile = Join-Path $BackendDir ".env"
    if (Test-Path $envFile) {
        $ApiKey = (Get-Content $envFile | Where-Object { $_ -match '^API_KEY=' } | Select-Object -First 1) -replace 'API_KEY=', '' -replace '"', ''
    }
}

if (-not $ApiKey) {
    Write-Host "[ERROR] API_KEY not set. Use -ApiKey parameter or set in .env" -ForegroundColor Red
    exit 1
}

# Skip SSL certificate validation for local development (Windows PowerShell 5.1 compatible)
if (-not $Production) {
    # Try to add the type if it doesn't exist
    if (-not ([System.Management.Automation.PSTypeName]'TrustAllCertsPolicy').Type) {
        add-type @"
            using System.Net;
            using System.Security.Cryptography.X509Certificates;
            public class TrustAllCertsPolicy : ICertificatePolicy {
                public bool CheckValidationResult(
                    ServicePoint srvPoint, X509Certificate certificate,
                    WebRequest request, int certificateProblem) {
                    return true;
                }
            }
"@
    }
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 -bor [Net.SecurityProtocolType]::Tls
}

# Test 1: Health Check
Write-Host "`n[1/5] Health Check..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "$BaseUrl/up" -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "  [OK] Health check passed" -ForegroundColor Green
    }
} catch {
    Write-Host "  [ERROR] Health check failed: $_" -ForegroundColor Red
    exit 1
}

# Test 2: Latency Middleware
Write-Host "`n[2/5] Testing Latency Middleware..." -ForegroundColor Yellow
try {
    $payload = @{
        model = "gpt-4o-mini"
        messages = @(@{ role = "user"; content = "Test baseline metrics" })
        temperature = 0.7
        max_tokens = 100
    } | ConvertTo-Json -Compress
    
    $headers = @{
        "Content-Type" = "application/json"
        "Authorization" = "Bearer $ApiKey"
    }
    
    $response = Invoke-WebRequest -Uri "$BaseUrl/api/v1/chat/completions" -Method POST -Headers $headers -Body $payload -UseBasicParsing -TimeoutSec 30
    
    $latency = $response.Headers['X-Latency-Ms']
    $correlation = $response.Headers['X-Correlation-Id']
    
    if ($latency -and $correlation) {
        Write-Host "  [OK] Middleware active" -ForegroundColor Green
        Write-Host "  Latency: $latency ms" -ForegroundColor Cyan
        Write-Host "  Correlation ID: $correlation" -ForegroundColor Cyan
    } else {
        Write-Host "  [ERROR] Middleware headers not found" -ForegroundColor Red
    }
} catch {
    Write-Host "  [ERROR] Request failed: $_" -ForegroundColor Red
}

# Test 3: Redis
Write-Host "`n[3/5] Testing Redis Storage..." -ForegroundColor Yellow
try {
    $ping = redis-cli ping 2>&1
    if ($ping -match "PONG") {
        Write-Host "  [OK] Redis connection OK" -ForegroundColor Green
        
        $count = redis-cli LLEN "latency:chat:$TenantId" 2>&1
        if ($count -match '^\d+$') {
            Write-Host "  Found $count metrics in Redis" -ForegroundColor Cyan
        }
    }
} catch {
    Write-Host "  [WARNING] Redis not accessible (optional)" -ForegroundColor Yellow
}

# Test 4: Queue Lag
Write-Host "`n[4/5] Testing Queue Lag..." -ForegroundColor Yellow
try {
    Push-Location $BackendDir
    $output = php artisan metrics:queue-lag --json 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  [OK] Queue lag command executed" -ForegroundColor Green
        $queues = $output | ConvertFrom-Json
        foreach ($queue in $queues.PSObject.Properties) {
            $lag = $queue.Value.lag_seconds
            $status = if ($lag -gt 30) { "[WARNING]" } else { "[OK]" }
            $color = if ($lag -gt 30) { "Yellow" } else { "Green" }
            Write-Host "  $status $($queue.Name): $lag seconds lag" -ForegroundColor $color
        }
    }
    Pop-Location
} catch {
    Write-Host "  [WARNING] Queue lag check failed (optional)" -ForegroundColor Yellow
    Pop-Location
}

# Test 5: Quick Load Test
Write-Host "`n[5/5] Running Quick Load Test (5 requests)..." -ForegroundColor Yellow
$latencies = @()
$success = 0

for ($i = 1; $i -le 5; $i++) {
    Write-Host "  Request $i/5..." -NoNewline
    
    try {
        $payload = @{
            model = "gpt-4o-mini"
            messages = @(@{ role = "user"; content = "Test request $i" })
            temperature = 0.7
            max_tokens = 100
        } | ConvertTo-Json -Compress
        
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $response = Invoke-RestMethod -Uri "$BaseUrl/api/v1/chat/completions" -Method POST -Headers @{
            "Content-Type" = "application/json"
            "Authorization" = "Bearer $ApiKey"
        } -Body $payload -TimeoutSec 30
        $sw.Stop()
        
        $latency = $sw.ElapsedMilliseconds
        $latencies += $latency
        $success++
        
        Write-Host " OK ($latency ms)" -ForegroundColor Green
    } catch {
        Write-Host " FAILED" -ForegroundColor Red
    }
    
    if ($i -lt 5) { Start-Sleep -Seconds 1 }
}

# Results
Write-Host "`n=== Results ===" -ForegroundColor Cyan

if ($latencies.Count -gt 0) {
    $avg = ($latencies | Measure-Object -Average).Average
    $min = ($latencies | Measure-Object -Minimum).Minimum
    $max = ($latencies | Measure-Object -Maximum).Maximum
    $p95 = ($latencies | Sort-Object)[[math]::Floor($latencies.Count * 0.95)]
    
    Write-Host "Success Rate: $success/5 ($([math]::Round($success * 100 / 5))%)" -ForegroundColor Cyan
    Write-Host "Avg Latency: $([math]::Round($avg, 2)) ms" -ForegroundColor Cyan
    Write-Host "Min Latency: $min ms" -ForegroundColor Cyan
    Write-Host "Max Latency: $max ms" -ForegroundColor Cyan
    Write-Host "P95 Latency: $p95 ms" -ForegroundColor Cyan
    
    if ($p95 -lt 2500) {
        Write-Host "`n[OK] P95 latency within target (<2500ms)" -ForegroundColor Green
    } else {
        Write-Host "`n[WARNING] P95 latency above target (${p95}ms > 2500ms)" -ForegroundColor Yellow
    }
}

Write-Host "`n=== Next Steps ===" -ForegroundColor Cyan
Write-Host "1. Review P95 latency and queue lag above" -ForegroundColor White
Write-Host "2. If embedding queue lag >30s, proceed with Step 6 (Batch Embeddings)" -ForegroundColor White
Write-Host "3. If P95 >2.5s, analyze correlation IDs in logs" -ForegroundColor White
Write-Host "4. See full documentation: docs/baseline-metrics-guide.md" -ForegroundColor White

Write-Host "`n=== Test Complete ===" -ForegroundColor Cyan


