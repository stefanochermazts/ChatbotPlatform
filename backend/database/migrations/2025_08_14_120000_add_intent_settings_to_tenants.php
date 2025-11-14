<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('intents_enabled')->nullable()->after('default_language');
            $table->json('extra_intent_keywords')->nullable()->after('intents_enabled');
            $table->string('kb_scope_mode')->default('relaxed')->after('extra_intent_keywords'); // relaxed|strict
            $table->float('intent_min_score')->nullable()->after('kb_scope_mode'); // 0..1
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'intent_min_score')) {
                $table->dropColumn('intent_min_score');
            }
            if (Schema::hasColumn('tenants', 'kb_scope_mode')) {
                $table->dropColumn('kb_scope_mode');
            }
            if (Schema::hasColumn('tenants', 'extra_intent_keywords')) {
                $table->dropColumn('extra_intent_keywords');
            }
            if (Schema::hasColumn('tenants', 'intents_enabled')) {
                $table->dropColumn('intents_enabled');
            }
        });
    }
};
