<?php

namespace Tests\Unit\Services\Ingestion;

use App\Services\Ingestion\TextParsingService;
use PHPUnit\Framework\TestCase;

class TextParsingServiceTest extends TestCase
{
    private TextParsingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TextParsingService;
    }

    public function test_flatten_markdown_tables_converts_rows_to_plain_text(): void
    {
        $markdown = <<<'MD'
| Nominativo | Ruolo | Organo | Gruppo |
| --- | --- | --- | --- |
| Mario Rossi | Presidente | Consiglio | Lista Civica |
| Anna Bianchi | Consigliere | Consiglio | Lista Civica |
MD;

        $flattened = $this->service->flattenMarkdownTables($markdown);

        $expected = "Nominativo: Mario Rossi; Ruolo: Presidente; Organo: Consiglio; Gruppo: Lista Civica\n".
            'Nominativo: Anna Bianchi; Ruolo: Consigliere; Organo: Consiglio; Gruppo: Lista Civica';

        $this->assertSame($expected, $flattened);
    }

    public function test_flatten_markdown_tables_returns_original_when_not_a_table(): void
    {
        $text = 'Nessuna tabella qui.';

        $this->assertSame($text, $this->service->flattenMarkdownTables($text));
    }
}
