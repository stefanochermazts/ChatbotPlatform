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
        Schema::table('form_submissions', function (Blueprint $table) {
            // Modifica i campi da integer a decimal per supportare i decimali
            $table->decimal('first_response_time_minutes', 8, 2)->nullable()->change();
            $table->decimal('avg_response_time_minutes', 8, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            // Ripristina i campi come integer
            $table->integer('first_response_time_minutes')->nullable()->change();
            $table->integer('avg_response_time_minutes')->nullable()->change();
        });
    }
};
