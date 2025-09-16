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
        Schema::create('chatbot_feedback', function (Blueprint $table) {
            $table->id();
            
            // Relazioni
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Contenuto conversazione
            $table->text('user_question'); // Domanda dell'utente
            $table->text('bot_response'); // Risposta del chatbot
            $table->json('response_metadata')->nullable(); // Citations, confidence, etc.
            
            // Feedback
            $table->enum('rating', ['negative', 'neutral', 'positive']); // 😡 😐 😊
            $table->text('comment')->nullable(); // Commento opzionale dell'utente
            
            // Context/Session
            $table->string('session_id')->nullable(); // ID sessione widget
            $table->string('conversation_id')->nullable(); // ID conversazione
            $table->string('message_id')->nullable(); // ID specifico messaggio
            
            // Metadata
            $table->json('user_agent_data')->nullable(); // Browser, device info
            $table->ipAddress('ip_address')->nullable();
            $table->string('page_url')->nullable(); // Pagina dove è stato dato feedback
            $table->timestamp('feedback_given_at'); // Quando è stato dato il feedback
            
            $table->timestamps();
            
            // Indici per performance
            $table->index(['tenant_id', 'rating']);
            $table->index(['tenant_id', 'feedback_given_at']);
            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_feedback');
    }
};
