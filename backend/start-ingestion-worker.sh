#!/bin/bash

echo "Avvio worker per coda ingestion..."
echo ""
echo "IMPORTANTE: Questo worker processa chunking, embeddings e Milvus"
echo "Tieni aperto questo terminale per permettere l'ingestion dei documenti!"
echo ""
echo "Usa Ctrl+C per fermare il worker"
echo ""

php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3

