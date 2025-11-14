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
        Schema::table('tenant_forms', function (Blueprint $table) {
            // Rinomina campi per coerenza con template email
            $table->renameColumn('email_template_subject', 'user_confirmation_email_subject');
            $table->renameColumn('email_template_body', 'user_confirmation_email_body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_forms', function (Blueprint $table) {
            // Ripristina nomi originali
            $table->renameColumn('user_confirmation_email_subject', 'email_template_subject');
            $table->renameColumn('user_confirmation_email_body', 'email_template_body');
        });
    }
};
