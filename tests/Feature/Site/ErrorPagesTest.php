<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_pages_show_the_branded_romanian_error_page(): void
    {
        $this->get('/pagina-care-nu-exista')
            ->assertNotFound()
            ->assertSee('Lumea aceasta nu există (încă)')
            ->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/api/fiscal-agent/commands')->assertUnauthorized()->assertJson(['error' => 'unauthorized']);
    }
}
