<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Forza il tipo della colonna 'key' a TEXT (necessario per chiavi cifrate più lunghe di 255)
        if (Schema::hasColumn('api_keys', 'key')) {
            // Postgres consente ALTER TYPE direttamente; per altri DB rimane TEXT compatibile
            DB::statement('ALTER TABLE api_keys ALTER COLUMN "key" TYPE TEXT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_keys', 'key')) {
            // Non sempre safe tornare a varchar(255); lo facciamo comunque per completezza
            DB::statement('ALTER TABLE api_keys ALTER COLUMN "key" TYPE VARCHAR(255)');
        }
    }
};
