<?php

/**
 * Script di debug per testare l'autenticazione Horizon
 *
 * Esegui: php test-horizon-auth.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 DEBUG HORIZON AUTHENTICATION\n";
echo "================================\n\n";

// 1. Verifica ambiente
echo '1️⃣ Ambiente: '.app()->environment()."\n";
echo '   APP_ENV: '.env('APP_ENV')."\n\n";

// 2. Verifica configurazione Horizon
echo "2️⃣ Configurazione Horizon:\n";
echo '   Domain: '.config('horizon.domain')."\n";
echo '   Path: '.config('horizon.path')."\n";
echo '   Middleware: '.json_encode(config('horizon.middleware'))."\n\n";

// 3. Verifica utente nel database
echo "3️⃣ Verifica utente stefano@crowdm.com:\n";
$user = \App\Models\User::where('email', 'stefano@crowdm.com')->first();
if ($user) {
    echo "   ✅ Utente trovato:\n";
    echo "      - ID: {$user->id}\n";
    echo "      - Email: {$user->email}\n";
    echo "      - Name: {$user->name}\n";
    echo '      - Tenant ID: '.($user->tenant_id ?? 'N/A')."\n";
} else {
    echo "   ❌ Utente NON trovato nel database!\n";
}
echo "\n";

// 4. Verifica HorizonServiceProvider
echo "4️⃣ Verifica HorizonServiceProvider:\n";
$providers = app()->getLoadedProviders();
if (isset($providers[\App\Providers\HorizonServiceProvider::class])) {
    echo "   ✅ HorizonServiceProvider caricato\n";
} else {
    echo "   ❌ HorizonServiceProvider NON caricato\n";
}
echo "\n";

// 5. Verifica Laravel Horizon
echo "5️⃣ Verifica Laravel Horizon:\n";
if (class_exists(\Laravel\Horizon\Horizon::class)) {
    echo "   ✅ Laravel Horizon installato\n";
} else {
    echo "   ❌ Laravel Horizon NON installato\n";
}
echo "\n";

// 6. Verifica route Horizon
echo "6️⃣ Route Horizon:\n";
$routes = collect(app('router')->getRoutes())->filter(function ($route) {
    return str_starts_with($route->uri(), 'horizon');
});
if ($routes->count() > 0) {
    echo "   ✅ {$routes->count()} route Horizon trovate\n";
    echo "   Esempi:\n";
    foreach ($routes->take(5) as $route) {
        echo "      - {$route->methods()[0]} {$route->uri()}\n";
    }
} else {
    echo "   ❌ Nessuna route Horizon trovata!\n";
}
echo "\n";

echo "================================\n";
echo "✅ Debug completato\n";
