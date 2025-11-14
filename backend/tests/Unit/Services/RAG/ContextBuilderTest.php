<?php

namespace Tests\Unit\Services\RAG;

use App\Models\Tenant;
use App\Models\WidgetConfig;
use App\Services\LLM\OpenAIChatService;
use App\Services\RAG\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_snippets_are_prioritized_and_formatted(): void
    {
        $tenant = Tenant::factory()->create();

        WidgetConfig::create([
            'tenant_id' => $tenant->id,
            'source_link_text' => 'Fonte',
        ]);

        $chat = Mockery::mock(OpenAIChatService::class);
        $builder = new ContextBuilder($chat);

        $citations = [
            [
                'title' => 'Consiglieri comunali',
                'chunk_text' => <<<'MD'
| Nome | Ruolo |
| --- | --- |
| Mario Rossi | Presidente |
| Anna Bianchi | Consigliere |
MD,
                'document_source_url' => 'https://example.com/consiglieri',
            ],
            [
                'title' => 'Altro Documento',
                'chunk_text' => 'Contenuto testuale generico',
            ],
        ];

        $result = $builder->build($citations, $tenant->id);

        $context = $result['context'];

        $this->assertStringContainsString('**Table (alta priorità)**', $context);
        $this->assertStringContainsString('| Nome | Ruolo |', $context);
        $this->assertStringContainsString('Tabella (testo lineare di supporto):', $context);
        $this->assertStringContainsString('Contenuto testuale generico', $context);

        $tablePos = strpos($context, '**Table (alta priorità)**');
        $textPos = strpos($context, 'Contenuto testuale generico');

        $this->assertNotFalse($tablePos);
        $this->assertNotFalse($textPos);
        $this->assertLessThan($textPos, $tablePos);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
