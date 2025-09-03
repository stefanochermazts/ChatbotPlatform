<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TEST SCRAPER CORRETTO ===" . PHP_EOL;

$url = 'https://www.comune.sancesareo.rm.it/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20110';

try {
    // Download HTML direttamente
    $response = Illuminate\Support\Facades\Http::timeout(30)->get($url);
    
    if ($response->successful()) {
        $html = $response->body();
        echo "HTML scaricato: " . strlen($html) . " caratteri" . PHP_EOL;
        
        // Crea instanza scraper
        $scraperService = new App\Services\Scraper\WebScraperService();
        
        // Usa reflection per accedere al metodo privato
        $reflection = new ReflectionClass($scraperService);
        $extractMethod = $reflection->getMethod('extractContent');
        $extractMethod->setAccessible(true);
        
        // Estrai contenuto
        $extracted = $extractMethod->invoke($scraperService, $html, $url);
        
        if ($extracted && isset($extracted['content'])) {
            $content = $extracted['content'];
            echo "Contenuto estratto: " . strlen($content) . " caratteri" . PHP_EOL;
            
            // Cerca orari specifici
            $has9 = strpos($content, '9:00') !== false;
            $has17 = strpos($content, '17:00') !== false;
            $hasPolizia = strpos($content, 'COMANDO POLIZIA LOCALE') !== false;
            
            echo "Contiene 9:00: " . ($has9 ? "SÌ" : "NO") . PHP_EOL;
            echo "Contiene 17:00: " . ($has17 ? "SÌ" : "NO") . PHP_EOL;
            echo "Contiene COMANDO POLIZIA LOCALE: " . ($hasPolizia ? "SÌ" : "NO") . PHP_EOL;
            
            if ($hasPolizia) {
                // Estrai sezione specifica
                if (preg_match('/COMANDO POLIZIA LOCALE.{0,600}/s', $content, $match)) {
                    echo PHP_EOL . "SEZIONE POLIZIA ESTRATTA:" . PHP_EOL;
                    echo "=========================" . PHP_EOL;
                    echo $match[0] . PHP_EOL;
                    echo "=========================" . PHP_EOL;
                }
            }
            
            if ($has9 && $has17) {
                echo PHP_EOL . "🎉 SUCCESSO! Orari estratti correttamente!" . PHP_EOL;
            } else {
                echo PHP_EOL . "❌ Orari ancora mancanti nel contenuto estratto." . PHP_EOL;
            }
            
        } else {
            echo "Errore nell'estrazione del contenuto." . PHP_EOL;
        }
        
    } else {
        echo "Errore HTTP: " . $response->status() . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "Errore: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace: " . $e->getTraceAsString() . PHP_EOL;
}
