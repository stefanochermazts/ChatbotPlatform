echo "=== PULIZIA CACHE COMPLETA ==="
echo "1. Cache applicazione (database/file):"
php artisan cache:clear
echo
echo "2. Cache configurazione:"
php artisan config:clear
echo
echo "3. Cache routes:"
php artisan route:clear
echo
echo "4. Cache views (Blade templates):"
php artisan view:clear
echo
echo "5. Cache eventi/listener:"
php artisan event:clear
echo
echo "6. Autoload ottimizzato:"
composer dump-autoload
echo
echo "✅ PULIZIA COMPLETATA!"