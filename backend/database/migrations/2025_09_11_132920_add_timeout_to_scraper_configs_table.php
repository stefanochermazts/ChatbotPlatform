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
            $table->integer('timeout')->default(60)->after('rate_limit_rps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            $table->dropColumn('timeout');
        });
    }
};
