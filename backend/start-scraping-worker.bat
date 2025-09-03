@echo off
echo Avvio worker per coda scraping...
echo.
echo IMPORTANTE: Questo script deve rimanere aperto per processare i job di scraping
echo Chiudere questa finestra fermera' il worker e lo scraping tornera' sincrono!
echo.
echo Usa Ctrl+C per fermare il worker
echo.
php artisan queue:work --queue=scraping --tries=3 --timeout=1800 --memory=512 --sleep=3 --rest=0
