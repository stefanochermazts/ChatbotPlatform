<?php

namespace Tests\Unit\Providers;

use App\Contracts\Chat\ChatOrchestrationServiceInterface;
use App\Contracts\Chat\ChatProfilingServiceInterface;
use App\Contracts\Chat\ContextScoringServiceInterface;
use App\Contracts\Chat\FallbackStrategyServiceInterface;
use App\Services\Chat\ChatProfilingService;
use App\Services\Chat\ContextScoringService;
use App\Services\Chat\FallbackStrategyService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

/**
 * Test service bindings for Chat services
 *
 * Verifies that all Chat service interfaces are correctly bound
 * to their concrete implementations in the Service Container.
 *
 * @group bindings
 * @group chat
 * @group providers
 */
class ChatServiceBindingsTest extends BaseTestCase
{
    use CreatesApplication;

    public function test_context_scoring_service_is_bound(): void
    {
        $service = $this->app->make(ContextScoringServiceInterface::class);

        $this->assertInstanceOf(ContextScoringService::class, $service);
    }

    public function test_fallback_strategy_service_is_bound(): void
    {
        $service = $this->app->make(FallbackStrategyServiceInterface::class);

        $this->assertInstanceOf(FallbackStrategyService::class, $service);
    }

    public function test_chat_profiling_service_is_bound(): void
    {
        $service = $this->app->make(ChatProfilingServiceInterface::class);

        $this->assertInstanceOf(ChatProfilingService::class, $service);
    }

    public function test_chat_orchestration_service_binding_exists(): void
    {
        // Note: ChatOrchestrationService not yet implemented
        // This test will pass when Step 6 is completed

        $this->assertTrue(
            $this->app->bound(ChatOrchestrationServiceInterface::class),
            'ChatOrchestrationServiceInterface should be bound in Service Container'
        );
    }

    public function test_all_chat_services_are_singletons_or_transient(): void
    {
        // Verify that services can be resolved multiple times
        // (Laravel default is transient, not singleton)

        $scoring1 = $this->app->make(ContextScoringServiceInterface::class);
        $scoring2 = $this->app->make(ContextScoringServiceInterface::class);

        // Default bind() creates new instances each time
        $this->assertNotSame($scoring1, $scoring2);
    }

    public function test_context_scoring_service_can_be_injected(): void
    {
        // Test that service can be injected via constructor
        $service = $this->app->make(ContextScoringServiceInterface::class);

        $this->assertNotNull($service);
        $this->assertTrue(method_exists($service, 'scoreCitations'));
    }

    public function test_fallback_strategy_service_can_be_injected(): void
    {
        $service = $this->app->make(FallbackStrategyServiceInterface::class);

        $this->assertNotNull($service);
        $this->assertTrue(method_exists($service, 'handleFallback'));
    }

    public function test_chat_profiling_service_can_be_injected(): void
    {
        $service = $this->app->make(ChatProfilingServiceInterface::class);

        $this->assertNotNull($service);
        $this->assertTrue(method_exists($service, 'profile'));
    }
}
