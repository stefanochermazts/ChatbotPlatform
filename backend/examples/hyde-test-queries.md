# 🧪 Query di Test per HyDE

## 🎯 Query Ideali per HyDE

Queste query beneficiano maggiormente della tecnica HyDE:

### **1. Orari e Contatti**
```bash
# Test orari
php artisan rag:test-hyde 1 "orari biblioteca comunale" --compare
php artisan rag:test-hyde 1 "quando è aperto l'ufficio anagrafe" --compare  
php artisan rag:test-hyde 1 "orario ricevimento sindaco" --compare

# Test contatti
php artisan rag:test-hyde 1 "telefono vigili urbani" --compare
php artisan rag:test-hyde 1 "email ufficio tributi" --compare
php artisan rag:test-hyde 1 "numero polizia municipale" --compare
```

### **2. Procedure e Servizi**
```bash
# Procedure complesse
php artisan rag:test-hyde 1 "come richiedere la carta d'identità" --compare
php artisan rag:test-hyde 1 "procedura per aprire un nuovo negozio" --compare
php artisan rag:test-hyde 1 "come iscriversi all'asilo nido" --compare

# Servizi specifici
php artisan rag:test-hyde 1 "tasse sui rifiuti per aziende" --compare
php artisan rag:test-hyde 1 "bonus famiglia numerosa requisiti" --compare
php artisan rag:test-hyde 1 "permesso ZTL centro storico" --compare
```

### **3. Informazioni Generali**
```bash
# Localizzazione
php artisan rag:test-hyde 1 "dove si trova il municipio" --compare
php artisan rag:test-hyde 1 "indirizzo biblioteca centrale" --compare
php artisan rag:test-hyde 1 "come raggiungere l'ufficio postale" --compare

# Servizi online
php artisan rag:test-hyde 1 "servizi disponibili online" --compare
php artisan rag:test-hyde 1 "accesso portale cittadino" --compare
php artisan rag:test-hyde 1 "prenotazione appuntamenti online" --compare
```

## 🔍 Esempi di Output

### **Esempio 1: Query Orari**

**Input**: `php artisan rag:test-hyde 1 "orari biblioteca" --compare`

**Output Atteso**:
```
🔬 Testando HyDE per tenant: Comune Demo (ID: 1)
💬 Query: orari biblioteca

🔄 Confronto: Standard vs HyDE

📊 Confronto Risultati:
+-------------------+-----------+-------+------------+
| Metrica           | Standard  | HyDE  | Differenza |
+-------------------+-----------+-------+------------+
| Citazioni trovate | 2         | 4     | 2          |
| Confidence        | 0.650     | 0.820 | 0.170      |
| Tempo (ms)        | 1240      | 1680  | +440       |
+-------------------+-----------+-------+------------+

📝 Documento Ipotetico Generato:
La biblioteca comunale è aperta dal lunedì al venerdì dalle 9:00 alle 18:00, 
il sabato dalle 9:00 alle 13:00. Durante l'estate (luglio-agosto) gli orari 
possono essere ridotti. È chiusa la domenica e nei giorni festivi.

📚 Prime 3 Citazioni:

🕹️  Posizione 1:
🔵 Standard: Doc 15 - Servizi Bibliotecari (Score: 0.745)
🟣 HyDE: Doc 15 - Servizi Bibliotecari (Score: 0.892)
✅ Stesso documento

🕹️  Posizione 2:
🔵 Standard: Doc 23 - Regolamento Comunale (Score: 0.621)
🟣 HyDE: Doc 12 - Orari Uffici Pubblici (Score: 0.835)
❌ Documenti diversi
```

### **Esempio 2: Query Procedura**

**Input**: `php artisan rag:test-hyde 1 "come richiedere carta identità" --detailed`

