<?php

namespace Tests\Feature\Site;

use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\EnglishContentImporter;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnglishVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_translates_pages_block_by_block_and_publishes_them_in_english(): void
    {
        Page::query()->create([
            'key' => 'home', 'template' => 'blocks', 'slug' => ['ro' => ''], 'title' => ['ro' => 'Acasă'],
            'blocks' => ['ro' => [['type' => 'hero_portal', 'data' => [
                'title_1' => 'Realitatea poate aștepta.', 'primary_label' => 'Rezervă aventura', 'primary_link' => 'route:booking', 'poster_image' => 5,
            ]]]],
            'published_locales' => ['ro'], 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        Faq::query()->create(['category' => 'general', 'question' => ['ro' => 'Cum mă îmbrac?'], 'answer' => ['ro' => 'Recomandăm haine și încălțăminte comode, în care te poți mișca ușor.'],
            'published_locales' => ['ro'], 'is_published' => true]);

        $importer = app(EnglishContentImporter::class);
        $importer->import();

        $page = Page::query()->where('key', 'home')->sole();
        $this->assertSame(['title_1' => 'Reality can wait.', 'primary_label' => 'Book your adventure', 'primary_link' => 'route:booking', 'poster_image' => 5], $page->blocks['en'][0]['data']);
        $this->assertContains('en', $page->published_locales);
        $this->assertSame('What should I wear?', Faq::query()->sole()->getTranslation('question', 'en'));
        $this->assertSame([], $importer->missing);

        $this->get('/en')->assertOk()->assertSee('Reality can wait.')->assertSee('hreflang="ro-RO"', false);
        $this->get('/')->assertOk()->assertSee('Realitatea poate aștepta.');
    }

    public function test_prices_follow_the_page_language(): void
    {
        $this->assertSame('1.990 lei', Money::format(199000));
        app()->setLocale('en');
        $this->assertSame('1,990 RON', Money::format(199000));
        $this->get('/en/voucher/order')->assertOk()->assertSee('Order a gift voucher');
    }
}
