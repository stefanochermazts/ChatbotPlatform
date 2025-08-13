<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            // Rimuovi unique su tenant_id se presente
            try {
                $table->dropUnique(['tenant_id']);
            } catch (\Throwable $e) {
                // ignore if not exists
            }
            // Nuovi campi per multi-scraper e scheduling
            $table->string('name')->default('Scraper')->after('tenant_id');
            $table->boolean('enabled')->default(true)->after('respect_robots');
            $table->integer('interval_minutes')->nullable()->after('enabled');
            $table->timestamp('last_run_at')->nullable()->after('interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('scraper_configs', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('scraper_configs', 'enabled')) {
                $table->dropColumn('enabled');
            }
            if (Schema::hasColumn('scraper_configs', 'interval_minutes')) {
                $table->dropColumn('interval_minutes');
            }
            if (Schema::hasColumn('scraper_configs', 'last_run_at')) {
                $table->dropColumn('last_run_at');
            }
        });
    }
};


