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
            if (! Schema::hasColumn('scraper_configs', 'title_strategy')) {
                $table->string('title_strategy', 16)
                    ->default('title')
                    ->after('js_final_wait');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            if (Schema::hasColumn('scraper_configs', 'title_strategy')) {
                $table->dropColumn('title_strategy');
            }
        });
    }
};
