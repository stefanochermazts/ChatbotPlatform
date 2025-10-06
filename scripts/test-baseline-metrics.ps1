# Baseline Metrics Test Script (PowerShell)
# 
# Automated testing script for Step 1 - Performance Optimization Plan
# Tests latency tracking, queue lag, and generates baseline report
#
# Usage:
#   .\scripts\test-baseline-metrics.ps1
#   .\scripts\test-baseline-metrics.ps1 -Production
#   .\scripts\test-baseline-metrics.ps1 -Help
#
# Requirements:
#   - curl (Windows 10+), redis-cli, php, jq
#   - Valid API key in environment or .env

param(
    [switch]$Production,
    [switch]$Help,
    [string]$BaseUrl = "http://localhost:8000",
    [string]$ApiKey = "",
    [int]$TenantId = 1,
    [int]$TestRequests = 10
)

# Show help
if ($Help) {
    Write-Host @"
Usage: .\scripts\test-baseline-metrics.ps1 [OPTIONS]

Options:
  -Production        Test against production environment
  -BaseUrl <url>     API base URL (default: http://localhost:8000)
  -ApiKey <key>      API authentication key (required)
  -TenantId <id>     Tenant ID to test (default: 1)
  -TestRequests <n>  Number of test requests (default: 10)
  -Help              Show this help message

Examples:
  .\scripts\test-baseline-metrics.ps1
  .\scripts\test-baseline-metrics.ps1 -Production
  .\scripts\test-baseline-metrics.ps1 -ApiKey "xxx" -TenantId 5
"@
    exit 0
}

# Configuration
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$BackendDir = Join-Path $ProjectRoot "backend"
$ReportDir = Join-Path $ProjectRoot "baseline-reports"
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$ReportFile = Join-Path $ReportDir "baseline-report-$Timestamp.json"

# Create report directory
if (-not (Test-Path $ReportDir)) {
    New-Item -ItemType Directory -Path $ReportDir | Out-Null
}

# Colors
function Write-Success { Write-Host "[OK] $args" -ForegroundColor Green }
function Write-Error2 { Write-Host "[ERROR] $args" -ForegroundColor Red }
function Write-Warning2 { Write-Host "[WARNING] $args" -ForegroundColor Yellow }
function Write-Info { Write-Host "[INFO] $args" -ForegroundColor Cyan }

function Write-Header {
    param([string]$Title)
    Write-Host ""
    Write-Host "================================================================" -ForegroundColor Blue
    Write-Host "  $Title" -ForegroundColor Blue
    Write-Host "================================================================" -ForegroundColor Blue
    Write-Host ""
}

# Load environment
function Load-Environment {
    $envFile = Join-Path $BackendDir ".env"
    
    if (Test-Path $envFile) {
        Get-Content $envFile | Where-Object { $_ -match '^\s*[^#]' } | ForEach-Object {
            if ($_ -match '^\s*([^=]+)\s*=\s*(.*)$') {
                $name = $matches[1].Trim()
                $value = $matches[2].Trim().Trim('"')
                if (-not (Test-Path "env:$name")) {
                    Set-Item -Path "env:$name" -Value $value
                }
            }
        }
        Write-Success "Loaded .env configuration"
    }
    
    # Set environment-specific values
    if ($Production) {
        $script:Environment = "production"
        if ($env:PRODUCTION_BASE_URL) { $script:BaseUrl = $env:PRODUCTION_BASE_URL }
        if ($env:PRODUCTION_API_KEY) { $script:ApiKey = $env:PRODUCTION_API_KEY }
        Write-Info "Testing against PRODUCTION: $BaseUrl"
    } else {
        $script:Environment = "local"
        Write-Info "Testing against LOCAL: $BaseUrl"
    }
    
    # Get API key from env if not provided
    if (-not $ApiKey) {
        $script:ApiKey = $env:API_KEY
    }
    
    if (-not $ApiKey) {
        Write-Error2 "API_KEY not set. Provide with -ApiKey parameter or set in .env"
        exit 1
    }
}

# Check dependencies
function Test-Dependencies {
    $missing = @()
    
    foreach ($cmd in @('curl', 'redis-cli', 'php', 'jq')) {
        if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
            $missing += $cmd
        }
    }
    
    if ($missing.Count -gt 0) {
        Write-Error2 "Missing required commands: $($missing -join ', ')"
        Write-Info "Install jq from: https://jqlang.github.io/jq/download/"
        Write-Info "Install redis-cli from Redis installation"
        exit 1
    }
    
    Write-Success "All dependencies available"
}

# Test 1: Health Check
function Test-HealthCheck {
    Write-Header "1. Health Check"
    
    try {
        $response = Invoke-WebRequest -Uri "$BaseUrl/up" -UseBasicParsing -TimeoutSec 10
        if ($response.StatusCode -eq 200) {
            Write-Success "Health check passed (HTTP $($response.StatusCode))"
            return $true
        }
    } catch {
        Write-Error2 "Health check failed: $_"
        return $false
    }
}

