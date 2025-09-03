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
        Schema::table('widget_events', function (Blueprint $table) {
            // Rimuovi il vincolo NOT NULL da tenant_id per permettere eventi pubblici senza tenant
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_events', function (Blueprint $table) {
            // Ripristina il vincolo NOT NULL
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }
};
