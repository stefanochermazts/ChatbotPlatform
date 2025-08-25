<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            // Colonna per memorizzare la chiave cifrata (cast 'encrypted' lato modello)
            $table->text('key')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('key');
        });
    }
};
