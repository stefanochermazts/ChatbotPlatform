<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Aumenta la lunghezza del campo source_url da 255 a 2000 caratteri
            // per gestire URL molto lunghi come quelli del Comune di Palmanova
            $table->string('source_url', 2000)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Ripristina la lunghezza originale (255 caratteri)
            // ATTENZIONE: potrebbe fallire se ci sono URL più lunghi di 255 caratteri
            $table->string('source_url', 255)->nullable()->change();
        });
    }
};


