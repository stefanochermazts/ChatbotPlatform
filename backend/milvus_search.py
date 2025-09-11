#!/usr/bin/env python3
"""
Milvus Python Client
Interfaccia completa Python per Laravel per tutte le operazioni Milvus
"""
import sys
import json
import os
import warnings

# Sopprimi warning protobuf per output JSON pulito
warnings.filterwarnings("ignore", category=UserWarning)

from pymilvus import connections, Collection, utility
import numpy as np

def connect_milvus():
    """Connessione standard a Milvus usando variabili d'ambiente"""
    host = os.getenv('MILVUS_HOST', '127.0.0.1')
    port = int(os.getenv('MILVUS_PORT', '19530'))
    
    connections.connect(
        alias="default",
        host=host,
        port=port
    )

def search_vectors(collection_name, query_vector, tenant_id, limit=10):
    """Esegue una ricerca vettoriale su Milvus"""
    try:
        connect_milvus()
        
        # Carica la collection
        collection = Collection(collection_name)
        collection.load()
        
        # Parametri di ricerca - ef deve essere >= limit per HNSW
        ef_value = max(96, limit + 10)  # Ensure ef >= limit with some buffer
        search_params = {
            "metric_type": "COSINE",
            "params": {"ef": ef_value}
        }
        
        # Esegui ricerca con filtro tenant (ripristinato)
        results = collection.search(
            data=[query_vector],
            anns_field="vector",
            param=search_params,
            limit=limit,
            expr=f"tenant_id == {tenant_id}"
        )
        
        # Formatta risultati per Laravel
        hits = []
        for hit in results[0]:
            hits.append({
                "id": int(hit.id),
                "distance": float(hit.distance),
                "score": 1.0 - float(hit.distance)  # Converti distance in score
            })
        
        return {"success": True, "hits": hits}
        
    except Exception as e:
        return {
            "success": False, 
            "error": str(e),
            "error_type": type(e).__name__
        }

