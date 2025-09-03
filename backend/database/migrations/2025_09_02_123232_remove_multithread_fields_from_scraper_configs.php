<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            // Rimuovi i campi multithread non più necessari
            $multiThreadFields = [
                'mt_max_threads',
                'mt_chunk_size', 
                'mt_request_timeout',
                'mt_rate_limit_per_minute',
                'mt_retry_attempts',
                'mt_retry_delay_seconds',
                'mt_memory_limit_mb',
                'mt_fast_mode'
            ];
            
            foreach ($multiThreadFields as $field) {
                if (Schema::hasColumn('scraper_configs', $field)) {
                    $table->dropColumn($field);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            // Ripristina i campi multithread se necessario
            $table->unsignedInteger('mt_max_threads')->nullable();
            $table->unsignedInteger('mt_chunk_size')->nullable();
            $table->unsignedInteger('mt_request_timeout')->nullable();
            $table->unsignedInteger('mt_rate_limit_per_minute')->nullable();
            $table->unsignedInteger('mt_retry_attempts')->nullable();
            $table->unsignedInteger('mt_retry_delay_seconds')->nullable();
            $table->unsignedInteger('mt_memory_limit_mb')->nullable();
            $table->boolean('mt_fast_mode')->default(false);
        });
    }
};