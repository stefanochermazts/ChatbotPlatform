<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scraper_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('seed_urls')->nullable();
            $table->json('allowed_domains')->nullable();
            $table->integer('max_depth')->default(2);
            $table->boolean('render_js')->default(true); // headless rendering
            $table->json('auth_headers')->nullable();
            $table->integer('rate_limit_rps')->default(1);
            $table->json('sitemap_urls')->nullable();
            $table->json('include_patterns')->nullable();
            $table->json('exclude_patterns')->nullable();
            $table->boolean('respect_robots')->default(true);
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_configs');
    }
};





