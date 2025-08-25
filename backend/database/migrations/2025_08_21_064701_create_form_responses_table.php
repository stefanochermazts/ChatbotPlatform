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
        Schema::create('form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            
            // Response content
            $table->text('response_content'); // Contenuto della risposta
            $table->enum('response_type', [
                'web',           // Risposta via interfaccia web
                'email',         // Risposta via email
                'auto'           // Risposta automatica
            ])->default('web');
            
            // Email tracking (se response_type = email)
            $table->string('email_subject')->nullable(); // Oggetto email
            $table->boolean('email_sent')->default(false); // Email inviata con successo
            $table->timestamp('email_sent_at')->nullable(); // Quando è stata inviata
            $table->text('email_error')->nullable(); // Errore invio email se presente
            
            // Response metadata
            $table->boolean('closes_submission')->default(false); // Questa risposta chiude la pratica
            $table->json('attachments')->nullable(); // Eventuali allegati
            $table->text('internal_notes')->nullable(); // Note interne (non inviate all'utente)
            
            $table->timestamps();
            
            // Indexes
            $table->index(['form_submission_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['response_type', 'email_sent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_responses');
    }
};