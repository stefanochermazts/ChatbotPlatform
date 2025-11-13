<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddSourcePageTitleToDocumentsTableTest extends TestCase
{
    use RefreshDatabase;

    public function testDocumentsTableHasSourcePageTitleColumn(): void
    {
        $this->assertTrue(Schema::hasColumn('documents', 'source_page_title'));
    }
}


