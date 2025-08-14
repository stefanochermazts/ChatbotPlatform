# Creazione Automatica Partizioni Milvus

## Panoramica

Il sistema ora crea automaticamente le partizioni Milvus quando viene creato un nuovo tenant, garantendo l'isolamento dei dati vettoriali per ogni cliente.

## Come Funziona

### 1. Creazione Automatica
Quando crei un nuovo tenant tramite:
- **API**: `POST /api/tenants`
- **Admin Panel**: Form di creazione tenant

Il sistema automaticamente:
1. Crea il record tenant nel database
2. Lancia un job in coda `CreateMilvusPartitionJob`
3. Il job crea la partizione `tenant_{ID}` nella collection Milvus

### 2. Strategia Fault-Tolerant
Il job `CreateMilvusPartitionJob` usa una strategia a doppio livello:

1. **Primo tentativo**: PHP SDK Milvus
   - Usa il `MilvusClient` esteso con metodi `createPartition()` e `hasPartition()`
   - Supporta diverse versioni dell'SDK con fallback automatici

2. **Fallback**: Script Python esistente
   - Se il PHP SDK fallisce, chiama `create_milvus_partition.py`
   - Garantisce compatibilità con il setup attuale

### 3. Logging e Monitoraggio
Tutti gli eventi sono tracciati nei log Laravel:
- `milvus.partition.creating`: Inizio creazione
- `milvus.partition.created`: Successo
- `milvus.partition.already_exists`: Partizione esistente
- `milvus.partition.create_failed`: Errori

## Comandi Utili

### Per Tenant Esistenti
Se hai tenant creati prima di questo aggiornamento:

```bash
# Crea partizioni per tutti i tenant
php artisan milvus:create-partitions --all

# Crea partizione per un tenant specifico
php artisan milvus:create-partitions --tenant-id=123
```

### Monitoraggio Code
```bash
# Monitora l'esecuzione dei job
php artisan queue:work --queue=default

# Visualizza job falliti
php artisan queue:failed
```

## Struttura Partizioni

- **Collection**: `kb_chunks_v1` (configurabile in `config/rag.php`)
- **Naming**: `tenant_{ID}` (es: `tenant_1`, `tenant_2`)
- **Isolamento**: Ogni tenant ha la propria partizione separata

## Configurazione

### Variabili Ambiente Milvus
```env
MILVUS_HOST=127.0.0.1
MILVUS_PORT=19530
MILVUS_URI=          # Opzionale per Milvus Cloud
MILVUS_TOKEN=        # Opzionale per Milvus Cloud
MILVUS_COLLECTION=kb_chunks_v1
```

### Code Laravel
Il job usa la coda `default`. Assicurati che sia configurata:

```env
QUEUE_CONNECTION=database  # o redis/sqs
```

## Risoluzione Problemi

### Job Falliti
```bash
# Visualizza dettagli job falliti
php artisan queue:failed

# Riprova job falliti
php artisan queue:retry all
```

### Verifica Partizioni Esistenti
```bash
# Script Python diretto
python create_milvus_partition.py --collection kb_chunks_v1 --partition tenant_123
```

### Logs
Controlla i log in `storage/logs/laravel.log` per eventi con prefisso `milvus.partition.*`

## Migrazione

Per sistemi esistenti:

1. **Backup**: Assicurati di avere backup dei dati Milvus
2. **Collection**: Verifica che `kb_chunks_v1` esista già
3. **Tenant esistenti**: Esegui `php artisan milvus:create-partitions --all`
4. **Test**: Crea un nuovo tenant di test per verificare il funzionamento

## Note Tecniche

- **Retry**: 3 tentativi con backoff di 30 secondi
- **Timeout**: Nessun timeout specifico (usa quello del worker)
- **Idempotenza**: Il job è sicuro da rieseguire
- **Concorrenza**: Supporta creazione parallela di più partizioni
