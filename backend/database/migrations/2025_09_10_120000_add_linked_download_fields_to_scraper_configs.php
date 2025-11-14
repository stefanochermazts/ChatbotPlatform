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
            if (! Schema::hasColumn('scraper_configs', 'download_linked_documents')) {
                $table->boolean('download_linked_documents')->default(false)->after('last_run_at');
            }
            if (! Schema::hasColumn('scraper_configs', 'linked_extensions')) {
                $table->json('linked_extensions')->nullable()->after('download_linked_documents');
            }
            if (! Schema::hasColumn('scraper_configs', 'linked_max_size_mb')) {
                $table->integer('linked_max_size_mb')->default(10)->after('linked_extensions');
            }
            if (! Schema::hasColumn('scraper_configs', 'linked_same_domain_only')) {
                $table->boolean('linked_same_domain_only')->default(true)->after('linked_max_size_mb');
            }
            if (! Schema::hasColumn('scraper_configs', 'linked_target_kb_id')) {
                $table->unsignedBigInteger('linked_target_kb_id')->nullable()->after('linked_same_domain_only');
                $table->foreign('linked_target_kb_id')->references('id')->on('knowledge_bases')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_configs', function (Blueprint $table) {
            if (Schema::hasColumn('scraper_configs', 'linked_target_kb_id')) {
                $table->dropForeign(['linked_target_kb_id']);
                $table->dropColumn('linked_target_kb_id');
            }
            if (Schema::hasColumn('scraper_configs', 'linked_same_domain_only')) {
                $table->dropColumn('linked_same_domain_only');
            }
            if (Schema::hasColumn('scraper_configs', 'linked_max_size_mb')) {
                $table->dropColumn('linked_max_size_mb');
            }
            if (Schema::hasColumn('scraper_configs', 'linked_extensions')) {
                $table->dropColumn('linked_extensions');
            }
            if (Schema::hasColumn('scraper_configs', 'download_linked_documents')) {
                $table->dropColumn('download_linked_documents');
            }
        });
    }
};
