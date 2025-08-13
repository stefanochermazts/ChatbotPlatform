<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            $table->json('link_only_patterns')->nullable()->after('exclude_patterns');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            $table->dropColumn('link_only_patterns');
        });
    }
};


