<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    /** Pe găzduirea cu short_open_tag activ, un „<?xml” scris în Blade strica pagina; declarația vine din controller. */
    public function test_sitemap_is_valid_xml_with_declaration(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>'."\n<urlset", $response->getContent());
        $this->assertNotFalse(simplexml_load_string($response->getContent()));
        $this->assertStringNotContainsString('<?xml', file_get_contents(resource_path('views/site/sitemap.blade.php')));
    }
}
