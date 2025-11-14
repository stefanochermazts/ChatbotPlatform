<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source'); // upload|web|connector
            $table->string('path'); // storage path or URL
            $table->json('metadata')->nullable();
            $table->string('ingestion_status')->default('pending'); // pending|processing|ready|failed
            $table->timestamps();
            $table->index(['tenant_id', 'ingestion_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
