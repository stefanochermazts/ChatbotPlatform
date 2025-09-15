# ChatbotPlatform - Guida al Deployment in Produzione

## Indice
1. [Architettura di Deployment](#architettura-di-deployment)
2. [Requisiti Infrastrutturali](#requisiti-infrastrutturali)
3. [Configurazione .env per Produzione](#configurazione-env-per-produzione)
4. [Setup Database e Cache](#setup-database-e-cache)
5. [Pipeline di Build e Deploy](#pipeline-di-build-e-deploy)
6. [Configurazione Web Server](#configurazione-web-server)
7. [Workers e Code](#workers-e-code)
8. [Monitoring e Logging](#monitoring-e-logging)
9. [Backup e Disaster Recovery](#backup-e-disaster-recovery)
10. [Checklist Pre-Deploy](#checklist-pre-deploy)
11. [Troubleshooting](#troubleshooting)

---

## Architettura di Deployment

### Stack Produzione Raccomandato
- **Web Server**: NGINX + PHP-FPM 8.2+ (o Octane/RoadRunner)
- **Database**: PostgreSQL 16+ con estensione pgvector
- **Cache/Queue**: Redis 7+
- **Search**: Meilisearch/Typesense (o BM25 in-database)
- **Vector Store**: Milvus/Zilliz (o pgvector)
- **Storage**: S3/Azure Blob + CDN
- **LB/WAF**: Cloudflare/AWS ALB
- **Container**: Docker (opzionale)

### Architettura Multi-Server
```
Internet → Cloudflare/CDN → Load Balancer → Web Servers (2+)
                                        ↓
                               Application Servers
                                        ↓
Database (Master/Replica) ← → Redis Cluster ← → Worker Nodes
                                        ↓
                               Vector Store (Milvus)
                                        ↓
                               Object Storage (S3)
```

---

## Requisiti Infrastrutturali

### Server Web/App (per 1000 utenti concorrenti)
- **CPU**: 8+ cores (Intel Xeon/AMD EPYC)
- **RAM**: 16-32 GB
- **Storage**: SSD NVMe 500GB+
- **Network**: 1Gbps+

### Database Server
- **CPU**: 8+ cores ottimizzati per database
- **RAM**: 32-64 GB (PostgreSQL cache)
- **Storage**: SSD NVMe 1TB+ con IOPS elevati
- **Backup**: Storage separato per backup

### Worker Nodes (RAG/Embeddings)
- **CPU**: 16+ cores (CPU-intensive)
- **RAM**: 32-64 GB
- **GPU**: Opzionale per modelli locali
- **Storage**: SSD NVMe 500GB+

### Redis/Cache
- **CPU**: 4+ cores
- **RAM**: 16-32 GB (principalmente in-memory)
- **Storage**: SSD per persistenza

---

## Configurazione .env per Produzione

### Configurazione Base Applicazione
```bash
# ===========================================
# CONFIGURAZIONE BASE APPLICAZIONE
# ===========================================
APP_NAME="ChatbotPlatform"
APP_ENV=production
APP_KEY=base64:GENERA_CHIAVE_SICURA_32_CARATTERI
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://your-domain.com
APP_LOCALE=it
APP_FALLBACK_LOCALE=en

# Token admin per provisioning sicuro
ADMIN_TOKEN=GENERA_TOKEN_SICURO_64_CARATTERI

# ===========================================
# DATABASE CONFIGURAZIONE (PostgreSQL)
# ===========================================
DB_CONNECTION=pgsql
DB_HOST=your-postgres-host.example.com
DB_PORT=5432
DB_DATABASE=chatbot_platform_prod
DB_USERNAME=chatbot_app
DB_PASSWORD=PASSWORD_SICURA_COMPLESSA

# Pool di connessioni ottimizzato
DB_CHARSET=utf8
DB_SEARCH_PATH=public
DB_SSLMODE=require

# Cache query nel database per performance
DB_CACHE_CONNECTION=pgsql
DB_CACHE_TABLE=cache
DB_CACHE_LOCK_CONNECTION=redis
DB_CACHE_LOCK_TABLE=cache_locks

# ===========================================
# REDIS CONFIGURAZIONE (Cache + Code)
# ===========================================
REDIS_HOST=your-redis-cluster.example.com
REDIS_PASSWORD=REDIS_PASSWORD_SICURA
REDIS_PORT=6379
REDIS_DATABASE=0

# Cache Redis separata
REDIS_CACHE_CONNECTION=cache
REDIS_CACHE_DATABASE=1

# Code Redis separata 
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_DATABASE=2

# Configurazioni cache ottimizzate
CACHE_STORE=redis
CACHE_PREFIX=cbp_prod

# ===========================================
# CODE E WORKERS (Performance Critical)
# ===========================================
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database

# Code specializzate per diversi tipi di lavoro
DB_QUEUE=default
DB_QUEUE_TABLE=jobs
DB_QUEUE_RETRY_AFTER=180

# Code specifiche per RAG/Scraping
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=300
SCRAPER_QUEUE_RETRY_AFTER=600

# Timeout ottimizzati per operazioni lunghe
INGESTION_TIMEOUT=1800
EMBEDDING_TIMEOUT=900
SCRAPING_TIMEOUT=1200

# ===========================================
# PERFORMANCE E SCALING
# ===========================================
# Session su Redis per load balancing
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=.your-domain.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict

# Ottimizzazioni view cache
VIEW_CACHE_DRIVER=redis
VIEW_COMPILED_PATH=/var/www/storage/framework/views

# Broadcast per real-time features
BROADCAST_CONNECTION=redis
BROADCAST_DRIVER=pusher

# ===========================================
# OPENAI E RAG CONFIGURAZIONE
# ===========================================
OPENAI_API_KEY=sk-CHIAVE_OPENAI_PRODUZIONE
OPENAI_API_BASE_URL=https://api.openai.com/v1
OPENAI_ORGANIZATION=org-YOUR_ORG_ID
OPENAI_PROJECT=proj_YOUR_PROJECT_ID

# Modelli di produzione ottimizzati
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIM=1536
OPENAI_CHAT_MODEL=gpt-4o-mini
OPENAI_EXPENSIVE_MODEL=gpt-4o

# Rate limiting per costi
OPENAI_MAX_TOKENS_PER_MINUTE=200000
OPENAI_MAX_REQUESTS_PER_MINUTE=500

# ===========================================
# RAG CONFIGURAZIONE PERFORMANCE
# ===========================================
# Chunking ottimizzato per produzione
RAG_CHUNK_MAX_CHARS=2200
RAG_CHUNK_OVERLAP_CHARS=250

# Vector search ottimizzato
RAG_VECTOR_DRIVER=milvus
RAG_VECTOR_METRIC=cosine
RAG_VECTOR_TOP_K=50
RAG_MMR_LAMBDA=0.3

# Retrieval ibrido bilanciato
RAG_VECTOR_TOP_K=50
RAG_BM25_TOP_K=100
RAG_RRF_K=60
RAG_MMR_TAKE=30
RAG_NEIGHBOR_RADIUS=3

# Cache RAG per performance
RAG_CACHE_ENABLED=true
RAG_CACHE_TTL=300

# Context building ottimizzato
RAG_CTX_ENABLED=true
RAG_CTX_MAX_CHARS=8000
RAG_CTX_COMPRESS_IF_OVER=1000
RAG_CTX_COMPRESS_TARGET=500

# Features avanzate controllate
RAG_FEAT_HYBRID=true
RAG_FEAT_RERANKER=true
RAG_FEAT_MULTIQUERY=false  # Disabilitato per performance
RAG_FEAT_CONTEXT=true

# Soglie confidence bilanciate
RAG_MIN_CITATIONS=1
RAG_MIN_CONFIDENCE=0.08
RAG_FORCE_IF_HAS_CITATIONS=true

# ===========================================
# MILVUS/VECTOR STORE PRODUZIONE
# ===========================================
MILVUS_HOST=your-milvus-cluster.example.com
MILVUS_PORT=19530
MILVUS_TOKEN=MILVUS_TOKEN_SICURO
MILVUS_TLS=true
MILVUS_COLLECTION=kb_chunks_prod_v1
MILVUS_PARTITIONS_ENABLED=true

# Indicizzazione ottimizzata per produzione
MILVUS_INDEX_TYPE=HNSW
MILVUS_HNSW_M=16
MILVUS_HNSW_EF_CONSTRUCTION=256
MILVUS_HNSW_EF=128

# ===========================================
# STORAGE E CDN
# ===========================================
FILESYSTEM_DISK=s3

# S3/Object Storage
AWS_ACCESS_KEY_ID=YOUR_ACCESS_KEY
AWS_SECRET_ACCESS_KEY=YOUR_SECRET_KEY
AWS_DEFAULT_REGION=eu-west-1
AWS_BUCKET=chatbot-platform-prod-storage
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_ENDPOINT=
AWS_URL=https://chatbot-platform-prod-storage.s3.eu-west-1.amazonaws.com

# CDN per asset statici
CDN_URL=https://cdn.your-domain.com
ASSET_URL=https://cdn.your-domain.com

# ===========================================
# EMAIL E NOTIFICHE
# ===========================================
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@mg.your-domain.com
MAIL_PASSWORD=MAILGUN_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="ChatbotPlatform"

# Notifiche Slack/Teams per alerting
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
TEAMS_WEBHOOK_URL=https://your-org.webhook.office.com/YOUR/WEBHOOK/URL

# ===========================================
# LOGGING E MONITORING
# ===========================================
LOG_CHANNEL=stack
LOG_STACK=single,slack
LOG_LEVEL=error
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter

# Structured logging per produzione
LOG_DEPRECATIONS_CHANNEL=null
LOG_QUERY_ENABLED=false
LOG_SLOW_QUERIES=true
LOG_SLOW_QUERY_THRESHOLD=2000

# Sentry per error tracking
SENTRY_LARAVEL_DSN=https://YOUR_SENTRY_DSN@sentry.io/PROJECT_ID
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1

# ===========================================
# SICUREZZA E RATE LIMITING
# ===========================================
# CORS per frontend/widget
SANCTUM_STATEFUL_DOMAINS=your-domain.com,admin.your-domain.com
SESSION_DOMAIN=.your-domain.com

# Rate limiting API
THROTTLE_API_REQUESTS=100
THROTTLE_API_DECAY_MINUTES=1
THROTTLE_CHAT_REQUESTS=10
THROTTLE_CHAT_DECAY_MINUTES=1

# Security headers
SECURE_HEADERS_ENABLED=true
HSTS_MAX_AGE=31536000
CSP_ENABLED=true

# ===========================================
# FEATURES FLAGS PRODUZIONE
# ===========================================
# Widget pubblico
WIDGET_ENABLED=true
WIDGET_RATE_LIMIT=60
WIDGET_CACHE_TTL=300

# Features avanzate
MULTI_KB_SEARCH_ENABLED=true
CONVERSATION_HISTORY_ENABLED=true
SCRAPER_ENABLED=true
SCRAPER_MAX_CONCURRENT=3

# Analytics e telemetria
RAG_TELEMETRY_ENABLED=true
ANALYTICS_ENABLED=true
METRICS_ENABLED=true

# ===========================================
# OCTANE (OPZIONALE - Performance Boost)
# ===========================================
# Decommentare per abilitare Octane
# OCTANE_SERVER=roadrunner
# OCTANE_HTTPS=true
# OCTANE_HOST=0.0.0.0
# OCTANE_PORT=8000
# OCTANE_WORKERS=auto
# OCTANE_MAX_REQUESTS=500
# OCTANE_WATCH=false

# ===========================================
# MAINTENANCE E BACKUP
# ===========================================
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=redis

# Backup automatico
BACKUP_ENABLED=true
BACKUP_S3_BUCKET=chatbot-platform-backups
BACKUP_RETENTION_DAYS=30
BACKUP_SCHEDULE="0 2 * * *"  # Daily at 2 AM

# Health checks
HEALTH_CHECK_ENABLED=true
HEALTH_CHECK_SECRET=HEALTH_CHECK_SECRET_TOKEN
```

---

## Setup Database e Cache

### PostgreSQL Ottimizzazioni
```sql
-- postgresql.conf ottimizzazioni per RAG workload
shared_buffers = 8GB                    # 25% della RAM
work_mem = 256MB                        # Per query complesse
maintenance_work_mem = 2GB              # Per VACUUM e CREATE INDEX
effective_cache_size = 24GB             # 75% della RAM disponibile
random_page_cost = 1.1                  # Per SSD
effective_io_concurrency = 200          # Per SSD
max_connections = 200
checkpoint_completion_target = 0.9
wal_buffers = 64MB
min_wal_size = 2GB
max_wal_size = 8GB

-- Abilitare pgvector
CREATE EXTENSION IF NOT EXISTS vector;

-- Indici ottimizzati per embedding search
CREATE INDEX CONCURRENTLY idx_document_chunks_embedding_vector 
ON document_chunks USING ivfflat (embedding_vector vector_cosine_ops) 
WITH (lists = 1000);

-- Indici per testo full-text search
CREATE INDEX CONCURRENTLY idx_document_chunks_content_gin 
ON document_chunks USING gin(to_tsvector('italian', content));

-- Indici per tenant scoping (CRITICAL)
CREATE INDEX CONCURRENTLY idx_documents_tenant_kb 
ON documents (tenant_id, knowledge_base_id);

CREATE INDEX CONCURRENTLY idx_document_chunks_tenant_kb 
ON document_chunks (tenant_id, knowledge_base_id);
```

### Redis Configurazione
```conf
# redis.conf ottimizzazioni
maxmemory 16gb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
appendonly yes
appendfsync everysec
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
tcp-keepalive 300
timeout 0
```

---

## Pipeline di Build e Deploy

### Script di Build Produzione
```bash
#!/bin/bash
# scripts/deploy-prod.sh

set -e

echo "🚀 Starting production deployment..."

# 1. Backup database
echo "📦 Creating database backup..."
pg_dump $DB_URL > "backup-$(date +%Y%m%d_%H%M%S).sql"

# 2. Maintenance mode
php artisan down --refresh=15 --retry=60 --secret="your-secret-token"

# 3. Pull latest code
git pull origin main

# 4. Install dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --production

# 5. Build assets
echo "🔨 Building assets..."
npm run build

# 6. Clear and optimize caches
echo "🗑️ Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# 7. Run migrations
echo "🗄️ Running migrations..."
php artisan migrate --force

# 8. Optimize for production
echo "⚡ Optimizing for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# 9. Queue restart
echo "🔄 Restarting queues..."
php artisan queue:restart

# 10. Bring back online
echo "✅ Bringing application online..."
php artisan up

echo "🎉 Deployment completed successfully!"
```

### Zero-Downtime Deployment (Blue-Green)
```bash
#!/bin/bash
# scripts/blue-green-deploy.sh

CURRENT_SLOT=$(readlink /var/www/current)
if [[ $CURRENT_SLOT == *"blue"* ]]; then
    NEW_SLOT="green"
    OLD_SLOT="blue"
else
    NEW_SLOT="blue"
    OLD_SLOT="green"
fi

echo "Deploying to $NEW_SLOT slot..."

# Deploy to new slot
cd /var/www/$NEW_SLOT
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize

# Health check
curl -f http://localhost:8000/health || exit 1

# Switch traffic
ln -sfn /var/www/$NEW_SLOT /var/www/current
sudo systemctl reload nginx

# Cleanup old slot
sleep 30
echo "Deployment to $NEW_SLOT completed"
```

---

## Configurazione Web Server

### NGINX Configurazione Ottimizzata
```nginx
# /etc/nginx/sites-available/chatbot-platform
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/current/backend/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /path/to/ssl/cert.pem;
    ssl_certificate_key /path/to/ssl/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Gzip Compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/xml+rss application/json;

    # Rate Limiting
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
    limit_req_zone $binary_remote_addr zone=chat:10m rate=5r/s;

    # Asset Caching
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # API Rate Limiting
    location /api/ {
        limit_req zone=api burst=20 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Chat API Stricter Limiting
    location /v1/chat/completions {
        limit_req zone=chat burst=10 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Widget Static Files
    location /widget/ {
        expires 1h;
        add_header Cache-Control "public";
        try_files $uri $uri/ =404;
    }

    # PHP Processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        
        # Performance tuning
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        fastcgi_read_timeout 300;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}
```

### PHP-FPM Ottimizzazioni
```ini
; /etc/php/8.2/fpm/pool.d/chatbot-platform.conf
[chatbot-platform]
user = www-data
group = www-data
listen = /var/run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process manager
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

; Performance tuning
request_terminate_timeout = 300
request_slowlog_timeout = 10
slowlog = /var/log/php-fpm-slow.log

; Memory
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
php_admin_value[post_max_size] = 100M
php_admin_value[upload_max_filesize] = 100M

; OpCache
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 256
php_admin_value[opcache.max_accelerated_files] = 10000
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.save_comments] = 1
php_admin_value[opcache.fast_shutdown] = 1
```

---

## Workers e Code

### Systemd Service per Queue Workers
```ini
# /etc/systemd/system/chatbot-queue-worker@.service
[Unit]
Description=ChatbotPlatform Queue Worker %i
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=3
ExecStart=/usr/bin/php /var/www/current/backend/artisan queue:work redis --queue=%i --sleep=3 --tries=3 --max-time=3600 --memory=512
TimeoutStopSec=60
KillMode=mixed
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Configurazione Supervisor
```ini
# /etc/supervisor/conf.d/chatbot-workers.conf
[group:chatbot-workers]
programs=chatbot-default,chatbot-ingestion,chatbot-embeddings,chatbot-scraping

[program:chatbot-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/current/backend/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/chatbot-default.log
stopwaitsecs=60

[program:chatbot-ingestion]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/current/backend/artisan queue:work redis --queue=ingestion --sleep=3 --tries=3 --max-time=1800 --memory=1024
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/chatbot-ingestion.log
stopwaitsecs=90

[program:chatbot-embeddings]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/current/backend/artisan queue:work redis --queue=embeddings --sleep=3 --tries=3 --max-time=900 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=3
redirect_stderr=true
stdout_logfile=/var/log/supervisor/chatbot-embeddings.log
stopwaitsecs=60

[program:chatbot-scraping]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/current/backend/artisan queue:work redis --queue=scraping --sleep=3 --tries=3 --max-time=1200 --memory=1024
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/chatbot-scraping.log
stopwaitsecs=120
```

### Horizon per Queue Management (Raccomandato)
```bash
# Installazione Horizon
composer require laravel/horizon

# Configurazione
php artisan horizon:install
php artisan vendor:publish --provider="Laravel\Horizon\HorizonServiceProvider"

# Systemd service per Horizon
# /etc/systemd/system/chatbot-horizon.service
[Unit]
Description=ChatbotPlatform Horizon Queue Manager
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/current/backend/artisan horizon
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

---

## Monitoring e Logging

### Structured Logging Configuration
```php
// config/logging.php per produzione
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'slack'],
        'ignore_exceptions' => false,
    ],

    'single' => [
        'driver' => 'single',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'error'),
        'formatter' => \Monolog\Formatter\JsonFormatter::class,
    ],

    'slack' => [
        'driver' => 'slack',
        'url' => env('SLACK_WEBHOOK_URL'),
        'username' => 'ChatbotPlatform',
        'emoji' => ':boom:',
        'level' => 'error',
    ],

    'sentry' => [
        'driver' => 'sentry',
    ],
],
```

### Health Check Endpoint
```php
// routes/web.php
Route::get('/health', function () {
    $checks = [
        'database' => DB::connection()->getPdo() !== null,
        'redis' => Redis::connection()->ping(),
        'storage' => Storage::disk()->exists('health-check.txt'),
        'queue' => true, // Add queue health check
    ];
    
    $healthy = array_reduce($checks, fn($carry, $check) => $carry && $check, true);
    
    return response()->json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $healthy ? 200 : 503);
})->name('health');
```

### Prometheus Metrics (Optional)
```php
// Metrics per monitoring avanzato
Route::get('/metrics', function () {
    $metrics = [
        'http_requests_total' => Cache::get('http_requests_total', 0),
        'chat_completions_total' => Cache::get('chat_completions_total', 0),
        'rag_queries_total' => Cache::get('rag_queries_total', 0),
        'queue_jobs_total' => DB::table('jobs')->count(),
        'failed_jobs_total' => DB::table('failed_jobs')->count(),
    ];
    
    $output = '';
    foreach ($metrics as $name => $value) {
        $output .= "# HELP {$name} Total number of {$name}\n";
        $output .= "# TYPE {$name} counter\n";
        $output .= "{$name} {$value}\n";
    }
    
    return response($output, 200, ['Content-Type' => 'text/plain']);
});
```

---

## Backup e Disaster Recovery

### Script di Backup Automatico
```bash
#!/bin/bash
# scripts/backup-prod.sh

BACKUP_DIR="/backups/chatbot-platform"
DATE=$(date +%Y%m%d_%H%M%S)
S3_BUCKET="chatbot-platform-backups"

# Database backup
echo "Creating database backup..."
pg_dump $DATABASE_URL | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"

# Upload files backup
echo "Creating files backup..."
tar -czf "$BACKUP_DIR/files_backup_$DATE.tar.gz" /var/www/current/backend/storage/app

# Upload to S3
echo "Uploading to S3..."
aws s3 cp "$BACKUP_DIR/db_backup_$DATE.sql.gz" "s3://$S3_BUCKET/database/"
aws s3 cp "$BACKUP_DIR/files_backup_$DATE.tar.gz" "s3://$S3_BUCKET/files/"

# Cleanup old local backups (keep 7 days)
find $BACKUP_DIR -name "*.gz" -mtime +7 -delete

echo "Backup completed: $DATE"
```

### Crontab per Backup Automatico
```bash
# crontab -e
# Daily backup at 2 AM
0 2 * * * /opt/scripts/backup-prod.sh >> /var/log/backup.log 2>&1

# Weekly full backup on Sundays at 3 AM
0 3 * * 0 /opt/scripts/backup-full.sh >> /var/log/backup-full.log 2>&1
```

---

## Checklist Pre-Deploy

### ✅ Infrastruttura
- [ ] Server configurati con requisiti minimi
- [ ] Database PostgreSQL 16+ con pgvector installato
- [ ] Redis cluster configurato e testato
- [ ] SSL/TLS certificati validi installati
- [ ] CDN configurato per asset statici
- [ ] Load balancer configurato (se multi-server)
- [ ] Backup storage configurato (S3/Azure)

### ✅ Configurazione
- [ ] File `.env` rivisto e validato
- [ ] Chiavi API OpenAI valide e con credito
- [ ] Token di sicurezza generati (APP_KEY, ADMIN_TOKEN)
- [ ] DNS configurato e propagato
- [ ] Firewall rules configurate
- [ ] Monitoring e alerting configurati

### ✅ Database
- [ ] Migrazioni testate in staging
- [ ] Indici di performance creati
- [ ] Backup/restore testato
- [ ] Connection pooling configurato
- [ ] Query performance verificate

### ✅ Performance
- [ ] OpCache PHP abilitato
- [ ] Redis cache funzionante
- [ ] CDN per asset statici
- [ ] Gzip compression abilitata
- [ ] Queue workers configurati
- [ ] Rate limiting attivo

### ✅ Sicurezza
- [ ] Firewall configurato (solo porte necessarie)
- [ ] SSL/TLS configurato correttamente
- [ ] Security headers implementati
- [ ] Rate limiting attivo
- [ ] Logs di sicurezza configurati
- [ ] Backup crittografati

### ✅ Monitoring
- [ ] Health checks funzionanti
- [ ] Log aggregation configurato
- [ ] Error tracking (Sentry) attivo
- [ ] Metriche sistema monitorate
- [ ] Alerting configurato per downtime

---

## Troubleshooting

### Problemi Comuni e Soluzioni

#### 1. Performance Lenta
```bash
# Verifica cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Verifica queue workers
sudo supervisorctl status
php artisan queue:restart

# Verifica database
EXPLAIN ANALYZE SELECT * FROM documents WHERE tenant_id = 1;
```

#### 2. Queue Jobs Bloccati
```bash
# Restart queue workers
php artisan queue:restart

# Clear failed jobs
php artisan queue:flush
php artisan queue:retry all

# Check queue status
php artisan queue:monitor redis:default --max=100
```

#### 3. High Memory Usage
```bash
# Check PHP-FPM processes
ps aux | grep php-fpm

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm

# Check for memory leaks in logs
tail -f /var/log/php-fpm-slow.log
```

#### 4. Database Connection Issues
```bash
# Check PostgreSQL status
sudo systemctl status postgresql

# Check connections
SELECT count(*) FROM pg_stat_activity;

# Check locks
SELECT * FROM pg_locks WHERE NOT granted;
```

#### 5. RAG/Embeddings Errors
```bash
# Check Milvus connection
curl http://milvus-host:19530/health

# Verify OpenAI API
curl -H "Authorization: Bearer $OPENAI_API_KEY" https://api.openai.com/v1/models

# Check vector dimensions
SELECT embedding_dim FROM documents LIMIT 1;
```

### Comandi di Diagnostica
```bash
# System health overview
df -h                           # Disk space
free -h                         # Memory usage
top -p $(pgrep php-fpm)        # PHP processes
netstat -tuln                   # Network connections

# Application diagnostics
php artisan about              # Laravel environment
php artisan route:list         # Available routes
php artisan queue:work --once  # Test queue processing
php artisan tinker             # Interactive shell

# Logs analysis
tail -f /var/log/nginx/error.log
tail -f storage/logs/laravel.log
journalctl -u chatbot-horizon -f
```

### Emergency Rollback
```bash
#!/bin/bash
# scripts/emergency-rollback.sh

echo "🚨 Emergency rollback initiated..."

# Put in maintenance
php artisan down

# Switch to previous deployment
ln -sfn /var/www/previous /var/www/current

# Reload web server
sudo systemctl reload nginx

# Restart workers
sudo supervisorctl restart chatbot-workers:*

# Bring back online
php artisan up

echo "✅ Rollback completed"
```

---

## Conclusioni

Questo deployment guide fornisce una base solida per portare ChatbotPlatform in produzione con performance ottimali. Punti chiave:

1. **Monitoring continuo**: Implementa health checks e alerting
2. **Performance**: Cache Redis, OpCache PHP, CDN per asset
3. **Scalabilità**: Queue workers dedicati, load balancing
4. **Sicurezza**: SSL/TLS, rate limiting, security headers
5. **Backup**: Automatizzati e testati regolarmente
6. **Zero downtime**: Blue-green deployment per aggiornamenti

Ricorda di testare sempre in ambiente di staging prima del deploy in produzione e di avere un piano di rollback pronto in caso di problemi.
