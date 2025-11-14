<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_keys', 'key')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                // Colonna per memorizzare la chiave (testo). Se esiste già, questa migration non fa nulla.
                $table->text('key')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_keys', 'key')) {
            Schema::table('api_keys', function (Blueprint $table): void {
                $table->dropColumn('key');
            });
        }
    }
};
