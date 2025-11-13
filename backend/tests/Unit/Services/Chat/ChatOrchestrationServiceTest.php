<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Tenant;
use App\Services\Chat\ChatOrchestrationService;
use App\Services\CitationService;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\CompleteQueryDetector;
use App\Services\RAG\ContextBuilder;
use App\Services\RAG\ConversationContextEnhancer;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\LinkConsistencyService;
use App\Services\RAG\TenantRagConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use App\Contracts\Chat\ContextScoringServiceInterface;
use App\Contracts\Chat\FallbackStrategyServiceInterface;
use App\Contracts\Chat\ChatProfilingServiceInterface;

class ChatOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_orchestrate_uses_citation_service_output(): void
    {
        config()->set('rag.scoring.enabled', false);

        $tenant = Tenant::factory()->create();

        $openAI = $this->createMock(OpenAIChatService::class);
        $kbSearch = $this->createMock(KbSearchService::class);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $conversationEnhancer = $this->createMock(ConversationContextEnhancer::class);
        $queryDetector = $this->createMock(CompleteQueryDetector::class);
        $linkConsistency = $this->createMock(LinkConsistencyService::class);
        $tenantConfig = $this->createMock(TenantRagConfigService::class);
        $scorer = $this->createMock(ContextScoringServiceInterface::class);
        /** @var FallbackStrategyServiceInterface&MockObject $fallback */
        $fallback = $this->getMockBuilder(FallbackStrategyServiceInterface::class)
            ->onlyMethods(['handleFallback'])
            ->addMethods(['cacheSuccessfulResponse'])
            ->getMock();
        $profiler = $this->createMock(ChatProfilingServiceInterface::class);
        /** @var CitationService&MockObject $citationService */
        $citationService = $this->createMock(CitationService::class);

        $retrievalCitations = [
            ['document_id' => 10, 'document_source_url' => 'https://a.example', 'title' => 'Uno'],
            ['document_id' => 11, 'document_source_url' => 'https://b.example', 'title' => 'Due'],
            ['document_id' => 12, 'document_source_url' => 'https://c.example', 'title' => 'Tre'],
        ];

        $normalized = new Collection([
            ['source_id' => 10, 'document_title' => 'Uno', 'page_url' => 'https://a.example'],
            ['source_id' => 11, 'document_title' => 'Due', 'page_url' => 'https://b.example'],
        ]);

        $queryDetector->expects($this->once())
            ->method('detectCompleteIntent')
            ->willReturn(['is_complete_query' => false]);

        $conversationEnhancer->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);

        $kbSearch->expects($this->once())
            ->method('retrieve')
            ->willReturn([
                'citations' => $retrievalCitations,
                'confidence' => 0.92,
            ]);

        $linkConsistency->expects($this->once())
            ->method('filterLinksInContext')
            ->with($retrievalCitations, $this->isType('string'))
            ->willReturn($retrievalCitations);

        $citationService->expects($this->once())
            ->method('getCitations')
            ->with($retrievalCitations, $tenant->id)
            ->willReturn($normalized);

        $contextBuilder->expects($this->once())
            ->method('build')
            ->with($normalized->toArray(), $tenant->id, $this->arrayHasKey('compression_enabled'))
            ->willReturn(['context' => '']);

        $tenantConfig->expects($this->once())
            ->method('getWidgetConfig')
            ->with($tenant->id)
            ->willReturn([]);

        $openAI->expects($this->once())
            ->method('chatCompletions')
            ->willReturn([
                'choices' => [
                    ['message' => ['content' => 'Risposta sintetica']],
                ],
                'model' => 'gpt-4o-mini',
                'usage' => [
                    'total_tokens' => 0,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                ],
            ]);

        $capturedResponse = null;

        $profiler->expects($this->any())
            ->method('profile')
            ->willReturnCallback(function (): void {});

        $scorer->expects($this->never())
            ->method('scoreCitations');

        $fallback->expects($this->never())
            ->method('handleFallback');

        $fallback->expects($this->once())
            ->method('cacheSuccessfulResponse')
            ->with(
                $this->isType('array'),
                $this->callback(function (array $response) use (&$capturedResponse) {
                    $capturedResponse = $response;
                    return true;
                })
            );

        $service = new ChatOrchestrationService(
            $openAI,
            $kbSearch,
            $contextBuilder,
            $conversationEnhancer,
            $queryDetector,
            $linkConsistency,
            $tenantConfig,
            $scorer,
            $fallback,
            $profiler,
            $citationService
        );

        $request = [
            'tenant_id' => $tenant->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Quali documenti sono disponibili?'],
            ],
            'stream' => false,
            'model' => 'gpt-4o-mini',
        ];

        $response = $service->orchestrate($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertIsArray($capturedResponse);

        $this->assertArrayHasKey('citations', $capturedResponse);
        $this->assertCount(2, $capturedResponse['citations']);
        $this->assertSame('Uno', $capturedResponse['citations'][0]['document_title']);
        $this->assertSame('https://a.example', $capturedResponse['citations'][0]['page_url']);
        $this->assertSame('Risposta sintetica', $capturedResponse['choices'][0]['message']['content']);
    }
}


