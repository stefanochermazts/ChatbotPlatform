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
        Schema::table('form_submissions', function (Blueprint $table) {
            // Thread tracking per performance
            $table->integer('responses_count')->default(0)->after('admin_notification_sent_at');
            $table->timestamp('last_response_at')->nullable()->after('responses_count');
            $table->foreignId('last_response_by')->nullable()->constrained('users')->after('last_response_at');

            // Conversazione attiva (per dashboard quick stats)
            $table->boolean('has_active_conversation')->default(false)->after('last_response_by');
            $table->enum('conversation_priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->after('has_active_conversation');

            // Timing analytics
            $table->integer('first_response_time_minutes')->nullable()->after('conversation_priority'); // Tempo prima risposta in minuti
            $table->integer('avg_response_time_minutes')->nullable()->after('first_response_time_minutes'); // Tempo medio risposta

            // Indexes per dashboard performance
            $table->index(['has_active_conversation', 'last_response_at']);
            $table->index(['conversation_priority', 'status']);
            $table->index(['tenant_id', 'has_active_conversation', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropForeign(['last_response_by']);
            $table->dropIndex(['has_active_conversation', 'last_response_at']);
            $table->dropIndex(['conversation_priority', 'status']);
            $table->dropIndex(['tenant_id', 'has_active_conversation', 'status']);

            $table->dropColumn([
                'responses_count',
                'last_response_at',
                'last_response_by',
                'has_active_conversation',
                'conversation_priority',
                'first_response_time_minutes',
                'avg_response_time_minutes',
            ]);
        });
    }
};
