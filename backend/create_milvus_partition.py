# create_milvus_partition.py
import os
import argparse
from pymilvus import connections, utility, Collection

def connect():
    uri = os.getenv("MILVUS_URI", "").strip()
    token = os.getenv("MILVUS_TOKEN", "").strip()
    host = os.getenv("MILVUS_HOST", "127.0.0.1")
    port = os.getenv("MILVUS_PORT", "19530")
    secure = os.getenv("MILVUS_TLS", "false").lower() == "true"
    if uri:
        connections.connect(alias="default", uri=uri, token=token, secure=secure)
    else:
        connections.connect(alias="default", host=host, port=port, secure=secure)

def has_partition(collection_name: str, partition_name: str) -> bool:
    # pymilvus 2.4+: utility.has_partition
    try:
        return utility.has_partition(collection_name, partition_name)
    except Exception:
        # fallback: scansione partizioni
        c = Collection(collection_name)
        return any(p.name == partition_name for p in c.partitions)

def ensure_partition(collection_name: str, partition_name: str):
    if not utility.has_collection(collection_name):
        raise SystemExit(f"Collection non trovata: {collection_name}")
    coll = Collection(collection_name)
    if has_partition(collection_name, partition_name):
        print(f"Partizione già esistente: {partition_name}")
        return
    coll.create_partition(partition_name)
    print(f"Partizione creata: {partition_name}")

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--collection", default=os.getenv("MILVUS_COLLECTION", "kb_chunks_v1"))
    parser.add_argument("--partition", default="tenant_2")
    args = parser.parse_args()

    connect()
    ensure_partition(args.collection, args.partition)
