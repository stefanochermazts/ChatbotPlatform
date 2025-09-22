#!/usr/bin/env node

// Test simulazione parsing widget per caso specifico utente
console.log('🧪 Test Parsing Widget - Caso CIE');
console.log('===============================\n');

function simulateWidgetParsing(text) {
    let html = text;
    
    console.log('📥 INPUT:', text);
    console.log('');
    
    // Step 1: Escape HTML 
    html = html.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;');
    console.log('1️⃣ Dopo HTML escape:', html);
    
    // Step 2: Fix link malformati (CRITICAL FIX)
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s\n]+)(?=\s|$|\n)/g, (match, text, url) => {
        console.log(`🔧 CRITICAL FIX applicato a: "${match}"`);
        console.log(`   → Testo: "${text}"`);
        console.log(`   → URL: "${url}"`);
        const result = `<a href="${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
        console.log(`   → Risultato: "${result}"`);
        return result;
    });
    console.log('2️⃣ Dopo CRITICAL FIX:', html);
    
    // Step 3: Link completi (questa regex non dovrebbe più matchare i fix precedenti)
    html = html.replace(/\[([^\]]+)\]\(([^)\s]+(?:\s[^)]*)?)\)/g, (match, text, url) => {
        console.log(`🔗 Link completo trovato: "${match}"`);
        let cleanUrl = url.trim();
        if (/[.,;:!?"'>]$/.test(cleanUrl) && !/\/\d+$/.test(cleanUrl)) {
          cleanUrl = cleanUrl.replace(/[.,;:!?"'>]+$/, '');
          console.log(`   🧹 URL pulito: "${url}" → "${cleanUrl}"`);
        }
        const result = `<a href="${cleanUrl}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
        console.log(`   → Risultato: "${result}"`);
        return result;
    });
    console.log('3️⃣ Dopo link completi:', html);
    
    console.log('');
    console.log('📤 OUTPUT FINALE:', html);
    
    // Verifica risultato
    const linkCount = (html.match(/<a href=/g) || []).length;
    const hasValidLinks = linkCount > 0;
    
    console.log('');
    console.log('🔍 ANALISI RISULTATO:');
    console.log(`   Link HTML generati: ${linkCount}`);
    console.log(`   Successo: ${hasValidLinks ? '✅ SI' : '❌ NO'}`);
    
    if (hasValidLinks) {
        const urlMatches = html.match(/href="([^"]*)"/g);
        if (urlMatches) {
            console.log('   URL estratti:');
            urlMatches.forEach((match, i) => {
                const url = match.replace('href="', '').replace('"', '');
                console.log(`     ${i + 1}. ${url}`);
            });
        }
    }
    
    return html;
}

// Test cases specifici
const testCases = [
    {
        name: "Caso ESATTO dell'utente",
        input: "Per ulteriori dettagli, puoi consultare la pagina ufficiale del Comune di San Cesareo [qui](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247"
    },
    {
        name: "Link corretto (controllo)",
        input: "Per ulteriori dettagli, puoi consultare la pagina ufficiale del Comune di San Cesareo [qui](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247)"
    },
    {
        name: "Fine frase con spazio",
        input: "Visita [questo link](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247 per maggiori informazioni"
    },
    {
        name: "Fine stringa",
        input: "Documentazione completa: [CIE](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247"
    }
];

// Esegui tutti i test
testCases.forEach((testCase, index) => {
    console.log(`\n${'='.repeat(80)}`);
    console.log(`TEST ${index + 1}: ${testCase.name}`);
    console.log(`${'='.repeat(80)}`);
    
    const result = simulateWidgetParsing(testCase.input);
    
    console.log(`\n${'─'.repeat(40)}`);
});

console.log('\n🏁 Test completati!');
