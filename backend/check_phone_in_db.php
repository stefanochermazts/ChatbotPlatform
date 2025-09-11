<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Cerca documenti con "polizia" nel titolo
$docs = \App\Models\Document::where('title', 'like', '%polizia%')
    ->orWhere('title', 'like', '%Polizia%')
    ->get();

if ($docs->isEmpty()) {
    echo "Nessun documento trovato con 'polizia' nel titolo\n";
    
    // Prova a cercare per contenuto
    $docs = \App\Models\Document::where('file_content', 'like', '%polizialocale%')->get();
    
    if ($docs->isEmpty()) {
        echo "Nessun documento trovato con 'polizialocale' nel contenuto\n";
        
        // Prova a cercare per 06.95898223
        $docs = \App\Models\Document::where('file_content', 'like', '%06.95898223%')->get();
        
        if ($docs->isEmpty()) {
            echo "Nessun documento trovato con '06.95898223'\n";
            
            // Lista tutti i documenti del tenant 5
            $docs = \App\Models\Document::where('tenant_id', 5)->take(5)->get(['id', 'title', 'phone_numbers']);
            echo "Primi 5 documenti del tenant 5:\n";
            foreach ($docs as $doc) {
                echo "- ID {$doc->id}: {$doc->title} | Phone numbers: " . json_encode($doc->phone_numbers) . "\n";
            }
        } else {
            echo "Trovati documenti con il numero di telefono!\n";
        }
    } else {
        echo "Trovati documenti con 'polizialocale'!\n";
    }
}

foreach ($docs as $doc) {
    echo "\n=== DOCUMENTO ID {$doc->id} ===\n";
    echo "Titolo: {$doc->title}\n";
    echo "Tenant ID: {$doc->tenant_id}\n";
    echo "Phone numbers estratti: " . json_encode($doc->phone_numbers) . "\n";
    echo "Email addresses estratte: " . json_encode($doc->email_addresses) . "\n";
    
    if ($doc->file_content) {
        // Cerca il numero nel contenuto
        if (strpos($doc->file_content, '06.95898223') !== false) {
            echo "✅ Numero di telefono 06.95898223 TROVATO nel contenuto!\n";
        } else {
            echo "❌ Numero di telefono 06.95898223 NON trovato nel contenuto\n";
        }
        
        // Cerca "tel:" nel contenuto
        if (strpos($doc->file_content, 'tel:06') !== false) {
            echo "✅ Pattern 'tel:06' TROVATO nel contenuto!\n";
        } else {
            echo "❌ Pattern 'tel:06' NON trovato nel contenuto\n";
        }
        
        // Mostra un estratto del contenuto
        echo "\nEstratto contenuto (primi 1000 caratteri):\n";
        echo substr($doc->file_content, 0, 1000) . "\n";
    }
    
    echo "\n" . str_repeat('-', 80) . "\n";
}
