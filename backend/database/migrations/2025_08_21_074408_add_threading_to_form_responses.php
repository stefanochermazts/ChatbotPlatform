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
        Schema::table('form_responses', function (Blueprint $table) {
            // Threading support
            $table->string('thread_id')->nullable()->after('form_submission_id');
            $table->boolean('is_thread_starter')->default(false)->after('thread_id');
            $table->foreignId('parent_response_id')->nullable()->constrained('form_responses')->after('is_thread_starter');

            // Email threading per client email (Message-ID, References)
            $table->string('email_message_id')->nullable()->after('email_error');
            $table->text('email_references')->nullable()->after('email_message_id');

            // Notifiche e read status
            $table->boolean('admin_notified')->default(false)->after('email_references');
            $table->timestamp('admin_notified_at')->nullable()->after('admin_notified');
            $table->boolean('user_notified')->default(false)->after('admin_notified_at');
            $table->timestamp('user_notified_at')->nullable()->after('user_notified');
            $table->boolean('user_read')->default(false)->after('user_notified_at');
            $table->timestamp('user_read_at')->nullable()->after('user_read');

            // Priorità e tag per gestione avanzata
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->after('user_read_at');
            $table->json('tags')->nullable()->after('priority');

            // Indexes per performance
            $table->index(['thread_id', 'created_at']);
            $table->index(['form_submission_id', 'is_thread_starter']);
            $table->index(['priority', 'admin_notified']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropForeign(['parent_response_id']);
            $table->dropIndex(['thread_id', 'created_at']);
            $table->dropIndex(['form_submission_id', 'is_thread_starter']);
            $table->dropIndex(['priority', 'admin_notified']);

            $table->dropColumn([
                'thread_id',
                'is_thread_starter',
                'parent_response_id',
                'email_message_id',
                'email_references',
                'admin_notified',
                'admin_notified_at',
                'user_notified',
                'user_notified_at',
                'user_read',
                'user_read_at',
                'priority',
                'tags',
            ]);
        });
    }
};