**Output Atteso**:
```
🔬 Testando HyDE per tenant: Comune Demo (ID: 1)
💬 Query: come richiedere carta identità

✨ Risultati HyDE:
Tempo totale: 1850ms
Citazioni trovate: 5
Confidence: 0.892

🔬 Debug HyDE:
Status: ✅ Success
Processing time: 650ms

📝 Documento Ipotetico:
Per richiedere la carta d'identità è necessario presentarsi presso l'ufficio 
anagrafe del comune con un documento di riconoscimento valido, due fototessere 
recenti, e la ricevuta del pagamento del bollettino. Il costo è di 22,21 euro. 
La carta viene rilasciata immediatamente se il richiedente è già registrato 
all'anagrafe comunale, altrimenti sono necessari alcuni giorni per la verifica.

⚙️  Pesi Embedding:
Original: 0.6
Hypothetical: 0.4

📚 Citazioni:
1. Doc 8 - Guida Servizi Anagrafici (Score: 0.925)
2. Doc 12 - Procedura Documenti Identità (Score: 0.887)
3. Doc 15 - Tariffe Servizi Comunali (Score: 0.834)
4. Doc 23 - Regolamento Anagrafe (Score: 0.789)
5. Doc 31 - Modulistica Online (Score: 0.745)
```

## 📊 Metriche di Successo

### **Criteri di Valutazione**

✅ **HyDE è efficace se**:
- Citazioni trovate: +1-3 documenti aggiuntivi
- Confidence: +0.1-0.3 punti
- Tempo: <2x del tempo standard
- Rilevanza: Documenti più specifici in posizioni alte

⚠️ **HyDE potrebbe non aiutare se**:
- Query già molto specifiche (es: "Doc 123 paragrafo 5")
- Ricerche per nomi propri (es: "Mario Rossi telefono")
- Query troppo generiche (es: "informazioni")

### **Benchmark Raccomandati**

**Test Settimanale**: Esegui questi test ogni settimana per monitorare performance

```bash
#!/bin/bash
# test-hyde-benchmark.sh

echo "=== HyDE Benchmark Test ==="
echo "Data: $(date)"
echo ""

# Test set 1: Orari
echo "1. Test Orari:"
php artisan rag:test-hyde 1 "orari biblioteca" --compare
php artisan rag:test-hyde 1 "quando aperto ufficio anagrafe" --compare

echo ""
echo "2. Test Procedure:"
php artisan rag:test-hyde 1 "come richiedere carta identità" --compare
php artisan rag:test-hyde 1 "procedura bonus famiglia" --compare

echo ""
echo "3. Test Contatti:"
php artisan rag:test-hyde 1 "telefono vigili urbani" --compare
php artisan rag:test-hyde 1 "email ufficio tributi" --compare

echo "=== Fine Benchmark ==="
```

## 🚫 Query di Controllo (HyDE meno efficace)

Testa anche queste query per verificare che HyDE non degradi le performance:

```bash
# Query già specifiche
php artisan rag:test-hyde 1 "art. 15 regolamento comunale" --compare
php artisan rag:test-hyde 1 "modulo richiesta CIE" --compare

# Query per nomi
php artisan rag:test-hyde 1 "Mario Rossi ufficio tecnico" --compare
php artisan rag:test-hyde 1 "dott.ssa Bianchi orario ricevimento" --compare

# Query molto generiche
php artisan rag:test-hyde 1 "informazioni" --compare
php artisan rag:test-hyde 1 "servizi" --compare
```

**Risultato Atteso**: Differenza minima o lieve miglioramento (non peggioramento significativo)

## 📝 Note di Testing

1. **Tenant ID**: Sostituisci `1` con l'ID del tenant che ha documenti caricati
2. **Warm-up**: Esegui 2-3 query prima del test per "scaldare" il sistema
3. **Cache**: Usa `php artisan cache:clear` tra test se necessario
4. **Monitoraggio**: Tieni d'occhio i log in `storage/logs/laravel.log`
5. **Baseline**: Stabilisci metriche baseline prima di abilitare HyDE in produzione

## 🛠️ Debug Avanzato

```bash
# Verifica configurazione HyDE
php artisan config:show rag.advanced.hyde

# Test con output JSON per analysis
php artisan rag:test-hyde 1 "test query" --detailed > hyde-test-$(date +%Y%m%d).json

# Monitora log real-time
tail -f storage/logs/laravel.log | findstr hyde

# Verifica embedding generation
php artisan tinker
> $hyde = app(\App\Services\RAG\HyDEExpander::class);
> $result = $hyde->expandQuery('test query', 1, true);
> dd($result);
```
