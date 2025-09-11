<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('scraper_config_id')->nullable();
            $table->string('session_id')->unique(); // UUID per sessione scraping
            $table->enum('status', ['running', 'completed', 'failed', 'cancelled'])->default('running');
            
            // Contatori scraping
            $table->integer('pages_found')->default(0);
            $table->integer('pages_scraped')->default(0);
            $table->integer('pages_skipped')->default(0);
            $table->integer('pages_failed')->default(0);
            
            // Contatori documenti
            $table->integer('documents_created')->default(0);
            $table->integer('documents_updated')->default(0);
            $table->integer('documents_unchanged')->default(0);
            
            // Contatori ingestion
            $table->integer('ingestion_pending')->default(0);
            $table->integer('ingestion_processing')->default(0);
            $table->integer('ingestion_completed')->default(0);
            $table->integer('ingestion_failed')->default(0);
            
            // Metadati progresso
            $table->string('current_url')->nullable();
            $table->integer('current_depth')->default(0);
            $table->text('last_error')->nullable();
            $table->json('urls_queue')->nullable(); // URLs ancora da processare
            
            // Timing
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('estimated_duration_seconds')->nullable();
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_progress');
    }
};