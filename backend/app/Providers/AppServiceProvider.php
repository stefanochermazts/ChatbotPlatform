<?php

namespace App\Providers;

use App\Listeners\SendUserInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 📦 Register Ingestion Service Interfaces
        // These services handle the document ingestion pipeline
        $this->app->bind(
            \App\Contracts\Ingestion\DocumentExtractionServiceInterface::class,
            \App\Services\Ingestion\DocumentExtractionService::class
        );

        $this->app->bind(
            \App\Contracts\Ingestion\TextParsingServiceInterface::class,
            \App\Services\Ingestion\TextParsingService::class
        );

        $this->app->bind(
            \App\Contracts\Ingestion\ChunkingServiceInterface::class,
            \App\Services\Ingestion\ChunkingService::class
        );

        $this->app->bind(
            \App\Contracts\Ingestion\EmbeddingBatchServiceInterface::class,
            \App\Services\Ingestion\EmbeddingBatchService::class
        );

        $this->app->bind(
            \App\Contracts\Ingestion\VectorIndexingServiceInterface::class,
            \App\Services\Ingestion\VectorIndexingService::class
        );

        // 💬 Register Chat Service Interfaces
        // These services handle RAG pipeline and chat orchestration
        $this->app->bind(
            \App\Contracts\Chat\ChatOrchestrationServiceInterface::class,
            \App\Services\Chat\ChatOrchestrationService::class
        );

        $this->app->bind(
            \App\Contracts\Chat\ContextScoringServiceInterface::class,
            \App\Services\Chat\ContextScoringService::class
        );

        $this->app->bind(
            \App\Contracts\Chat\FallbackStrategyServiceInterface::class,
            \App\Services\Chat\FallbackStrategyService::class
        );

        $this->app->bind(
            \App\Contracts\Chat\ChatProfilingServiceInterface::class,
            \App\Services\Chat\ChatProfilingService::class
        );

        // 📄 Register Document Service Interfaces
        // These services handle document management and admin operations
        $this->app->bind(
            \App\Contracts\Document\DocumentCrudServiceInterface::class,
            \App\Services\Document\DocumentCrudService::class
        );

        $this->app->bind(
            \App\Contracts\Document\DocumentFilterServiceInterface::class,
            \App\Services\Document\DocumentFilterService::class
        );

        $this->app->bind(
            \App\Contracts\Document\DocumentUploadServiceInterface::class,
            \App\Services\Document\DocumentUploadService::class
        );

        $this->app->bind(
            \App\Contracts\Document\DocumentStorageServiceInterface::class,
            \App\Services\Document\DocumentStorageService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        // Registra event listeners
        Event::listen(
            Registered::class,
            SendUserInvitation::class,
        );

        // Registra policies
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);

        // ✅ FIX BUG 4: Registra Observer per invalidare cache RAG config
        Tenant::observe(\App\Observers\TenantObserver::class);
        
        // ✅ FIX: Registra Observer per sincronizzare delete con Milvus
        \App\Models\Document::observe(\App\Observers\DocumentObserver::class);
    }
}
