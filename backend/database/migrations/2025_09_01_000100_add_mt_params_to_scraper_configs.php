<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            // Parametri per-tenant del multi-thread scraper
            $table->unsignedInteger('mt_max_threads')->nullable()->after('rate_limit_rps');
            $table->unsignedInteger('mt_chunk_size')->nullable()->after('mt_max_threads');
            $table->unsignedInteger('mt_request_timeout')->nullable()->after('mt_chunk_size'); // seconds
            $table->unsignedInteger('mt_rate_limit_per_minute')->nullable()->after('mt_request_timeout');
            $table->unsignedInteger('mt_retry_attempts')->nullable()->after('mt_rate_limit_per_minute');
            $table->unsignedInteger('mt_retry_delay_seconds')->nullable()->after('mt_retry_attempts');
            $table->unsignedInteger('mt_memory_limit_mb')->nullable()->after('mt_retry_delay_seconds');
            $table->boolean('mt_fast_mode')->default(false)->after('mt_memory_limit_mb');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('scraper_configs', 'mt_fast_mode')) {
                $table->dropColumn('mt_fast_mode');
            }
            foreach ([
                'mt_memory_limit_mb',
                'mt_retry_delay_seconds',
                'mt_retry_attempts',
                'mt_rate_limit_per_minute',
                'mt_request_timeout',
                'mt_chunk_size',
                'mt_max_threads',
            ] as $col) {
                if (Schema::hasColumn('scraper_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};




