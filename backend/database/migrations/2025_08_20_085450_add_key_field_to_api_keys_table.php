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
        if (!Schema::hasColumn('api_keys', 'key')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->string('key')->nullable()->after('name')->comment('Plain text API key for widget usage');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('api_keys', 'key')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropColumn('key');
            });
        }
    }
};
