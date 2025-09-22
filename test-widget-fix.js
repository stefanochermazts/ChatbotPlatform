#!/usr/bin/env node

// Test del fix widget con l'output esatto che l'utente ha segnalato
console.log('🧪 TEST WIDGET FIX - Caso CIE Utente');
console.log('=====================================\n');

function simulateWidgetMarkdownParser(text) {
    let html = text;
    
    console.log('📥 INPUT ORIGINALE:');
    console.log(text);
    console.log('');
    
    // Escape HTML di base per sicurezza
    html = html.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;');
    
    console.log('1️⃣ Dopo HTML escape:');
    console.log(html);
    console.log('');
    
    // 6a. CRITICAL FIX: Link markdown senza parentesi di chiusura
    // Questo è il fix principale per il problema dell'utente
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s\n]+)(?=\s|$|\n)/g, (match, text, url) => {
        console.warn('🔧 CRITICAL FIX applicato a:', match);
        console.log(`   → Testo: "${text}"`);
        console.log(`   → URL: "${url}"`);
        // Convertiamo direttamente in HTML link funzionante
        const result = `<a href="${url}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
        console.log(`   → HTML: ${result}`);
        return result;
    });
    
    console.log('2️⃣ Dopo CRITICAL FIX:');
    console.log(html);
    console.log('');
    
    // 6b. Links markdown [text](url) - gestisce URL completi e troncati  
    html = html.replace(/\[([^\]]+)\]\(([^)\s]+(?:\s[^)]*)?)\)/g, (match, text, url) => {
        console.log('🔗 Link completo trovato:', match);
        
        // Pulisce l'URL ma preserva integrità del link
        let cleanUrl = url.trim();
        
        // Rimuovi solo caratteri di punteggiatura finali MA solo se l'URL non finisce con numeri/lettere valide
        if (/[.,;:!?"'>]$/.test(cleanUrl) && !/\/\d+$/.test(cleanUrl)) {
          cleanUrl = cleanUrl.replace(/[.,;:!?"'>]+$/, '');
          console.log(`   🧹 URL pulito: "${url}" → "${cleanUrl}"`);
        }
        
        // Validazione URL più robusta
        let finalUrl;
        if (cleanUrl.match(/^https?:\/\//)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.match(/^mailto:/)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.match(/^tel:/)) {
          finalUrl = cleanUrl;
        } else if (cleanUrl.startsWith('www.')) {
          finalUrl = `https://${cleanUrl}`;
        } else if (cleanUrl.startsWith('/')) {
          finalUrl = cleanUrl; // Relative URL
        } else {
          // Se non è un URL valido, non creare il link
          console.warn('🔍 Invalid URL:', cleanUrl);
          return `${text} (${cleanUrl})`; // Fallback a testo normale
        }
        
        const result = `<a href="${finalUrl}" target="_blank" rel="noopener noreferrer" class="chatbot-link">${text}</a>`;
        console.log(`   → HTML: ${result}`);
        return result;
    });
    
    console.log('3️⃣ Dopo links completi:');
    console.log(html);
    console.log('');
    
    return html;
}

// Test con l'output esatto che l'utente ha segnalato
const problematicOutput = "Per ulteriori dettagli, puoi consultare la pagina ufficiale del Comune di San Cesareo [qui](http://www.comune.sancesareo.rm.it/c058119/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20247";

console.log('🎯 CASO SPECIFICO UTENTE:');
console.log('=========================');

const result = simulateWidgetMarkdownParser(problematicOutput);

console.log('📤 RISULTATO FINALE:');
console.log(result);
console.log('');

// Verifica che il link sia stato processato correttamente
const linkCount = (result.match(/<a href=/g) || []).length;
const urlMatch = result.match(/href="([^"]*)"/);

console.log('🔍 VERIFICA RISULTATO:');
console.log(`Link HTML generati: ${linkCount}`);
console.log(`Successo: ${linkCount > 0 ? '✅ SÌ' : '❌ NO'}`);

if (urlMatch) {
    const extractedUrl = urlMatch[1];
    console.log(`URL estratto: ${extractedUrl}`);
    
    // Verifica che l'URL sia completo e finisca con 20247
    const isComplete = extractedUrl.endsWith('20247');
    console.log(`URL completo: ${isComplete ? '✅ SÌ' : '❌ NO'}`);
    
    if (isComplete) {
        console.log('');
        console.log('🎉 SUCCESS! Il fix ha funzionato correttamente!');
        console.log('   Il link malformato è stato convertito in HTML funzionante.');
    } else {
        console.log('');
        console.log('❌ FAIL! Il fix non ha funzionato.');
    }
} else {
    console.log('❌ FAIL! Nessun link HTML generato.');
}

console.log('\n' + '='.repeat(60));
console.log('🏁 Test completato!');
