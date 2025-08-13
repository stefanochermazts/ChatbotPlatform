<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            $table->foreignId('target_knowledge_base_id')->nullable()->after('respect_robots')->constrained('knowledge_bases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_knowledge_base_id');
        });
    }
};


