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
        Schema::table('tenants', function (Blueprint $table) {
            // Configurazioni RAG personalizzabili per tenant
            $table->json('rag_settings')->nullable()->after('custom_synonyms');
            // Profilo RAG predefinito (public_administration, ecommerce, customer_service)
            $table->string('rag_profile')->nullable()->after('rag_settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['rag_settings', 'rag_profile']);
        });
    }
};
