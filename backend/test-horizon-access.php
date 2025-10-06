<?php

/**
 * Script di debug avanzato per Horizon
 * Esegui: php test-horizon-access.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 DEBUG AVANZATO HORIZON\n";
echo "================================\n\n";

// 1. Verifica ambiente
echo "1️⃣ Ambiente: " . app()->environment() . "\n\n";

// 2. Verifica HorizonServiceProvider boot è stato chiamato
echo "2️⃣ Verifica Service Providers:\n";
$providers = app()->getLoadedProviders();
echo "   HorizonServiceProvider: " . (isset($providers[\App\Providers\HorizonServiceProvider::class]) ? '✅' : '❌') . "\n";
echo "   HorizonApplicationServiceProvider: " . (isset($providers[\Laravel\Horizon\HorizonApplicationServiceProvider::class]) ? '✅' : '❌') . "\n\n";

// 3. Verifica route Horizon
echo "3️⃣ Route Horizon registrate:\n";
$horizonRoutes = collect(app('router')->getRoutes())->filter(function ($route) {
    return str_starts_with($route->uri(), 'horizon');
});

if ($horizonRoutes->count() > 0) {
    echo "   ✅ {$horizonRoutes->count()} route trovate\n";
    echo "   Primi 10 route:\n";
    foreach ($horizonRoutes->take(10) as $route) {
        $middleware = implode(', ', $route->middleware());
        echo "      - {$route->methods()[0]} /{$route->uri()} [middleware: {$middleware}]\n";
    }
} else {
    echo "   ❌ NESSUNA route Horizon trovata!\n";
}
echo "\n";

// 4. Test specifico route /horizon
echo "4️⃣ Test route /horizon:\n";
$horizonMainRoute = collect(app('router')->getRoutes())->first(function ($route) {
    return $route->uri() === 'horizon' || $route->uri() === 'horizon/';
});

if ($horizonMainRoute) {
    echo "   ✅ Route principale trovata\n";
    echo "   URI: {$horizonMainRoute->uri()}\n";
    echo "   Methods: " . implode(', ', $horizonMainRoute->methods()) . "\n";
    echo "   Middleware: " . implode(', ', $horizonMainRoute->middleware()) . "\n";
    echo "   Action: " . $horizonMainRoute->getActionName() . "\n";
} else {
    echo "   ❌ Route principale NON trovata!\n";
}
echo "\n";

// 5. Verifica config Horizon
echo "5️⃣ Config Horizon:\n";
echo "   Path: " . config('horizon.path', 'N/A') . "\n";
echo "   Domain: " . config('horizon.domain', 'N/A') . "\n";
echo "   Middleware: " . json_encode(config('horizon.middleware')) . "\n\n";

// 6. Verifica .htaccess / web server config
echo "6️⃣ Verifica file pubblici:\n";
$publicPath = base_path('public');
$htaccess = $publicPath . '/.htaccess';
echo "   .htaccess esiste: " . (file_exists($htaccess) ? '✅' : '❌') . "\n";
if (file_exists($htaccess)) {
    $content = file_get_contents($htaccess);
    if (strpos($content, 'horizon') !== false) {
        echo "   ⚠️ .htaccess contiene regole per 'horizon'\n";
    }
}
echo "\n";

// 7. Test simulazione richiesta HTTP
echo "7️⃣ Test simulazione richiesta /horizon:\n";
try {
    $request = \Illuminate\Http\Request::create('/horizon', 'GET');
    $response = app()->handle($request);
    echo "   Status Code: {$response->getStatusCode()}\n";
    echo "   Headers:\n";
    foreach ($response->headers->all() as $key => $values) {
        if (in_array($key, ['content-type', 'location', 'www-authenticate'])) {
            echo "      {$key}: " . implode(', ', $values) . "\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ Errore: {$e->getMessage()}\n";
}
echo "\n";

// 8. Controlla se Horizon ha una gate custom
echo "8️⃣ Verifica Gates:\n";
try {
    $gates = \Illuminate\Support\Facades\Gate::abilities();
    echo "   Gate 'viewHorizon': " . (isset($gates['viewHorizon']) ? '✅ TROVATO' : '❌ NON trovato') . "\n";
} catch (\Exception $e) {
    echo "   ⚠️ Non riesco a verificare gates\n";
}
echo "\n";

echo "================================\n";
echo "✅ Debug completato\n";

