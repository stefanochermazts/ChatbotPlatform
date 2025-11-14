<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('custom_system_prompt')->nullable()->after('default_language');
            $table->text('custom_context_template')->nullable()->after('custom_system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['custom_system_prompt', 'custom_context_template']);
        });
    }
};
