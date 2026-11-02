<?php

namespace Tests\Feature\Site;

use App\Modules\Cms\Models\Page;
use App\Modules\Core\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningCountdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Page::query()->create([
            'key' => 'home', 'template' => 'blocks', 'slug' => ['ro' => ''], 'title' => ['ro' => 'Acasă'],
            'blocks' => ['ro' => [['type' => 'opening_countdown', 'data' => [
                'title_1' => 'O altă lume se deschide.', 'title_2' => 'Chiar vineri, 13.', 'today_title' => 'Azi deschidem.',
            ]]]],
            'published_locales' => ['ro'], 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        app(SettingsRepository::class)->set('venue.opening_date', '2026-11-13');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_countdown_shows_until_the_opening_day_and_then_disappears(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Bucharest'));
        $this->get('/')->assertOk()->assertSee('Chiar vineri, 13.')->assertSee('data-countdown="2026-11-12T22:00:00Z"', false)->assertSee('vineri, 13 noiembrie 2026')->assertSee('>10<', false);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Bucharest'));
        $this->get('/')->assertOk()->assertDontSee('Chiar vineri, 13.');
    }
}