def upsert_vectors(collection_name, tenant_id, document_id, vectors, chunks=None):
    """Inserisce o aggiorna vettori in Milvus"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        
        # Prepara i dati per l'inserimento usando il formato a liste
        ids = []
        tenant_ids = []
        document_ids = []
        chunk_indices = []
        vector_data = []
        
        for i, vector in enumerate(vectors):
            primary_id = (document_id * 100000) + i
            ids.append(primary_id)
            tenant_ids.append(tenant_id)
            document_ids.append(document_id)
            chunk_indices.append(i)
            vector_data.append(vector)
        
        # Formato corretto per Milvus: liste separate per ogni campo
        data = [
            ids,           # id (primary key)
            tenant_ids,    # tenant_id
            document_ids,  # document_id  
            chunk_indices, # chunk_index
            vector_data    # vector
        ]
        
        # Inserisci i dati
        collection.insert(data)
        collection.flush()
        
        return {
            "success": True,
            "inserted_count": len(ids)
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def delete_by_primary_ids(collection_name, primary_ids):
    """Cancella documenti per primary ID"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        
        # Milvus accetta max 16384 ID per volta
        batch_size = 16384
        deleted_count = 0
        
        for i in range(0, len(primary_ids), batch_size):
            batch = primary_ids[i:i + batch_size]
            expr = f"id in {batch}"
            collection.delete(expr)
            deleted_count += len(batch)
        
        collection.flush()
        
        return {
            "success": True,
            "deleted_count": deleted_count
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def delete_by_tenant(collection_name, tenant_id):
    """Cancella tutti i documenti di un tenant"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        
        expr = f"tenant_id == {tenant_id}"
        collection.delete(expr)
        collection.flush()
        
        return {"success": True}
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def count_by_tenant(collection_name, tenant_id):
    """Conta i chunk per uno specifico tenant"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        collection.load()
        
        # Fai una query limitata per contare gli elementi
        expr = f"tenant_id == {tenant_id}"
        results = collection.query(
            expr=expr,
            output_fields=["id"],
            limit=10000  # Limite alto per contare tutto
        )
        
        return {
            "success": True,
            "count": len(results)
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def health_check(collection_name):
    """Controllo salute di Milvus"""
    try:
        connect_milvus()
        
        # Lista collection
        collections = utility.list_collections()
        
        collection_exists = collection_name in collections
        collection_info = {}
        
        if collection_exists:
            collection = Collection(collection_name)
            collection_info = {
                "num_entities": collection.num_entities,
                "schema": str(collection.schema)
            }
        
        return {
            "success": True,
            "connected": True,
            "collections": collections,
            "collection_exists": collection_exists,
            "collection_info": collection_info
        }
        
    except Exception as e:
        return {
            "success": False,
            "connected": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def create_partition(collection_name, partition_name):
    """Crea una partizione"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        
        # Controlla se esiste già
        partitions = [p.name for p in collection.partitions]
        if partition_name in partitions:
            return {
                "success": True,
                "already_exists": True
            }
        
        # Crea la partizione
        collection.create_partition(partition_name)
        
        return {
            "success": True,
            "created": True
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def has_partition(collection_name, partition_name):
    """Verifica se una partizione esiste"""
    try:
        connect_milvus()
        collection = Collection(collection_name)
        
        partitions = [p.name for p in collection.partitions]
        exists = partition_name in partitions
        
        return {
            "success": True,
            "exists": exists,
            "partitions": partitions
        }
        
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__
        }

def main():
    """Main entry point - gestisce tutte le operazioni Milvus"""
    if len(sys.argv) != 2:
        print(json.dumps({"success": False, "error": "Usage: python milvus_search.py '<json_params>'"}))
        sys.exit(1)
    
    try:
        # Parse parametri JSON da Laravel
        param_str = sys.argv[1]
        
        # Se il parametro inizia con @, leggi da file (fix per Windows)
        if param_str.startswith('@'):
            file_path = param_str[1:]  # Rimuovi @
            with open(file_path, 'r', encoding='utf-8') as f:
                params = json.load(f)
        else:
            # Legacy: leggi direttamente da parametro
            params = json.loads(param_str)
        
        operation = params.get('operation', 'search')
        collection_name = params.get('collection', 'kb_chunks_v1')
        
        if operation == 'search':
            query_vector = params.get('query_vector', [])
            tenant_id = int(params.get('tenant_id', 0))
            limit = int(params.get('limit', 10))
            
            if not query_vector:
                print(json.dumps({"success": False, "error": "query_vector is required"}))
                sys.exit(1)
            if tenant_id <= 0:
                print(json.dumps({"success": False, "error": "valid tenant_id is required"}))
                sys.exit(1)
            
            result = search_vectors(collection_name, query_vector, tenant_id, limit)
            
        elif operation == 'upsert':
            tenant_id = int(params.get('tenant_id', 0))
            document_id = int(params.get('document_id', 0))
            vectors = params.get('vectors', [])
            
            if tenant_id <= 0 or document_id <= 0:
                print(json.dumps({"success": False, "error": "valid tenant_id and document_id required"}))
                sys.exit(1)
            if not vectors:
                print(json.dumps({"success": False, "error": "vectors is required"}))
                sys.exit(1)
            
            result = upsert_vectors(collection_name, tenant_id, document_id, vectors)
            
        elif operation == 'delete_by_ids':
            primary_ids = params.get('primary_ids', [])
            
            if not primary_ids:
                print(json.dumps({"success": False, "error": "primary_ids is required"}))
                sys.exit(1)
            
            result = delete_by_primary_ids(collection_name, primary_ids)
            
        elif operation == 'delete_by_tenant':
            tenant_id = int(params.get('tenant_id', 0))
            
            if tenant_id <= 0:
                print(json.dumps({"success": False, "error": "valid tenant_id is required"}))
                sys.exit(1)
            
            result = delete_by_tenant(collection_name, tenant_id)
            
        elif operation == 'count_by_tenant':
            tenant_id = int(params.get('tenant_id', 0))
            
            if tenant_id <= 0:
                print(json.dumps({"success": False, "error": "valid tenant_id is required"}))
                sys.exit(1)
            
            result = count_by_tenant(collection_name, tenant_id)
            
        elif operation == 'health':
            result = health_check(collection_name)
            
        elif operation == 'create_partition':
            partition_name = params.get('partition_name', '')
            
            if not partition_name:
                print(json.dumps({"success": False, "error": "partition_name is required"}))
                sys.exit(1)
            
            result = create_partition(collection_name, partition_name)
            
        elif operation == 'has_partition':
            partition_name = params.get('partition_name', '')
            
            if not partition_name:
                print(json.dumps({"success": False, "error": "partition_name is required"}))
                sys.exit(1)
            
            result = has_partition(collection_name, partition_name)
            
        else:
            print(json.dumps({"success": False, "error": f"Unknown operation: {operation}"}))
            sys.exit(1)
        
        print(json.dumps(result))
        
    except json.JSONDecodeError as e:
        print(json.dumps({"success": False, "error": f"Invalid JSON: {e}"}))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({"success": False, "error": str(e), "error_type": type(e).__name__}))
        sys.exit(1)

if __name__ == "__main__":
    main()
