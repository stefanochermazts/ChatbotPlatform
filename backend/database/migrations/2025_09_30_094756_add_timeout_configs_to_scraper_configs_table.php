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
            // Timeout configurabili per JavaScript rendering
            $table->integer('js_timeout')->nullable()->default(30)->comment('Timeout per rendering JavaScript (secondi)');
            $table->integer('js_navigation_timeout')->nullable()->default(30)->comment('Timeout per navigazione Puppeteer (secondi)');
            $table->integer('js_content_wait')->nullable()->default(15)->comment('Timeout attesa contenuto dinamico (secondi)');

            // Timeout per attese specifiche
            $table->integer('js_scroll_delay')->nullable()->default(2)->comment('Delay tra scroll per lazy loading (secondi)');
            $table->integer('js_final_wait')->nullable()->default(8)->comment('Attesa finale per stabilizzazione contenuto (secondi)');

            // Manteniamo il timeout esistente per HTTP requests
            // Il campo 'timeout' esistente diventa 'http_timeout' semanticamente
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            $table->dropColumn([
                'js_timeout',
                'js_navigation_timeout',
                'js_content_wait',
                'js_scroll_delay',
                'js_final_wait',
            ]);
        });
    }
};
