<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CmsFixtures;
use Tests\TestCase;

/** test.optica-optofarm.ro: never indexed, visibly a test, no demo accounts with a known password. */
class TestSiteTest extends TestCase
{
    use CmsFixtures, RefreshDatabase;

    public function test_a_test_installation_is_not_indexed_and_says_so_on_every_page(): void
    {
        $this->makePage('about', 'despre-noi', 'rolunk', ['title' => 'Despre noi', 'body' => 'Text'], ['title' => 'Rólunk', 'body' => 'Szöveg']);

        $this->get('/despre-noi')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertDontSee(__('site.test_site'));
        $this->get('/robots.txt')->assertDontSee("Disallow: /\n", false);

        config(['optica.test_site' => true]);
        $this->get('/despre-noi')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertSee(__('site.test_site'));
        $this->get('/robots.txt')->assertSeeText("User-agent: *\nDisallow: /", false);
        $this->get('/hu/rolunk')->assertSee(__('site.test_site', [], 'hu'));
        $this->signIn($this->staff('reception'))->get(route('crm.dashboard'))->assertSee(__('crm.test_site'));
    }

    public function test_demo_data_on_a_test_installation_creates_no_login_accounts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SiteContentSeeder::class);
        config(['optica.test_site' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        app(DemoDataSeeder::class)->__invoke();

        $this->assertSame(0, User::where('email', 'like', 'demo.%')->count());
        $this->assertDatabaseHas('patients', ['last_name' => 'Demo']);
    }

    public function test_demo_data_is_refused_on_a_live_installation(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(DemoDataSeeder::class)->__invoke();
    }
}
