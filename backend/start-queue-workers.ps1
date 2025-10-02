# 🚀 ChatbotPlatform - Queue Workers Startup Script
# Avvia worker separati per scraping (lungo) e ingestion (veloce)

Write-Host "🚀 Avvio Queue Workers..." -ForegroundColor Green
Write-Host ""

# Worker 1: Scraping (lungo, bassa priorità)
Write-Host "📦 Worker 1: Scraping Queue" -ForegroundColor Cyan
Write-Host "   - Queue: scraping" -ForegroundColor Gray
Write-Host "   - Timeout: 7200s (2 ore)" -ForegroundColor Gray
Write-Host "   - Memory: 1024MB" -ForegroundColor Gray
Write-Host ""

$scrapingJob = Start-Job -ScriptBlock {
    Set-Location $using:PWD
    php artisan queue:work --queue=scraping --timeout=7200 --sleep=3 --tries=3 --memory=1024
}

Start-Sleep -Seconds 2

# Worker 2: Ingestion, Indexing, Default (veloce, alta priorità)
Write-Host "⚡ Worker 2: Ingestion & Processing Queue" -ForegroundColor Cyan
Write-Host "   - Queue: ingestion,indexing,default,email,evaluation" -ForegroundColor Gray
Write-Host "   - Timeout: 300s (5 minuti)" -ForegroundColor Gray
Write-Host "   - Memory: 1024MB" -ForegroundColor Gray
Write-Host ""

$processingJob = Start-Job -ScriptBlock {
    Set-Location $using:PWD
    php artisan queue:work --queue=ingestion,indexing,default,email,evaluation --timeout=300 --sleep=3 --tries=3 --memory=1024
}

Start-Sleep -Seconds 2

Write-Host "✅ Workers avviati!" -ForegroundColor Green
Write-Host ""
Write-Host "📊 Job IDs:" -ForegroundColor Yellow
Write-Host "   Worker Scraping: $($scrapingJob.Id)" -ForegroundColor Gray
Write-Host "   Worker Processing: $($processingJob.Id)" -ForegroundColor Gray
Write-Host ""
Write-Host "💡 Comandi utili:" -ForegroundColor Yellow
Write-Host "   - Vedere output workers: Receive-Job -Id <JOB_ID> -Keep" -ForegroundColor Gray
Write-Host "   - Fermare workers: Stop-Job -Id <JOB_ID>" -ForegroundColor Gray
Write-Host "   - Vedere stato: Get-Job" -ForegroundColor Gray
Write-Host ""
Write-Host "⚠️  Premi CTRL+C per fermare entrambi i workers" -ForegroundColor Yellow
Write-Host ""

# Monitora i job
try {
    while ($true) {
        Start-Sleep -Seconds 5
        
        # Check se i job sono ancora in esecuzione
        $scrapingState = (Get-Job -Id $scrapingJob.Id).State
        $processingState = (Get-Job -Id $processingJob.Id).State
        
        if ($scrapingState -ne "Running" -or $processingState -ne "Running") {
            Write-Host ""
            Write-Host "⚠️  Uno dei worker si è fermato!" -ForegroundColor Red
            Write-Host "   Worker Scraping: $scrapingState" -ForegroundColor Gray
            Write-Host "   Worker Processing: $processingState" -ForegroundColor Gray
            break
        }
    }
}
catch {
    Write-Host ""
    Write-Host "🛑 Interruzione ricevuta, fermando i workers..." -ForegroundColor Yellow
}
finally {
    Stop-Job -Id $scrapingJob.Id -ErrorAction SilentlyContinue
    Stop-Job -Id $processingJob.Id -ErrorAction SilentlyContinue
    Remove-Job -Id $scrapingJob.Id -ErrorAction SilentlyContinue
    Remove-Job -Id $processingJob.Id -ErrorAction SilentlyContinue
    Write-Host "✅ Workers fermati." -ForegroundColor Green
}
