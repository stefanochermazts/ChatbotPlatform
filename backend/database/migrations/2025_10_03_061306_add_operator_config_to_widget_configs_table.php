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
        Schema::table('widget_configs', function (Blueprint $table) {
            // Operator Configuration
            $table->boolean('operator_enabled')->default(false)->after('enable_analytics');
            $table->string('operator_button_text')->default('Operatore')->after('operator_enabled');
            $table->string('operator_button_icon')->default('headphones')->after('operator_button_text');
            $table->json('operator_availability')->nullable()->after('operator_button_icon');
            $table->text('operator_unavailable_message')->nullable()->after('operator_availability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_configs', function (Blueprint $table) {
            $table->dropColumn([
                'operator_enabled',
                'operator_button_text',
                'operator_button_icon',
                'operator_availability',
                'operator_unavailable_message',
            ]);
        });
    }
};
