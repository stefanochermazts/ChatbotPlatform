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
        Schema::create('widget_events', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            // Event Information
            $table->string('event_type', 50)->index(); // widget_opened, widget_closed, message_sent, message_received, etc.
            $table->string('session_id', 100)->index(); // Track user sessions
            $table->string('user_id', 100)->nullable()->index(); // Optional user tracking

            // Message/Interaction Data
            $table->text('message_content')->nullable(); // Content of message if applicable
            $table->integer('message_length')->nullable(); // Length of message
            $table->json('citations')->nullable(); // Citations data for responses
            $table->integer('citations_count')->default(0);

            // Response Quality Metrics
            $table->decimal('confidence_score', 5, 3)->nullable(); // RAG confidence
            $table->integer('response_time_ms')->nullable(); // Response time in milliseconds
            $table->string('model_used', 50)->nullable(); // AI model used
            $table->integer('tokens_used')->nullable(); // Token consumption

            // User Interaction
            $table->integer('user_rating')->nullable(); // 1-5 rating if provided
            $table->string('feedback_type', 20)->nullable(); // thumbs_up, thumbs_down, etc.
            $table->text('feedback_text')->nullable(); // Optional feedback text

            // Technical Information
            $table->string('widget_version', 20)->default('1.0.0');
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('referrer_url')->nullable();
            $table->string('page_url')->nullable();

            // Geographic Data
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();

            // Device Information
            $table->string('device_type', 20)->nullable(); // desktop, mobile, tablet
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->boolean('is_mobile')->default(false);

            // Widget Configuration
            $table->string('widget_theme', 50)->nullable();
            $table->string('widget_position', 20)->nullable();
            $table->boolean('conversation_context_enabled')->default(true);

            // Performance Metrics
            $table->integer('load_time_ms')->nullable(); // Widget load time
            $table->integer('interaction_duration_ms')->nullable(); // Time spent interacting
            $table->integer('messages_in_session')->default(0); // Total messages in session

            // Business Metrics
            $table->boolean('resolved_query')->nullable(); // Whether query was resolved
            $table->string('intent_detected', 100)->nullable(); // Detected user intent
            $table->string('escalation_reason')->nullable(); // If escalated to human
            $table->decimal('satisfaction_score', 3, 2)->nullable(); // Calculated satisfaction

            // Error Tracking
            $table->boolean('had_error')->default(false);
            $table->string('error_type', 50)->nullable();
            $table->text('error_message')->nullable();

            // Custom Properties (JSON for extensibility)
            $table->json('custom_properties')->nullable();

            // Metadata
            $table->timestamp('event_timestamp')->useCurrent()->index();
            $table->timestamps();

            // Indexes for performance
            $table->index(['tenant_id', 'event_type', 'event_timestamp']);
            $table->index(['tenant_id', 'session_id']);
            $table->index(['event_type', 'event_timestamp']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('user_rating');
            $table->index('had_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_events');
    }
};
