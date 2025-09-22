<?php

// Test del fix widget con l'output esatto dell'utente
$problematicOutput = 'Per ulteriori dettagli, puoi consultare la pagina ufficiale del Comune di San Cesareo [qui](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247';

echo "🧪 TEST WIDGET FIX - Caso CIE\n";
echo "===========================\n\n";

echo "📥 INPUT PROBLEMATICO:\n";
echo $problematicOutput . "\n\n";

// Simula la regex CRITICAL FIX del widget
$pattern = '/\[([^\]]+)\]\((https?:\/\/[^)\s\n]+)(?=\s|$|\n)/';

if (preg_match($pattern, $problematicOutput, $matches)) {
    echo "🔧 CRITICAL FIX MATCH:\n";
    echo "   Testo: " . $matches[1] . "\n";
    echo "   URL: " . $matches[2] . "\n";
    
    $htmlLink = '<a href="' . $matches[2] . '" target="_blank" rel="noopener noreferrer" class="chatbot-link">' . $matches[1] . '</a>';
    
    echo "   HTML: " . $htmlLink . "\n\n";
    
    // Verifica che l'URL finisca con 20247
    $urlComplete = str_ends_with($matches[2], '20247');
    echo "✅ URL completo: " . ($urlComplete ? 'SÌ' : 'NO') . "\n";
    echo "✅ Link funzionante: " . ($urlComplete ? 'SÌ' : 'NO') . "\n";
    
    if ($urlComplete) {
        echo "\n🎉 SUCCESS! Il fix funziona correttamente!";
    } else {
        echo "\n❌ FAIL! Il fix non funziona.";
    }
} else {
    echo "❌ FAIL! La regex non ha trovato match.\n";
}
echo "\n";
