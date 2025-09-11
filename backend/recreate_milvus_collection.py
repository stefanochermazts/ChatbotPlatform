#!/usr/bin/env python3
"""
Script per ricreare la collezione Milvus con schema corretto
Backup dei dati esistenti e migrazione allo schema con enable_dynamic_field=True
"""
import os
import sys
import json
import warnings
from pymilvus import connections, Collection, FieldSchema, CollectionSchema, DataType, utility

# Sopprimi warning
warnings.filterwarnings("ignore", category=UserWarning)

def connect_milvus():
    """Connessione a Milvus"""
    host = os.getenv('MILVUS_HOST', '127.0.0.1')
    port = int(os.getenv('MILVUS_PORT', '19530'))
    
    connections.connect(
        alias="default",
        host=host,
        port=port
    )

def backup_collection_data(collection_name):
    """Backup di tutti i dati della collezione esistente"""
    try:
        connect_milvus()
        
        if not utility.has_collection(collection_name):
            return {"success": True, "message": "Collection does not exist, no backup needed"}
        
        collection = Collection(collection_name)
        collection.load()
        
        # Backup in batch per rispettare limite Milvus di 16384 records per query
        all_results = []
        batch_size = 10000  # Sicuro sotto il limite di 16384
        offset = 0
        
        while True:
            # Query batch di dati
            batch_results = collection.query(
                expr="id >= 0",
                output_fields=["id", "tenant_id", "document_id", "chunk_index", "vector"],
                limit=batch_size,
                offset=offset
            )
            
            if not batch_results:
                break
                
            all_results.extend(batch_results)
            print(f"Backed up {len(all_results)} records so far...", file=sys.stderr)
            offset += len(batch_results)
            
            # Se abbiamo ottenuto meno records del batch_size, abbiamo finito
            if len(batch_results) < batch_size:
                break
        
        results = all_results
        
        backup_data = {
            "collection_name": collection_name,
            "total_records": len(results),
            "schema_info": str(collection.schema),
            "data": results
        }
        
        # Salva backup su file
        backup_file = f"milvus_backup_{collection_name}.json"
        with open(backup_file, 'w') as f:
            # Converti numpy arrays in liste per JSON
            for record in backup_data["data"]:
                if "vector" in record and hasattr(record["vector"], "tolist"):
                    record["vector"] = record["vector"].tolist()
            json.dump(backup_data, f, indent=2)
        
        return {
            "success": True,
            "backup_file": backup_file,
            "records_backed_up": len(results)
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def create_new_collection_schema(collection_name, vector_dim=3072):
    """Crea nuova collezione con schema corretto"""
    try:
        connect_milvus()
        
        # Drop collezione esistente se esiste
        if utility.has_collection(collection_name):
            utility.drop_collection(collection_name)
            print(f"Dropped existing collection: {collection_name}")
        
        # Definisci campi per lo schema
        fields = [
            FieldSchema(name="id", dtype=DataType.INT64, is_primary=True, auto_id=False),
            FieldSchema(name="tenant_id", dtype=DataType.INT64),
            FieldSchema(name="document_id", dtype=DataType.INT64),
            FieldSchema(name="chunk_index", dtype=DataType.INT64),
            FieldSchema(name="vector", dtype=DataType.FLOAT_VECTOR, dim=vector_dim)
        ]
        
        # Crea schema con enable_dynamic_field=True
        schema = CollectionSchema(
            fields=fields,
            description="KB chunks vectors with dynamic fields enabled",
            enable_dynamic_field=True  # 🔑 QUESTO È LA CHIAVE!
        )
        
        # Crea nuova collezione
        collection = Collection(
            name=collection_name,
            schema=schema
        )
        
        # Crea indice per la ricerca vettoriale
        index_params = {
            "metric_type": "COSINE",
            "index_type": "HNSW",
            "params": {"M": 16, "efConstruction": 256}
        }
        collection.create_index(field_name="vector", index_params=index_params)
        
        return {
            "success": True,
            "message": f"Created new collection {collection_name} with dynamic fields enabled",
            "schema": str(schema)
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def restore_collection_data(collection_name, backup_file):
    """Ripristina i dati dalla backup"""
    try:
        connect_milvus()
        
        if not os.path.exists(backup_file):
            return {"success": False, "error": f"Backup file {backup_file} not found"}
        
        # Carica backup
        with open(backup_file, 'r') as f:
            backup_data = json.load(f)
        
        collection = Collection(collection_name)
        
        # Prepara dati per inserimento (formato lista)
        records = backup_data["data"]
        if not records:
            return {"success": True, "message": "No data to restore"}
        
        ids = []
        tenant_ids = []
        document_ids = []
        chunk_indices = []
        vectors = []
        
        for record in records:
            ids.append(record["id"])
            tenant_ids.append(record["tenant_id"])
            document_ids.append(record["document_id"])
            chunk_indices.append(record["chunk_index"])
            vectors.append(record["vector"])
        
        # Inserisci in batch (max 1000 per volta)
        batch_size = 1000
        total_inserted = 0
        
        for i in range(0, len(ids), batch_size):
            batch_data = [
                ids[i:i+batch_size],
                tenant_ids[i:i+batch_size],
                document_ids[i:i+batch_size],
                chunk_indices[i:i+batch_size],
                vectors[i:i+batch_size]
            ]
            
            collection.insert(batch_data)
            total_inserted += len(batch_data[0])
        
        collection.flush()
        
        return {
            "success": True,
            "restored_records": total_inserted,
            "original_records": len(records)
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def main():
    """Main function - gestisce backup, ricreazione e restore"""
    if len(sys.argv) != 3:
        print(json.dumps({
            "success": False,
            "error": "Usage: python recreate_milvus_collection.py <operation> <collection_name>"
        }))
        sys.exit(1)
    
    operation = sys.argv[1]
    collection_name = sys.argv[2]
    
    try:
        if operation == "backup":
            result = backup_collection_data(collection_name)
        elif operation == "recreate":
            result = create_new_collection_schema(collection_name)
        elif operation == "restore":
            backup_file = f"milvus_backup_{collection_name}.json"
            result = restore_collection_data(collection_name, backup_file)
        elif operation == "full_migration":
            # Migrazione completa: backup + recreate + restore
            print("Step 1: Backup existing data...")
            backup_result = backup_collection_data(collection_name)
            if not backup_result["success"]:
                print(json.dumps(backup_result))
                sys.exit(1)
            
            print("Step 2: Recreate collection with new schema...")
            recreate_result = create_new_collection_schema(collection_name)
            if not recreate_result["success"]:
                print(json.dumps(recreate_result))
                sys.exit(1)
            
            print("Step 3: Restore data...")
            restore_result = restore_collection_data(collection_name, backup_result.get("backup_file"))
            
            result = {
                "success": restore_result["success"],
                "backup": backup_result,
                "recreate": recreate_result,
                "restore": restore_result
            }
        else:
            result = {"success": False, "error": f"Unknown operation: {operation}"}
        
        print(json.dumps(result, indent=2))
        
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }))
        sys.exit(1)

if __name__ == "__main__":
    main()
