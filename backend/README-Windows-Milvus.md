# Guida rapida: Risoluzione problema Milvus su Windows

## 🚨 Problema
Durante la creazione di un nuovo tenant, si verifica l'errore:
```
OSError: [WinError 10106] Impossibile caricare o inizializzare il provider del servizio richiesto
```

## ✅ Soluzione immediata
Aggiungi al file `.env`:

```env
# Disabilita partizioni Milvus per evitare problemi Windows
MILVUS_PARTITIONS_ENABLED=false
```

## 🔧 Test e verifica

### 1. Verifica stato connessione Milvus
```bash
php artisan milvus:health --detailed
```

### 2. Test creazione partizione per un tenant specifico
```bash
php artisan milvus:test-partition 1
```

### 3. Verifica log sistema
```bash
tail -f storage/logs/laravel.log | findstr milvus
```

## 📋 Configurazioni disponibili

Aggiungi al `.env` per controllare il comportamento:

```env
# Configurazione Milvus
MILVUS_HOST=127.0.0.1
MILVUS_PORT=19530
MILVUS_COLLECTION=kb_chunks_v1

# Partizioni (disabilita su Windows se hai problemi)
MILVUS_PARTITIONS_ENABLED=false

# Per Zilliz Cloud
MILVUS_URI=your-zilliz-uri
MILVUS_TOKEN=your-token
MILVUS_TLS=true
```

## 🔍 Verifica funzionamento

Dopo aver configurato `MILVUS_PARTITIONS_ENABLED=false`:

1. **Crea un nuovo tenant** - non dovrebbe più dare errori
2. **Il RAG continua a funzionare** - tutti i dati vanno nella partizione `_default`
3. **Performance leggermente ridotte** su molti tenant, ma funzionale

## ⚡ Risoluzione definitiva (avanzata)

Se vuoi abilitare le partizioni su Windows:

### Opzione 1: Usare Docker per Milvus
```bash
docker run -d --name milvus-standalone -p 19530:19530 milvusdb/milvus:latest
```

### Opzione 2: Usare WSL2 per lo script Python
Configurare Laravel per chiamare Python tramite WSL2 invece che Windows direttamente.

### Opzione 3: Aggiornare grpcio (potrebbe non funzionare)
```bash
pip install --upgrade grpcio==1.59.0 pymilvus
```

## 📊 Impatto sulle funzionalità

| Funzionalità | Con partizioni | Senza partizioni |
|--------------|----------------|------------------|
| RAG | ✅ Ottimale | ✅ Funzionale |
| Isolamento dati | ✅ Completo | ⚠️ Via filtro tenant_id |
| Performance | ✅ Ottime | ⚠️ Buone |
| Gestione tenant | ✅ Granulare | ✅ Semplificata |
| Compatibilità Windows | ❌ Problematica | ✅ Stabile |

**Raccomandazione**: Per sviluppo su Windows, usa `MILVUS_PARTITIONS_ENABLED=false`
