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
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            
            // Session and user tracking
            $table->string('session_id'); // ID sessione chatbot
            $table->string('user_email')->nullable(); // Email utente se fornita
            $table->string('user_name')->nullable(); // Nome utente se fornito
            
            // Form data
            $table->json('form_data'); // Dati del form compilato
            $table->json('chat_context')->nullable(); // Contesto conversazione che ha triggerato il form
            
            // Status tracking
            $table->enum('status', [
                'pending',     // In attesa di risposta
                'responded',   // Risposta inviata
                'closed'       // Pratica chiusa
            ])->default('pending');
            
            // Metadata
            $table->timestamp('submitted_at'); // Quando è stato inviato
            $table->ipAddress('ip_address')->nullable(); // IP utente
            $table->text('user_agent')->nullable(); // User agent browser
            $table->string('trigger_type')->nullable(); // Come è stato triggerato (keyword, auto, manual)
            $table->string('trigger_value')->nullable(); // Valore specifico del trigger
            
            // Email tracking
            $table->boolean('confirmation_email_sent')->default(false);
            $table->timestamp('confirmation_email_sent_at')->nullable();
            $table->boolean('admin_notification_sent')->default(false);
            $table->timestamp('admin_notification_sent_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['tenant_id', 'status', 'submitted_at']);
            $table->index(['session_id']);
            $table->index(['user_email']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};