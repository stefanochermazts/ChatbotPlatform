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
        Schema::create('quick_action_executions', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quick_action_id')->constrained()->cascadeOnDelete();
            
            // Execution Context
            $table->string('session_id', 100)->index(); // Widget session ID
            $table->string('user_identifier', 100)->nullable()->index(); // Email, user ID, etc.
            $table->string('execution_id', 100)->unique(); // Unique execution ID for deduplication
            
            // Request Information
            $table->json('request_data')->nullable(); // Data sent with the request
            $table->string('request_method', 10); // GET, POST, etc.
            $table->text('request_url')->nullable(); // URL called
            $table->json('request_headers')->nullable(); // Headers sent
            
            // Response Information
            $table->integer('response_status')->nullable(); // HTTP status code
            $table->json('response_data')->nullable(); // Response data
            $table->text('response_headers')->nullable(); // Response headers
            $table->integer('response_time_ms')->nullable(); // Response time in milliseconds
            
            // Execution Status
            $table->string('status', 20)->default('pending'); // pending, success, failed, timeout
            $table->text('error_message')->nullable(); // Error message if failed
            $table->text('error_trace')->nullable(); // Stack trace if error
            
            // Security & Authentication
            $table->string('jwt_token_hash', 64)->nullable(); // SHA256 hash of JWT token used
            $table->string('hmac_signature', 128)->nullable(); // HMAC signature
            $table->boolean('security_validated')->default(false);
            
            // Network Information
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referer_url')->nullable();
            
            // Business Logic
            $table->boolean('is_retry')->default(false); // Whether this is a retry attempt
            $table->integer('retry_count')->default(0); // Number of retries
            $table->foreignId('original_execution_id')->nullable(); // Link to original execution if retry
            
            // Rate Limiting
            $table->boolean('rate_limited')->default(false);
            $table->string('rate_limit_key', 100)->nullable(); // Rate limiting key used
            
            // Timestamps
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance and queries
            $table->index(['tenant_id', 'status']);
            $table->index(['quick_action_id', 'status']);
            $table->index(['session_id', 'started_at']);
            $table->index(['user_identifier', 'started_at']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index('rate_limit_key');
            
            // Foreign key constraint for retry relationship
            $table->foreign('original_execution_id')->references('id')->on('quick_action_executions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_action_executions');
    }
};
