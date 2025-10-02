@echo off
REM ChatbotPlatform - Simple Queue Workers Launcher
REM Avvia due worker separati: Scraping (lento) e Ingestion (veloce)

echo.
echo ====================================================
echo   ChatbotPlatform - Queue Workers
echo ====================================================
echo.

cd /d "%~dp0"

echo [1/2] Avvio Worker SCRAPING (background)...
echo        - Queue: scraping
echo        - Timeout: 7200s (2 ore)
echo.
start "Queue Worker - Scraping" /MIN cmd /c "php artisan queue:work --queue=scraping --timeout=7200 --sleep=3 --tries=3 --memory=1024"

timeout /t 2 /nobreak >nul

echo [2/2] Avvio Worker INGESTION (foreground)...
echo        - Queue: ingestion,indexing,default,email,evaluation  
echo        - Timeout: 300s (5 minuti)
echo.
echo ^> Il worker INGESTION rimane in primo piano
echo ^> Il worker SCRAPING e' minimizzato in background
echo.
echo PREMI CTRL+C per fermare entrambi
echo.

php artisan queue:work --queue=ingestion,indexing,default,email,evaluation --timeout=300 --sleep=3 --tries=3 --memory=1024
