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
        Schema::create('handoff_requests', function (Blueprint $table) {
            $table->id();

            // 🔗 Relationships
            $table->unsignedBigInteger('conversation_session_id');
            $table->unsignedBigInteger('tenant_id'); // Denormalized per scoping
            $table->unsignedBigInteger('requesting_message_id')->nullable(); // Messaggio che ha innescato richiesta

            // 🎯 Request Details
            $table->enum('trigger_type', [
                'user_explicit',     // Utente chiede esplicitamente un operatore
                'bot_escalation',    // Bot decide di escalare per low confidence
                'intent_complex',    // Query troppo complessa per il bot
                'sentiment_negative', // Sentiment negativo rilevato
                'timeout_frustration', // Troppi messaggi senza risoluzione
                'manual_operator',   // Operatore avvia handoff manualmente
                'system_rule',        // Regola automatica del tenant
            ]);

            $table->text('reason')->nullable(); // Spiegazione dettagliata della richiesta
            $table->text('user_message')->nullable(); // Ultimo messaggio utente che ha scatenato handoff
            $table->json('context_data')->nullable(); // Dati contestuali (intent, entities, etc.)

            // 🎲 Priority & Routing
            $table->enum('priority', [
                'low',
                'normal',
                'high',
                'urgent',
            ])->default('normal');

            $table->json('routing_criteria')->nullable(); // Criteri per assegnazione (skill, lingua, etc.)
            $table->string('preferred_operator_id')->nullable(); // Operatore preferito se disponibile

            // 📊 Status & Timeline
            $table->enum('status', [
                'pending',          // In attesa di essere presa in carico
                'assigned',         // Assegnata ad operatore
                'in_progress',      // Operatore sta gestendo
                'resolved',         // Risolta con successo
                'escalated',        // Escalata a livello superiore
                'cancelled',        // Cancellata (utente ha abbandonato)
                'timeout',          // Scaduta per timeout
                'failed',           // Fallita per errori tecnici
            ])->default('pending');

            // 👤 Assignment
            $table->unsignedBigInteger('assigned_operator_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedInteger('assignment_attempts')->default(0); // Quante volte si è tentato assegnare

            // ⏱️ SLA & Timing
            $table->timestamp('requested_at');
            $table->timestamp('first_response_at')->nullable(); // Prima risposta operatore
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('wait_time_seconds')->nullable(); // Tempo attesa prima assegnazione
            $table->unsignedInteger('resolution_time_seconds')->nullable(); // Tempo totale risoluzione

            // 📈 Quality & Metrics
            $table->enum('resolution_outcome', [
                'resolved_by_operator',    // Risolto da operatore
                'resolved_by_escalation',  // Risolto tramite escalation
                'user_satisfied',          // Utente soddisfatto
                'user_abandoned',          // Utente ha abbandonato
                'transferred_back_to_bot', // Ripassato al bot
                'requires_followup',        // Richiede follow-up
            ])->nullable();

            $table->text('resolution_notes')->nullable(); // Note operatore sulla risoluzione
            $table->decimal('user_satisfaction', 3, 2)->nullable(); // 1.00 - 5.00
            $table->json('feedback_data')->nullable(); // Feedback strutturato utente

            // 🔧 Technical Data
            $table->json('metadata')->nullable(); // Dati tecnici, debug, custom
            $table->json('tags')->nullable(); // Tag per categorizzazione e reporting
            $table->boolean('is_escalated')->default(false);
            $table->unsignedBigInteger('escalated_to_request_id')->nullable(); // Chain escalation

            $table->timestamps();

            // 🔑 Indexes per performance
            $table->index(['conversation_session_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'priority']);
            $table->index(['assigned_operator_id']);
            $table->index(['trigger_type']);
            $table->index(['requested_at']);
            $table->index(['status', 'requested_at']);
            $table->index(['priority', 'requested_at']);

            // 🔗 Foreign Keys
            $table->foreign('conversation_session_id')->references('id')->on('conversation_sessions')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('requesting_message_id')->references('id')->on('conversation_messages')->onDelete('set null');
            $table->foreign('assigned_operator_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('escalated_to_request_id')->references('id')->on('handoff_requests')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('handoff_requests');
    }
};
