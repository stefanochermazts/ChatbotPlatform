<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ingestion_progress')->default(0)->after('ingestion_status');
            $table->text('last_error')->nullable()->after('ingestion_progress');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['ingestion_progress', 'last_error']);
        });
    }
};


