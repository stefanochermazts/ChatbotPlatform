<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Colonna su documents per associare la KB
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('knowledge_base_id')->nullable()->after('tenant_id')->constrained('knowledge_bases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('knowledge_base_id');
        });
        Schema::dropIfExists('knowledge_bases');
    }
};


