#!/bin/bash

echo "========================================="
echo "   Avvio Worker Paralleli per Ingestion"
echo "========================================="
echo ""
echo "Avviando 3 worker simultanei per processare documenti in parallelo..."
echo ""
echo "Per fermare TUTTI i worker: Ctrl+C in questa finestra"
echo ""

# Avvia worker in background
php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-1 &
WORKER1_PID=$!

php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-2 &
WORKER2_PID=$!

php artisan queue:work --queue=ingestion --tries=3 --timeout=1800 --memory=1024 --sleep=3 --name=worker-3 &
WORKER3_PID=$!

echo ""
echo "✅ 3 worker avviati in background!"
echo "   Worker PIDs: $WORKER1_PID, $WORKER2_PID, $WORKER3_PID"
echo ""

# Gestione interruzione
trap "echo ''; echo '🛑 Fermando tutti i worker...'; kill $WORKER1_PID $WORKER2_PID $WORKER3_PID 2>/dev/null; echo '✅ Worker fermati'; exit 0" INT

# Monitor continuo
echo "Monitor worker attivi (Ctrl+C per fermare):"
echo ""

while true; do
    echo "=== $(date) ==="
    php artisan queue:monitor ingestion --display-all 2>/dev/null || echo "Queue monitor non disponibile"
    echo ""
    sleep 10
done


