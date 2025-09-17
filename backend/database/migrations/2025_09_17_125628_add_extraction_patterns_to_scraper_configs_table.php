<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            $table->json('extraction_patterns')->nullable()->after('auth_headers');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            $table->dropColumn('extraction_patterns');
        });
    }
};