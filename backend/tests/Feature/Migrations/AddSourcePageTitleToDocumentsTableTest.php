<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddSourcePageTitleToDocumentsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_table_has_source_page_title_column(): void
    {
        $this->assertTrue(Schema::hasColumn('documents', 'source_page_title'));
    }
}
