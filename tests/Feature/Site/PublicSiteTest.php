<?php

namespace Tests\Feature\Site;

use App\Http\Middleware\RemoveTrailingSlash;
use App\Livewire\Site\LeadForm;
use App\Models\Consent;
use App\Models\Lead;
use App\Models\NotFoundLog;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use Database\Seeders\CmsContentSeeder;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CmsFixtures;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use CmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        RateLimiter::clear('lead-form:127.0.0.1');
    }

    public function test_pages_render_published_content_in_both_languages_and_never_the_draft(): void
    {
        $page = $this->makePage('about', 'despre-noi', 'rolunk', ['title' => 'Despre noi', 'body' => 'Text publicat RO'], ['title' => 'Rólunk', 'body' => 'Közzétett szöveg HU']);
        $draft = $page->draft;
        $draft['sections'][0]['text']['ro']['body'] = 'CIORNA SECRETA';
        $page->update(['draft' => $draft]);

        $this->get('/despre-noi')->assertOk()->assertSee('Text publicat RO')->assertDontSee('CIORNA SECRETA')
            ->assertSee('<html lang="ro">', false)
            ->assertSee('hreflang="hu" href="'.url('/hu/rolunk').'"', false);
        $this->get('/hu/rolunk')->assertOk()->assertSee('Közzétett szöveg HU')->assertSee('<html lang="hu">', false);
    }

    public function test_unpublished_pages_are_not_found_and_404s_are_logged(): void
    {
        $this->makePage('draft-only', 'pagina-noua', 'uj-oldal', ['body' => 'x'], ['body' => 'y'], publish: false);

        $this->get('/pagina-noua')->assertNotFound();
        $this->get('/pagina-noua')->assertNotFound();
        $this->get('/nu-exista/deloc')->assertNotFound();

        $this->assertSame(2, NotFoundLog::where('path', '/pagina-noua')->value('hits'));
        $this->assertTrue(NotFoundLog::where('path', '/nu-exista/deloc')->exists());
    }

    public function test_trailing_slash_and_managed_redirects(): void
    {
        $this->makePage('services', 'servicii', 'servicii', ['body' => 'a'], ['body' => 'b']);
        Redirect::create(['from_path' => '/pagina-veche', 'to_url' => '/servicii', 'status_code' => 301]);
        Redirect::create(['from_path' => '/feed', 'to_url' => null, 'status_code' => 410]);

        // The test client strips trailing slashes, so the middleware is called directly.
        $middleware = new RemoveTrailingSlash;
        $next = fn () => response('next');
        $response = $middleware->handle(Request::create('/servicii/?utm_source=x'), $next);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(url('/servicii?utm_source=x'), $response->headers->get('Location'));
        $this->assertSame('next', $middleware->handle(Request::create('/'), $next)->getContent());
        $this->assertSame('next', $middleware->handle(Request::create('/servicii/', 'POST'), $next)->getContent());
        $this->get('/Pagina-Veche')->assertStatus(301)->assertRedirect('/servicii');
        $this->get('/feed')->assertStatus(410);
        $this->assertSame(1, Redirect::where('from_path', '/pagina-veche')->value('hits'));
        $this->assertSame(0, NotFoundLog::count());
    }

    public function test_staff_areas_are_not_swallowed_by_the_page_routes(): void
    {
        $this->get('/crm')->assertRedirect(route('crm.login'));
        $this->get('/cms/pages')->assertRedirect(route('crm.login'));
        $this->assertSame(0, NotFoundLog::count());
    }

    public function test_sitemap_lists_only_published_indexable_pages_and_robots_points_to_it(): void
    {
        $this->makePage('home', '', '', ['body' => 'h'], ['body' => 'h'], template: 'home');
        $this->makePage('contact', 'contact', 'kapcsolat', ['body' => 'c'], ['body' => 'c']);
        $this->makePage('secret', 'ascunsa', 'rejtett', ['body' => 's'], ['body' => 's'], publish: false);
        $hidden = $this->makePage('noindex', 'fara-index', 'nincs-index', ['body' => 'n'], ['body' => 'n']);
        $content = $hidden->published;
        $content['seo']['ro']['noindex'] = true;
        $hidden->update(['published' => $content]);

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(url('/contact'), false)->assertSee(url('/hu/kapcsolat'), false)
            ->assertDontSee('ascunsa')->assertDontSee('fara-index');
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('sitemap.xml'))->assertSee('Disallow: /crm');
    }

    public function test_draft_preview_requires_the_edit_permission_and_is_not_indexed(): void
    {
        $page = $this->makePage('about', 'despre-noi', 'rolunk', ['body' => 'Publicat'], ['body' => 'Publikált']);
        $draft = $page->draft;
        $draft['sections'][0]['text']['hu']['body'] = 'Piszkozat szöveg';
        $page->update(['draft' => $draft]);

        $this->get(route('site.preview', $page))->assertRedirect(route('crm.login'));
        $this->signIn($this->staff('reception'))->get(route('site.preview', $page))->assertForbidden();

        $this->signIn($this->staff('site_editor'))->get(route('site.hu.preview', $page))
            ->assertOk()->assertSee('Piszkozat szöveg')->assertSee('noindex, nofollow', false);
    }

    public function test_offers_are_visible_only_inside_their_period(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 10));
        $content = ['ro' => ['title' => 'Ofertă demo', 'summary' => '', 'body' => 'Detalii demo'], 'hu' => ['title' => 'Demo ajánlat', 'summary' => '', 'body' => '']];
        Post::create(['type' => 'offer', 'slug_ro' => 'oferta-demo', 'slug_hu' => 'demo-ajanlat', 'draft' => $content, 'published' => $content,
            'published_at' => now(), 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

        $this->get('/reduceri-actuale/oferta-demo')->assertOk()->assertSee('Detalii demo');
        $this->get('/hu/reduceri-actuale/demo-ajanlat')->assertOk()->assertSee('Demo ajánlat');

        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->get('/reduceri-actuale/oferta-demo')->assertNotFound();
    }

    public function test_contact_form_creates_one_lead_with_consent(): void
    {
        $form = Livewire::withHeaders(['referer' => url('/contact')])->test(LeadForm::class, ['type' => 'contact'])
            ->set('name', 'Pacient Demo')
            ->set('phone', '0700 000 000')
            ->set('email', 'Demo@Example.test')
            ->set('message', 'Aș dori informații despre program.')
            ->set('privacy', true)
            ->set('marketingEmail', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('sent', true);
        // A double click or a resent request must not create a second lead.
        $form->call('submit');

        $this->assertSame(1, Lead::count());
        $lead = Lead::first();
        $this->assertSame('new', $lead->status);
        $this->assertSame('demo@example.test', $lead->email);
        $this->assertTrue(Consent::where('lead_id', $lead->id)->where('purpose', 'privacy_notice')->exists());
        $this->assertTrue(Consent::where('lead_id', $lead->id)->where('purpose', 'marketing')->where('channel', 'email')->where('granted', true)->exists());
    }

    public function test_contact_form_requires_privacy_consent_and_ignores_bots(): void
    {
        Livewire::test(LeadForm::class)
            ->set('name', 'Pacient Demo')->set('phone', '0700000000')->set('message', 'Mesaj demo')
            ->call('submit')->assertHasErrors(['privacy'])->assertSet('sent', false);

        Livewire::test(LeadForm::class)
            ->set('name', 'Robot')->set('phone', '0700000000')->set('message', 'Spam spam')->set('privacy', true)
            ->set('website', 'http://spam.example')
            ->call('submit')->assertSet('sent', true);

        $this->assertSame(0, Lead::count());
    }

    public function test_the_imported_site_renders_in_both_languages(): void
    {
        $this->seed([SiteContentSeeder::class, CmsContentSeeder::class]);

        foreach (Page::all() as $page) {
            $this->get('/'.$page->slug_ro)->assertOk();
            $this->get('/hu'.($page->slug_hu === '' ? '' : '/'.$page->slug_hu))->assertOk();
        }
        foreach (\App\Models\Service::whereNotNull('published_at')->where('is_public', true)->get() as $service) {
            $this->get('/servicii/'.$service->code)->assertOk();
        }
        foreach (\App\Models\Location::whereNotNull('published_at')->where('is_public', true)->get() as $location) {
            $this->get('/hu/puncte-lucru/'.$location->code)->assertOk();
        }
        $this->get('/feed')->assertStatus(410);
        $this->assertSame(0, NotFoundLog::count());
    }
}