# Test 2: Middleware
function Test-Middleware {
    Write-Header "2. Latency Middleware Test"
    
    $payload = @{
        model = "gpt-4o-mini"
        messages = @(
            @{ role = "user"; content = "Test request for baseline metrics" }
        )
        temperature = 0.7
        max_tokens = 100
    } | ConvertTo-Json -Compress
    
    Write-Info "Sending test request..."
    
    try {
        $headers = @{
            "Content-Type" = "application/json"
            "Authorization" = "Bearer $ApiKey"
        }
        
        $response = Invoke-WebRequest -Uri "$BaseUrl/api/v1/chat/completions" `
            -Method POST `
            -Headers $headers `
            -Body $payload `
            -UseBasicParsing `
            -TimeoutSec 30
        
        $latencyHeader = $response.Headers['X-Latency-Ms']
        $correlationHeader = $response.Headers['X-Correlation-Id']
        
        if ($latencyHeader -and $correlationHeader) {
            Write-Success "Middleware working correctly"
            Write-Info "  Latency: $($latencyHeader)ms"
            Write-Info "  Correlation ID: $correlationHeader"
            Write-Info "  HTTP Status: $($response.StatusCode)"
            return $true
        } else {
            Write-Error2 "Middleware headers not found"
            return $false
        }
    } catch {
        Write-Error2 "Request failed: $_"
        return $false
    }
}

# Test 3: Redis Storage
function Test-RedisStorage {
    Write-Header "3. Redis Storage Test"
    
    Write-Info "Checking Redis connection..."
    
    try {
        $ping = redis-cli ping 2>&1
        if ($ping -match "PONG") {
            Write-Success "Redis connection OK"
        } else {
            Write-Error2 "Redis not accessible"
            return $false
        }
    } catch {
        Write-Error2 "Redis connection failed: $_"
        return $false
    }
    
    Write-Info "Checking stored metrics for tenant $TenantId..."
    
    $count = redis-cli LLEN "latency:chat:$TenantId" 2>&1
    
    if ($count -match '^\d+$' -and [int]$count -gt 0) {
        Write-Success "Found $count requests in Redis"
        
        $latest = redis-cli LINDEX "latency:chat:$TenantId" 0 2>&1
        if ($latest) {
            Write-Info "Latest request:"
            Write-Host ($latest | jq . 2>&1)
        }
    } else {
        Write-Warning2 "No metrics found in Redis (this is OK for first run)"
    }
    
    return $true
}

# Test 4: Queue Lag
function Test-QueueLag {
    Write-Header "4. Queue Lag Test"
    
    Write-Info "Running queue lag monitoring..."
    
    Push-Location $BackendDir
    try {
        $output = php artisan metrics:queue-lag --json 2>&1 | Out-String
        
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Queue lag command executed"
            Write-Host ($output | jq . 2>&1)
            
            # Save for report
            $output | Out-File -FilePath "$env:TEMP\queue_lag_result.json" -Encoding UTF8
            
            return $true
        } else {
            Write-Error2 "Queue lag command failed"
            Write-Info "Output: $output"
            return $false
        }
    } finally {
        Pop-Location
    }
}

# Test 5: Load Test
function Run-LoadTest {
    Write-Header "5. Synthetic Load Test"
    
    Write-Info "Sending $TestRequests requests with 2s interval..."
    
    $latencies = @()
    $tokens = @()
    $costs = @()
    $errors = 0
    $success = 0
    
    $queries = @(
        "What are your opening hours?",
        "Do you offer wheelchair accessibility?",
        "Tell me about your services",
        "How can I contact customer support?",
        "What are the payment methods accepted?"
    )
    
    for ($i = 1; $i -le $TestRequests; $i++) {
        $query = $queries[($i - 1) % $queries.Count]
        $payload = @{
            model = "gpt-4o-mini"
            messages = @(@{ role = "user"; content = $query })
            temperature = 0.7
            max_tokens = 200
        } | ConvertTo-Json -Compress
        
        Write-Host "  Request $i/$TestRequests... " -NoNewline
        
        try {
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            
            $response = Invoke-RestMethod -Uri "$BaseUrl/api/v1/chat/completions" `
                -Method POST `
                -Headers @{
                    "Content-Type" = "application/json"
                    "Authorization" = "Bearer $ApiKey"
                } `
                -Body $payload `
                -TimeoutSec 30
            
            $sw.Stop()
            $latency = $sw.ElapsedMilliseconds
            
            Write-Host "OK ($($latency)ms)" -ForegroundColor Green
            
            $success++
            $latencies += $latency
            
            if ($response.usage) {
                $tokens += $response.usage.total_tokens
                
                $inputCost = ($response.usage.prompt_tokens * 0.15) / 1000000
                $outputCost = ($response.usage.completion_tokens * 0.60) / 1000000
                $costs += ($inputCost + $outputCost)
            }
            
        } catch {
            Write-Host "FAIL" -ForegroundColor Red
            $errors++
        }
        
        if ($i -lt $TestRequests) { Start-Sleep -Seconds 2 }
    }
    
    # Calculate statistics
    if ($latencies.Count -gt 0) {
        $avgLatency = ($latencies | Measure-Object -Average).Average
        $minLatency = ($latencies | Measure-Object -Minimum).Minimum
        $maxLatency = ($latencies | Measure-Object -Maximum).Maximum
        $p95Latency = ($latencies | Sort-Object)[[math]::Floor($latencies.Count * 0.95)]
        
        Write-Success "Load test completed"
        Write-Info "Results:"
        Write-Info "  Success Rate: $success/$TestRequests ($([math]::Round($success * 100 / $TestRequests))%)"
        Write-Info "  Avg Latency: $([math]::Round($avgLatency, 2))ms"
        Write-Info "  Min Latency: ${minLatency}ms"
        Write-Info "  Max Latency: ${maxLatency}ms"
        Write-Info "  P95 Latency: ${p95Latency}ms"
        
        if ($p95Latency -lt 2500) {
            Write-Success "P95 latency within target (<2500ms) ✅"
        } else {
            Write-Warning2 "P95 latency above target (${p95Latency}ms > 2500ms)"
        }
        
        # Save results
        @{
            total_requests = $TestRequests
            successful_requests = $success
            failed_requests = $errors
            success_rate_percent = [math]::Round($success * 100 / $TestRequests)
            avg_latency_ms = [math]::Round($avgLatency, 2)
            min_latency_ms = $minLatency
            max_latency_ms = $maxLatency
            p95_latency_ms = $p95Latency
        } | ConvertTo-Json | Out-File -FilePath "$env:TEMP\load_test_result.json" -Encoding UTF8
        
        if ($tokens.Count -gt 0) {
            $avgTokens = ($tokens | Measure-Object -Average).Average
            $totalCost = ($costs | Measure-Object -Sum).Sum
            $avgCost = $totalCost / $costs.Count
            
            Write-Info "  Avg Tokens: $([math]::Round($avgTokens))"
            Write-Info "  Avg Cost: `$$([math]::Round($avgCost, 8))"
            Write-Info "  Total Cost: `$$([math]::Round($totalCost, 6))"
        }
        
        return $true
    } else {
        Write-Error2 "No successful requests"
        return $false
    }
}

