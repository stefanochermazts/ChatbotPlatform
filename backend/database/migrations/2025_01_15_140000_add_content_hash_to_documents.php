<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('path');
            $table->string('content_hash', 64)->nullable()->after('source_url');
            $table->timestamp('last_scraped_at')->nullable()->after('content_hash');
            $table->unsignedInteger('scrape_version')->default(1)->after('last_scraped_at');
            
            // Indice per performance su ricerche per URL e hash
            $table->index(['tenant_id', 'source_url']);
            $table->index(['tenant_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source_url']);
            $table->dropIndex(['tenant_id', 'content_hash']);
            $table->dropColumn(['source_url', 'content_hash', 'last_scraped_at', 'scrape_version']);
        });
    }
};
