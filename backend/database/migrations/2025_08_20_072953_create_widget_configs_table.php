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
        Schema::create('widget_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Basic Configuration
            $table->boolean('enabled')->default(true);
            $table->string('widget_name')->default('Assistente Virtuale');
            $table->text('welcome_message')->nullable();
            $table->string('position', 20)->default('bottom-right');
            $table->boolean('auto_open')->default(false);

            // Theme Configuration
            $table->string('theme', 50)->default('default');
            $table->json('custom_colors')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('font_family')->nullable();

            // Layout Configuration
            $table->string('widget_width')->default('380px');
            $table->string('widget_height')->default('600px');
            $table->string('border_radius')->default('1.5rem');
            $table->string('button_size')->default('60px');

            // Behavior Configuration
            $table->boolean('show_header')->default(true);
            $table->boolean('show_avatar')->default(true);
            $table->boolean('show_close_button')->default(true);
            $table->boolean('enable_animations')->default(true);
            $table->boolean('enable_dark_mode')->default(true);

            // API Configuration
            $table->string('api_model')->default('gpt-4o-mini');
            $table->decimal('temperature', 3, 2)->default(0.7);
            $table->integer('max_tokens')->default(1000);
            $table->boolean('enable_conversation_context')->default(true);

            // Security Configuration
            $table->json('allowed_domains')->nullable();
            $table->boolean('enable_analytics')->default(true);
            $table->boolean('gdpr_compliant')->default(true);

            // Custom CSS/JS
            $table->longText('custom_css')->nullable();
            $table->longText('custom_js')->nullable();

            // Advanced Configuration (JSON)
            $table->json('advanced_config')->nullable();

            // Metadata
            $table->timestamp('last_updated_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            // Indexes
            $table->unique('tenant_id');
            $table->index(['enabled', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_configs');
    }
};
