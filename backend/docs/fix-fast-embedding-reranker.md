# Fix FastEmbeddingReranker - Miglioramento Scoring

## 🐛 Problema Identificato

Il `FastEmbeddingReranker` stava dando risultati sbagliati per query brevi come "sindaco" perché:

1. **Penalizzava eccessivamente chunk corti** (< 300 caratteri)
   - I chunk "esplosi" dalle tabelle (es. "Sabelli Alessandra - Sindaco") hanno ~200-300 caratteri
   - Venivano penalizzati con score basso

2. **Favoriva testi lunghi con alta term frequency**
   - Il "Regolamento del Consiglio Comunale" (2066 caratteri) menziona "sindaco" molte volte
   - Otteneva score altissimo (0.74) anche se non rilevante per la query

3. **Non dava bonus per match esatti**
   - Un chunk con "Sindaco: Sabelli Alessandra" non riceveva bonus per contenere esattamente la parola cercata

## ✅ Modifiche Applicate

### 1. Ridotto Penalità per Chunk Corti

**Prima:**
```php
if ($length >= 300 && $length <= 800) {
    return 1.0;
} elseif ($length < 300) {
    return $length / 300; // Penalizza testi troppo corti
}
```

**Dopo:**
```php
if ($length >= 100 && $length <= 1000) {
    return 1.0;
} elseif ($length < 100) {
    return max(0.7, $length / 100); // Penalizza solo chunk molto corti (< 100 caratteri)
}
```

**Impatto:**
- Chunk di 200-300 caratteri ora ottengono score 1.0 invece di 0.66-1.0
- Chunk da tabelle esplose non più penalizzati

### 2. Aggiunto Exact Match Bonus (25% del peso totale)

**Nuovo metodo `calculateExactMatchScore`:**
- **Score 1.0**: Query completa presente nel testo (es. "sindaco" in "Sindaco: Sabelli")
- **Score 0.9**: Query come parola standalone (es. "sindaco" non parte di "sindacato")
- **Score 0.8**: Parole della query vicine tra loro (< 50 caratteri)
- **Score 0.0**: Nessun match esatto

**Impatto:**
- Chunk con match esatti della query ottengono +25% di score
- "Sabelli Alessandra - Sindaco" ora batte "il Sindaco convoca il Consiglio"

### 3. Ribilanciati Pesi degli Score

**Prima:**
```php
$scores[$i] = (
    $keywordScore * 0.4 +    // Keyword overlap
    $tfScore * 0.3 +         // Term frequency
    $lengthScore * 0.2 +     // Length factor
    $positionScore * 0.1     // Position bias
);
```

**Dopo:**
```php
$scores[$i] = (
    $keywordScore * 0.3 +      // Keyword overlap (ridotto da 0.4)
    $tfScore * 0.25 +          // Term frequency (ridotto da 0.3)
    $lengthScore * 0.15 +      // Length factor (ridotto da 0.2)
    $exactMatchScore * 0.25 +  // Exact phrase match (nuovo!)
    $positionScore * 0.05      // Position bias (ridotto da 0.1)
);
```

**Impatto:**
- Ridotto peso di term frequency (che favoriva testi lunghi)
- Ridotto peso di length score (che penalizzava chunk corti)
- Aggiunto peso significativo per exact match

### 4. Migliorato Keyword Overlap

**Aggiunto bonus per match completi:**
```php
$baseScore = count($intersection) / count($queryWords);
if ($baseScore >= 0.99) {
    return 1.0; // Tutte le parole presenti → score massimo
}
```

## 📊 Risultati Attesi

### Prima del Fix

Query: "sindaco"

**Top 3 risultati:**
1. Regolamento del Consiglio Comunale (chunk 8) - Score: 0.74 ❌
2. Regolamento del Consiglio Comunale (chunk 10) - Score: 0.74 ❌
3. Regolamento del Consiglio Comunale (chunk 9) - Score: 0.74 ❌

### Dopo il Fix

Query: "sindaco"

**Top 3 risultati attesi:**
1. "Sabelli Alessandra - Sindaco" (chunk 6) - Score: ~0.85 ✅
2. Altri chunk con "Sindaco" rilevanti
3. Regolamento (se rilevante)

## 🧪 Test

Per testare le modifiche:

1. Riabilita il reranker per tenant 5:
   ```bash
   php enable-reranker-tenant5.php
   ```

2. Prova query nel widget:
   - "chi è il sindaco" → dovrebbe trovare "Sabelli Alessandra"
   - "sindaco" → dovrebbe trovare "Sabelli Alessandra"
   - "consiglieri" → dovrebbe trovare la lista dei consiglieri

3. Verifica nei log che il reranking dia score corretti:
   ```bash
   tail -f storage/logs/laravel.log | grep -A 5 "RERANK"
   ```

## 🔄 Rollback

Se le modifiche causano problemi, puoi:

1. Disabilitare il reranker per tenant specifici:
   ```sql
   UPDATE tenants 
   SET rag_settings = jsonb_set(rag_settings, '{reranker,driver}', '"none"')
   WHERE id = 5;
   ```

2. Oppure usare il reranker LLM (più preciso ma più lento):
   ```sql
   UPDATE tenants 
   SET rag_settings = jsonb_set(rag_settings, '{reranker,driver}', '"llm"')
   WHERE id = 5;
   ```

## 📝 Note

- Il `FastEmbeddingReranker` **non usa embeddings** (nonostante il nome)
- Usa solo scoring lessicale/sintattico per performance
- Per reranking semantico preciso, usa `LLMReranker` o `EmbeddingReranker` (più lento)
- Queste modifiche migliorano il reranking lessicale senza impattare le performance

## 🎯 Metriche da Monitorare

Dopo il deploy, monitora:

1. **Groundedness**: dovrebbe rimanere >= 0.8
2. **Hallucination rate**: dovrebbe rimanere < 2%
3. **User satisfaction**: feedback positivi su risposte corrette
4. **Latency P95**: dovrebbe rimanere < 2.5s (reranker è veloce)

## 🔗 File Modificati

- `backend/app/Services/RAG/FastEmbeddingReranker.php`
  - `calculateLengthScore()`: ridotta penalità per chunk corti
  - `calculateKeywordOverlap()`: aggiunto bonus per match completi
  - `calculateExactMatchScore()`: nuovo metodo per exact match bonus
  - Pesi score ribilanciati

## 📅 Data

- **Data fix**: 2025-10-07
- **Versione**: 1.0
- **Autore**: AI Assistant (Cursor)
- **Tenant testato**: San Cesareo (ID: 5)
