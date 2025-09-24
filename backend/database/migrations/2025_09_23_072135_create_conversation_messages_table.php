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
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            
            // 🔗 Relationship
            $table->unsignedBigInteger('conversation_session_id');
            $table->unsignedBigInteger('tenant_id'); // Denormalized per performance e scoping
            
            // 👤 Message Source
            $table->enum('sender_type', [
                'user',           // Messaggio dall'utente finale
                'bot',            // Risposta automatica del chatbot
                'operator',       // Messaggio da operatore umano
                'system'          // Messaggio di sistema (handoff, status, etc.)
            ]);
            
            $table->unsignedBigInteger('sender_id')->nullable(); // ID operatore se sender_type = 'operator'
            $table->string('sender_name', 255)->nullable(); // Nome visualizzato
            
            // 💬 Message Content
            $table->text('content'); // Contenuto principale del messaggio
            $table->enum('content_type', [
                'text',           // Testo semplice
                'markdown',       // Testo formattato
                'html',           // HTML (per operatori)
                'system_event',   // Eventi di sistema
                'handoff_request', // Richiesta passaggio umano
                'quick_reply',    // Risposta rapida
                'attachment',     // File/immagine
                'form_data'       // Dati form strutturati
            ])->default('text');
            
            // 🎯 Message Context & Response
            $table->json('citations')->nullable(); // Citazioni RAG per risposte bot
            $table->string('intent', 100)->nullable(); // Intent riconosciuto (se applicabile)
            $table->decimal('confidence_score', 5, 4)->nullable(); // Confidence RAG/NLU
            $table->text('prompt_used')->nullable(); // Prompt utilizzato per generare risposta
            $table->unsignedBigInteger('parent_message_id')->nullable(); // Per threading/risposte
            
            // 📊 Message Metrics
            $table->boolean('is_helpful')->nullable(); // Feedback utente su utilità
            $table->integer('response_time_ms')->nullable(); // Tempo risposta in ms
            $table->json('rag_metadata')->nullable(); // Metadata retrieval (KB used, docs found, etc.)
            
            // 📱 Technical Data
            $table->json('metadata')->nullable(); // Dati tecnici, debug info, custom data
            $table->string('message_id', 255)->nullable(); // ID esterno per tracking
            $table->boolean('is_edited')->default(false); // Se messaggio è stato modificato
            $table->timestamp('edited_at')->nullable();
            $table->text('edit_reason')->nullable();
            
            // ⏱️ Timing
            $table->timestamp('sent_at'); // Quando è stato inviato
            $table->timestamp('delivered_at')->nullable(); // Quando è stato consegnato
            $table->timestamp('read_at')->nullable(); // Quando è stato letto
            
            $table->timestamps();
            
            // 🔑 Indexes per performance
            $table->index(['conversation_session_id', 'sent_at']);
            $table->index(['tenant_id', 'sender_type']);
            $table->index(['sender_type', 'sender_id']);
            $table->index(['content_type']);
            $table->index(['intent']);
            $table->index(['sent_at']);
            $table->index(['parent_message_id']);
            $table->index(['is_helpful']);
            
            // 🔗 Foreign Keys
            $table->foreign('conversation_session_id')->references('id')->on('conversation_sessions')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('parent_message_id')->references('id')->on('conversation_messages')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
