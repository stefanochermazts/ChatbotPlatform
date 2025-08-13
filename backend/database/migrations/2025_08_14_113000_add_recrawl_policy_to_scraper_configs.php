<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            $table->boolean('skip_known_urls')->default(true)->after('target_knowledge_base_id');
            $table->integer('recrawl_days')->nullable()->after('skip_known_urls');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('scraper_configs', 'skip_known_urls')) {
                $table->dropColumn('skip_known_urls');
            }
            if (Schema::hasColumn('scraper_configs', 'recrawl_days')) {
                $table->dropColumn('recrawl_days');
            }
        });
    }
};


