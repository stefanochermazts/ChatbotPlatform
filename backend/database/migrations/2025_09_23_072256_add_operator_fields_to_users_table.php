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
        Schema::table('users', function (Blueprint $table) {
            // 🏷️ User Type & Role
            $table->enum('user_type', [
                'admin',           // Admin piattaforma
                'tenant_admin',    // Admin del tenant
                'operator',        // Operatore supporto clienti
                'user',            // Utente normale (se necessario per multi-user tenant)
            ])->default('user')->after('email');

            // 👨‍💼 Operator Specific Fields
            $table->boolean('is_operator')->default(false)->after('user_type');
            $table->enum('operator_status', [
                'offline',         // Non disponibile
                'available',       // Disponibile per nuove conversazioni
                'busy',           // Occupato ma può ricevere urgenti
                'away',           // Temporaneamente assente
                'do_not_disturb',  // Non disturbare
            ])->nullable()->after('is_operator');

            // 🎯 Operator Capabilities & Skills
            $table->json('operator_skills')->nullable()->after('operator_status'); // Competenze (e.g., ["italian", "tech_support", "billing"])
            $table->json('operator_permissions')->nullable()->after('operator_skills'); // Permessi granulari
            $table->unsignedInteger('max_concurrent_conversations')->default(5)->after('operator_permissions');
            $table->unsignedInteger('current_conversations')->default(0)->after('max_concurrent_conversations');

            // ⏰ Availability & Schedule
            $table->json('work_schedule')->nullable()->after('current_conversations'); // Orari lavoro JSON
            $table->string('timezone', 50)->nullable()->after('work_schedule'); // Timezone operatore
            $table->timestamp('last_seen_at')->nullable()->after('timezone');
            $table->timestamp('status_updated_at')->nullable()->after('last_seen_at');

            // 📊 Operator Metrics
            $table->unsignedInteger('total_conversations_handled')->default(0)->after('status_updated_at');
            $table->decimal('average_response_time_minutes', 8, 2)->nullable()->after('total_conversations_handled');
            $table->decimal('average_resolution_time_minutes', 8, 2)->nullable()->after('average_response_time_minutes');
            $table->decimal('customer_satisfaction_avg', 3, 2)->nullable()->after('average_resolution_time_minutes'); // 1.00 - 5.00

            // 🎨 UI/UX Preferences
            $table->json('console_preferences')->nullable()->after('customer_satisfaction_avg'); // Tema, layout, notifiche
            $table->json('notification_settings')->nullable()->after('console_preferences'); // Email, push, sound, etc.

            // 🔧 Technical Data
            $table->json('operator_metadata')->nullable()->after('notification_settings'); // Dati custom, note, etc.

            // 🔑 Indexes per performance
            $table->index(['is_operator', 'operator_status']);
            $table->index(['user_type']);
            $table->index(['operator_status']);
            $table->index(['last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['is_operator', 'operator_status']);
            $table->dropIndex(['user_type']);
            $table->dropIndex(['operator_status']);
            $table->dropIndex(['last_seen_at']);

            // Drop columns
            $table->dropColumn([
                'user_type',
                'is_operator',
                'operator_status',
                'operator_skills',
                'operator_permissions',
                'max_concurrent_conversations',
                'current_conversations',
                'work_schedule',
                'timezone',
                'last_seen_at',
                'status_updated_at',
                'total_conversations_handled',
                'average_response_time_minutes',
                'average_resolution_time_minutes',
                'customer_satisfaction_avg',
                'console_preferences',
                'notification_settings',
                'operator_metadata',
            ]);
        });
    }
};
