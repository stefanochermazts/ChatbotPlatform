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
        Schema::create('tenant_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Form configuration
            $table->string('name'); // Nome del form (es. "Richiesta Anagrafe")
            $table->text('description')->nullable(); // Descrizione per admin
            $table->boolean('active')->default(true);

            // Trigger configuration
            $table->json('trigger_keywords')->nullable(); // Parole chiave che attivano il form
            $table->integer('trigger_after_messages')->nullable(); // Attiva dopo N messaggi
            $table->json('trigger_after_questions')->nullable(); // Attiva dopo domande specifiche

            // Email template configuration
            $table->string('email_template_subject')->default('Conferma ricezione richiesta');
            $table->text('email_template_body')->nullable(); // Template email di conferma
            $table->string('email_logo_path')->nullable(); // Path logo tenant per email

            // Admin notification settings
            $table->string('admin_notification_email')->nullable(); // Email admin per notifiche
            $table->boolean('auto_response_enabled')->default(false); // Auto-risposta abilitata
            $table->text('auto_response_message')->nullable(); // Messaggio auto-risposta

            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_forms');
    }
};
