# create_milvus_collection.py
import os
import argparse
from pymilvus import (
    connections, utility,
    FieldSchema, CollectionSchema, DataType, Collection
)

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

def ensure_collection(name: str, dim: int, metric: str = "COSINE") -> Collection:
    if utility.has_collection(name):
        coll = Collection(name)
    else:
        fields = [
            FieldSchema(name="id", dtype=DataType.INT64, is_primary=True, auto_id=False),
            FieldSchema(name="tenant_id", dtype=DataType.INT64),
            FieldSchema(name="document_id", dtype=DataType.INT64),
            FieldSchema(name="chunk_index", dtype=DataType.INT64),
            FieldSchema(name="vector", dtype=DataType.FLOAT_VECTOR, dim=dim),
        ]
        schema = CollectionSchema(fields=fields, description="KB chunks vectors")
        coll = Collection(name=name, schema=schema, shards_num=2)

    index_params = {
        "index_type": "HNSW",
        "metric_type": metric,              # COSINE | L2 | IP
        "params": {"M": 16, "efConstruction": 200},
    }
    try:
        coll.create_index(field_name="vector", index_params=index_params)
    except Exception:
        pass

    try:
        coll.load()
    except Exception:
        pass

    return coll

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--name", default=os.getenv("MILVUS_COLLECTION", "kb_chunks_v1"))
    parser.add_argument("--dim", type=int, default=int(os.getenv("OPENAI_EMBEDDING_DIM", "3072")))
    parser.add_argument("--metric", default=os.getenv("RAG_VECTOR_METRIC", "COSINE").upper(),
                        choices=["COSINE", "L2", "IP"])
    args = parser.parse_args()

    connect()
    coll = ensure_collection(args.name, args.dim, args.metric)
    print(f"Collection pronta: {coll.name} | dim={args.dim} | metric={args.metric}")

if __name__ == "__main__":
    main()

