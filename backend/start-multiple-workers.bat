@echo off
echo =========================================
echo   Avvio Worker Paralleli per Ingestion
echo =========================================
echo.
echo Avviando 3 worker simultanei per processare documenti in parallelo...
echo.
echo Per fermare TUTTI i worker: Ctrl+C in questa finestra
echo.

REM Avvia worker in background usando start /B
start /B "Worker-1" php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-1
start /B "Worker-2" php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-2  
start /B "Worker-3" php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-3

echo.
echo ✅ 3 worker avviati in background!
echo.
echo Monitor worker attivi:
echo.

:monitor
php artisan queue:monitor ingestion --display-all
timeout /t 10 >nul
goto monitor


