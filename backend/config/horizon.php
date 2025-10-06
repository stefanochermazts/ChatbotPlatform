<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    */

    'waits' => [
        'redis:default' => 60,
        'redis:scraping' => 120,
        'redis:ingestion' => 300,
        'redis:embeddings' => 300,
        'redis:indexing' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    */

    'environments' => [
        'production' => [
            'supervisor-scraping' => [
                'connection' => 'redis',
                'queue' => ['scraping'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple = parallelismo immediato
                'minProcesses' => 5,                 // 🆕 Minimo 3 worker sempre attivi
                'maxProcesses' => 10,                 // 🚀 Max 5 URL in parallelo
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 1024,
                'tries' => 2,
                'timeout' => 300,     // 5 minuti timeout per URL
                'nice' => 0,
            ],
            'supervisor-ingestion' => [
                'connection' => 'redis',
                'queue' => ['ingestion'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 5,                 // 🆕 Minimo 3 worker
                'maxProcesses' => 10,                 // 🚀 Max 5 documenti in parallelo
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 1536,
                'tries' => 3,
                'timeout' => 1800,    // 30 minuti timeout
                'nice' => 0,
            ],
            'supervisor-embeddings' => [
                'connection' => 'redis',
                'queue' => ['embeddings'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 2,                 // 🆕 Minimo 2 worker
                'maxProcesses' => 3,                 // 3 processi max per embeddings
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 900,
                'nice' => 0,
            ],
            'supervisor-indexing' => [
                'connection' => 'redis',
                'queue' => ['indexing'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 1,                 // 🆕 Minimo 1 worker
                'maxProcesses' => 2,                 // 2 processi max per indexing
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 600,
                'nice' => 0,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 2,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],
        ],

        'local' => [
            'supervisor-scraping' => [
                'connection' => 'redis',
                'queue' => ['scraping'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 2,                 // 🆕 Minimo 2 worker in dev
                'maxProcesses' => 2,                 // 🚀 Max 2 URL in parallelo in locale
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 512,
                'tries' => 2,
                'timeout' => 300,
                'nice' => 0,
            ],
            'supervisor-ingestion' => [
                'connection' => 'redis',
                'queue' => ['ingestion'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 2,                 // 🆕 Minimo 2 worker in dev
                'maxProcesses' => 3,                 // 🚀 Max 3 processi paralleli in locale
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 1024,
                'tries' => 3,
                'timeout' => 1800,
                'nice' => 0,
            ],
            'supervisor-embeddings' => [
                'connection' => 'redis',
                'queue' => ['embeddings'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 1,                 // 🆕 Minimo 1 worker in dev
                'maxProcesses' => 2,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 900,
                'nice' => 0,
            ],
            'supervisor-indexing' => [
                'connection' => 'redis',
                'queue' => ['indexing'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'simple',  // ✅ Simple per parallelismo
                'minProcesses' => 1,                 // 🆕 Minimo 1 worker in dev
                'maxProcesses' => 1,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 600,
                'nice' => 0,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 1,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],
        ],
    ],
];