# Generate Report
function New-Report {
    Write-Header "6. Generating Report"
    
    $report = @{
        report_timestamp = $Timestamp
        environment = $Environment
        base_url = $BaseUrl
        tenant_id = $TenantId
        baseline_established = $true
    }
    
    # Load queue lag
    if (Test-Path "$env:TEMP\queue_lag_result.json") {
        $report.queue_lag = Get-Content "$env:TEMP\queue_lag_result.json" -Raw | ConvertFrom-Json
    }
    
    # Load load test
    if (Test-Path "$env:TEMP\load_test_result.json") {
        $report.load_test = Get-Content "$env:TEMP\load_test_result.json" -Raw | ConvertFrom-Json
    }
    
    # Add next steps
    $report.next_steps = @(
        "Review P95 latency and identify bottlenecks",
        "If embedding queue lag >30s, proceed with Step 6 (Batch Embeddings)",
        "If scraping queue lag >30s, proceed with Step 3 (Optimize Scraper)",
        "If P95 >2.5s, analyze correlation IDs in logs",
        "Schedule metrics:queue-lag --store every 5 minutes"
    )
    
    $report | ConvertTo-Json -Depth 10 | Out-File -FilePath $ReportFile -Encoding UTF8
    
    Write-Success "Report saved to: $ReportFile"
    Write-Info "`n📊 Report Summary:"
    Get-Content $ReportFile -Raw | jq . 2>&1 | Select-Object -First 40
    
    # Cleanup
    Remove-Item "$env:TEMP\queue_lag_result.json" -ErrorAction SilentlyContinue
    Remove-Item "$env:TEMP\load_test_result.json" -ErrorAction SilentlyContinue
}

# Main execution
Write-Host @"

================================================================
                                                               
         Baseline Metrics Test - Step 1                       
         Performance Optimization Plan                        
                                                               
================================================================

"@ -ForegroundColor Blue

Test-Dependencies
Load-Environment

$failed = 0

if (-not (Test-HealthCheck)) { $failed++ }
if (-not (Test-Middleware)) { $failed++ }
Test-RedisStorage | Out-Null
Test-QueueLag | Out-Null
if (-not (Run-LoadTest)) { $failed++ }

New-Report

Write-Header "Test Summary"

if ($failed -eq 0) {
    Write-Success "All critical tests passed! ✅"
    Write-Info "Baseline metrics established successfully"
    exit 0
} else {
    Write-Warning2 "$failed critical test(s) failed"
    Write-Info "Review errors above and check configuration"
    exit 1
}

