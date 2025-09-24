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
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();
            
            // 🏢 Multitenancy & Scope
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('widget_config_id');
            
            // 🎯 Session Identification  
            $table->string('session_id', 255)->unique(); // UUID o similare per frontend
            $table->string('user_identifier', 255)->nullable(); // IP, fingerprint, o user ID se auth
            
            // 📱 Session Context
            $table->string('channel', 50)->default('widget'); // widget, api, whatsapp, etc.
            $table->string('user_agent', 500)->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->json('browser_info')->nullable(); // viewport, platform, etc.
            
            // 🔄 Session Status & Flow
            $table->enum('status', [
                'active',           // Conversazione in corso
                'waiting_operator', // In coda per operatore
                'assigned',         // Assegnata ad operatore  
                'resolved',         // Risolta
                'abandoned',        // Abbandonata da utente
                'timeout'          // Scaduta per timeout
            ])->default('active');
            
            $table->enum('handoff_status', [
                'bot_only',        // Solo chatbot
                'handoff_requested', // Richiesto passaggio umano
                'handoff_pending',   // In attesa operatore
                'handoff_active',    // Operatore attivo
                'handoff_completed'  // Handoff completato
            ])->default('bot_only');
            
            // 👤 Operator Assignment
            $table->unsignedBigInteger('assigned_operator_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('handoff_requested_at')->nullable();
            $table->text('handoff_reason')->nullable();
            
            // ⏱️ Session Timing
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('total_messages')->default(0);
            $table->unsignedInteger('bot_messages')->default(0);
            $table->unsignedInteger('user_messages')->default(0);
            $table->unsignedInteger('operator_messages')->default(0);
            
            // 📊 Session Metrics
            $table->decimal('satisfaction_score', 3, 2)->nullable(); // 1.00 - 5.00
            $table->boolean('goal_achieved')->nullable();
            $table->string('resolution_type', 100)->nullable(); // 'bot_resolved', 'operator_resolved', 'escalated'
            
            // 🗃️ Session Data
            $table->json('metadata')->nullable(); // Dati custom, contesto, preferenze
            $table->json('tags')->nullable(); // Tag per categorizzazione
            $table->text('summary')->nullable(); // Riassunto automatico o manuale
            
            $table->timestamps();
            
            // 🔑 Indexes per performance
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'widget_config_id']);
            $table->index(['session_id']);
            $table->index(['user_identifier', 'tenant_id']);
            $table->index(['assigned_operator_id']);
            $table->index(['status', 'handoff_status']);
            $table->index(['started_at']);
            $table->index(['last_activity_at']);
            
            // 🔗 Foreign Keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('widget_config_id')->references('id')->on('widget_configs')->onDelete('cascade');
            $table->foreign('assigned_operator_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};
