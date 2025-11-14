<?php

namespace Tests\Unit\Services;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\CitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CitationService::class);
    }

    public function test_it_limits_citations_to_max_sources(): void
    {
        $tenant = Tenant::factory()->create();

        $docs = collect(range(1, 3))->map(function (int $index) use ($tenant) {
            return Document::create([
                'tenant_id' => $tenant->id,
                'title' => "Documento {$index}",
                'source_page_title' => "Documento {$index}",
                'source' => 'upload',
                'path' => "documents/{$index}.pdf",
                'source_url' => "https://example.com/doc-{$index}",
                'ingestion_status' => 'completed',
            ]);
        });

        $citations = $docs->map(fn (Document $doc) => [
            'document_id' => $doc->id,
            'document_source_url' => $doc->source_url,
            'chunk_text' => 'Estratto rilevante',
        ])->all();

        $result = $this->service->getCitations($citations, $tenant->id, 2);

        $this->assertCount(2, $result);
        $this->assertSame($docs[0]->id, $result[0]['source_id']);
        $this->assertSame('https://example.com/doc-1', $result[0]['page_url']);
    }

    public function test_it_enriches_document_titles_from_database(): void
    {
        $tenant = Tenant::factory()->create();

        $document = Document::create([
            'tenant_id' => $tenant->id,
            'title' => 'Delibera Consiglio Comunale (Scraped)',
            'source_page_title' => 'Delibera Consiglio Comunale',
            'source' => 'upload',
            'path' => 'documents/delibera.pdf',
            'source_url' => 'https://example.com/delibera',
            'ingestion_status' => 'completed',
        ]);

        $citations = [
            [
                'document_id' => $document->id,
                'document_source_url' => $document->source_url,
                'chunk_text' => 'Estratto con informazioni utili',
            ],
        ];

        $result = $this->service->getCitations($citations, $tenant->id);
        $citation = $result->first();

        $this->assertNotNull($citation);
        $this->assertSame($document->id, $citation['source_id']);
        $this->assertSame('Delibera Consiglio Comunale', $citation['document_title']);
        $this->assertSame('Delibera Consiglio Comunale', $citation['title']);
        $this->assertSame('https://example.com/delibera', $citation['page_url']);
    }

    public function test_it_respects_tenant_scoping_when_loading_titles(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $documentB = Document::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Documento riservato',
            'source_page_title' => 'Documento riservato',
            'source' => 'upload',
            'path' => 'documents/riservato.pdf',
            'source_url' => 'https://example.com/riservato',
            'ingestion_status' => 'completed',
        ]);

        $citations = [
            [
                'document_id' => $documentB->id,
                'document_source_url' => $documentB->source_url,
                'chunk_text' => 'Contenuto sensibile',
            ],
        ];

        $result = $this->service->getCitations($citations, $tenantA->id);
        $citation = $result->first();

        $this->assertNotNull($citation);
        $this->assertSame($documentB->id, $citation['source_id']);
        $this->assertArrayNotHasKey('document_title', $citation);
        $this->assertNull($citation['title'] ?? null);
    }

    public function test_it_uses_tenant_setting_when_limit_not_provided(): void
    {
        $tenant = Tenant::factory()->create();

        foreach (range(1, 4) as $index) {
            $document = Document::create([
                'tenant_id' => $tenant->id,
                'title' => "Doc {$index}",
                'source_page_title' => "Doc {$index}",
                'source' => 'upload',
                'path' => "documents/{$index}.pdf",
                'source_url' => "https://example.com/doc-{$index}",
                'ingestion_status' => 'completed',
            ]);

            $citations[] = [
                'document_id' => $document->id,
                'document_source_url' => $document->source_url,
            ];
        }

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenant->id, 'key' => 'widget.max_citation_sources'],
            ['value' => '3']
        );

        $result = $this->service->getCitations($citations, $tenant->id);

        $this->assertCount(3, $result);
    }

    public function test_it_throws_exception_for_invalid_max_sources(): void
    {
        $tenant = Tenant::factory()->create();

        $citations = [
            [
                'document_id' => 1,
                'document_source_url' => 'https://example.com/doc',
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->service->getCitations($citations, $tenant->id, 0);
    }
}
