# Risoluzione problemi Milvus su Windows

## Problema: WinError 10106 durante creazione partizioni

### Descrizione
Su Windows può verificarsi l'errore `OSError: [WinError 10106] Impossibile caricare o inizializzare il provider del servizio richiesto` quando si tenta di creare partizioni Milvus tramite lo script Python.

Questo errore è causato da incompatibilità tra:
- grpcio (utilizzato da pymilvus)
- asyncio su Windows
- Provider di servizi di rete Windows

### Stack trace tipico
```
File ".../pymilvus/__init__.py", line 14, in <module>
    from .client.abstract import AnnSearchRequest, RRFRanker, WeightedRanker
...
File ".../grpc/_cython/cygrpc.pyx", line 27, in init grpc._cython.cygrpc
...
OSError: [WinError 10106] Impossibile caricare o inizializzare il provider del servizio richiesto
```

## Soluzioni implementate

### 1. Disabilitazione automatica su Windows
Il sistema ora rileva automaticamente quando è in esecuzione su Windows e non tenta il fallback Python se rileva errori grpcio.

### 2. Configurazione per disabilitare partizioni
È possibile disabilitare completamente la creazione di partizioni aggiungendo al `.env`:

```env
MILVUS_PARTITIONS_ENABLED=false
```

### 3. Gestione errori migliorata
- Gli errori non bloccano più la creazione dei tenant
- Logging dettagliato per debug
- Fallback graceful

## Soluzioni alternative per sviluppo

### Opzione 1: Usare Milvus Standalone senza partizioni
```env
MILVUS_PARTITIONS_ENABLED=false
```

### Opzione 2: Aggiornare grpcio (potrebbe non funzionare)
```bash
pip install --upgrade grpcio
pip install --upgrade pymilvus
```

### Opzione 3: Usare WSL2 per Python
```bash
# In WSL2
pip install pymilvus
# Configurare Laravel per chiamare Python in WSL2
```

### Opzione 4: Creare partizioni manualmente
Se necessario, creare le partizioni manualmente:

```python
# create_manual_partition.py
import os
from pymilvus import connections, Collection

def connect():
    connections.connect(
        alias="default",
        host=os.getenv("MILVUS_HOST", "127.0.0.1"),
        port=os.getenv("MILVUS_PORT", "19530")
    )

def create_partition(tenant_id):
    collection_name = os.getenv("MILVUS_COLLECTION", "kb_chunks_v1")
    partition_name = f"tenant_{tenant_id}"
    
    collection = Collection(collection_name)
    if partition_name not in [p.name for p in collection.partitions]:
        collection.create_partition(partition_name)
        print(f"Partizione creata: {partition_name}")
    else:
        print(f"Partizione già esistente: {partition_name}")

if __name__ == "__main__":
    import sys
    if len(sys.argv) != 2:
        print("Uso: python create_manual_partition.py <tenant_id>")
        sys.exit(1)
    
    tenant_id = sys.argv[1]
    connect()
    create_partition(tenant_id)
```

## Monitoraggio

Controllare i log per verificare il comportamento:

```bash
# Log di partizioni disabilitate
tail -f storage/logs/laravel.log | grep "milvus.partition.disabled"

# Log di errori grpcio su Windows
tail -f storage/logs/laravel.log | grep "milvus.partition.skipped_on_windows"

# Log di fallimenti
tail -f storage/logs/laravel.log | grep "milvus.partition.*failed"
```

## Impatto funzionale

**Con partizioni abilitate:**
- Isolamento completo dei dati per tenant
- Performance migliori su grandi volumi
- Gestione granulare delle risorse

**Con partizioni disabilitate:**
- Tutti i dati nella partizione `_default`
- Filtraggio via `tenant_id` nelle query
- Funzionalità RAG completamente preservata
- Performance leggermente ridotte su molti tenant

**Raccomandazione per sviluppo Windows:**
Disabilitare le partizioni (`MILVUS_PARTITIONS_ENABLED=false`) per evitare problemi di compatibilità.
