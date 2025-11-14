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
        Schema::create('quick_actions', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Action Configuration
            $table->string('action_type', 50); // contact_support, book_appointment, download_brochure, etc.
            $table->string('label'); // Display label for the action button
            $table->string('icon', 50)->nullable(); // Icon class or emoji
            $table->text('description')->nullable(); // Description for the action

            // Action Behavior
            $table->string('action_method', 20)->default('POST'); // HTTP method
            $table->text('action_url')->nullable(); // URL to call (can be relative or absolute)
            $table->json('action_payload')->nullable(); // Default payload to send
            $table->json('required_fields')->nullable(); // Fields required from user (e.g., email, phone)

            // Display Configuration
            $table->integer('display_order')->default(0); // Order in the UI
            $table->boolean('is_enabled')->default(true);
            $table->boolean('requires_auth')->default(false); // Whether user must be authenticated
            $table->string('button_style', 50)->default('primary'); // primary, secondary, outline
            $table->string('confirmation_message')->nullable(); // Confirmation dialog message

            // Rate Limiting
            $table->integer('rate_limit_per_user')->default(5); // Actions per user per hour
            $table->integer('rate_limit_global')->default(100); // Global actions per hour

            // Response Handling
            $table->string('success_message')->nullable(); // Message to show on success
            $table->string('success_action', 20)->default('message'); // message, redirect, download
            $table->text('success_url')->nullable(); // URL for redirect/download
            $table->string('error_message')->nullable(); // Custom error message

            // Security
            $table->boolean('requires_jwt')->default(true); // Whether to use JWT authentication
            $table->integer('jwt_expiry_minutes')->default(15); // JWT token expiry
            $table->boolean('requires_hmac')->default(true); // Whether to validate HMAC

            // Custom Configuration
            $table->json('custom_config')->nullable(); // Additional tenant-specific configuration

            // Metadata
            $table->timestamp('last_used_at')->nullable();
            $table->integer('total_executions')->default(0);
            $table->timestamps();

            // Indexes for performance
            $table->index(['tenant_id', 'action_type']);
            $table->index(['tenant_id', 'is_enabled', 'display_order']);
            $table->index('action_type');
            $table->index('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_actions');
    }
};
