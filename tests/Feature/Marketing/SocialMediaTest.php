<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Cms\Models\Media;
use App\Modules\Marketing\Models\SocialPost;
use App\Modules\Marketing\Services\Social\SocialPlanGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;
use ZipArchive;

class SocialMediaTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());

        return $user;
    }

    private function photo(string $key): Media
    {
        return Media::query()->where('static_key', $key)->firstOrFail();
    }

    public function test_week_plan_shows_posts_and_images_download_in_network_formats(): void
    {
        $admin = $this->user('super_admin');
        $landscape = $this->photo('social-doi-prieteni-in-joc');
        $post = SocialPost::query()->create([
            'publish_on' => CarbonImmutable::now('Europe/Bucharest')->toDateString(), 'publish_time' => '18:00', 'channels' => ['instagram'],
            'format' => 'post', 'theme' => 'friends', 'title' => 'Postare de test', 'caption' => 'Text de test', 'hashtags' => '#AnotherWorldCluj',
            'media_ids' => [$landscape->id], 'status' => 'planned',
        ]);

        $this->actingAs($admin)->get('/admin/social-week')->assertOk()->assertSee('Postare de test')->assertSee('Copiază textul');

        $portrait = $this->actingAs($admin)->get(route('admin.social.image', [$landscape, 'portrait']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring($portrait->getContent()), 0, 2));
        $story = $this->actingAs($admin)->get(route('admin.social.image', [$landscape, 'story']))->assertOk();
        $this->assertSame([1080, 1920], array_slice(getimagesizefromstring($story->getContent()), 0, 2));

        $bundle = $this->actingAs($admin)->get(route('admin.social.bundle', $post))->assertOk();
        $zip = new ZipArchive;
        $zip->open($bundle->getFile()->getPathname());
        $this->assertSame("Text de test\n\n#AnotherWorldCluj\n", $zip->getFromName('text.txt'));
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
    }

    public function test_staff_without_social_permission_cannot_open_or_download(): void
    {
        $operator = $this->user('operator');
        $media = $this->photo('social-doi-prieteni-in-joc');

        $this->actingAs($operator)->get('/admin/social-week')->assertForbidden();
        $this->actingAs($operator)->get(route('admin.social.image', [$media, 'portrait']))->assertForbidden();
    }

    public function test_images_with_minors_without_consent_are_not_served(): void
    {
        $admin = $this->user('marketing');
        $media = $this->photo('social-copil-casca-03');
        $media->update(['guardian_consent_confirmed' => false]);

        $this->actingAs($admin)->get(route('admin.social.image', [$media, 'portrait']))->assertNotFound();
    }

    public function test_generator_fills_only_free_days_without_repeating_images(): void
    {
        $monday = CarbonImmutable::parse('2026-12-07');
        SocialPost::query()->create([
            'publish_on' => '2026-12-08', 'channels' => ['instagram'], 'format' => 'post', 'theme' => 'friends',
            'title' => 'Existentă', 'caption' => 'Text', 'media_ids' => [], 'status' => 'planned',
        ]);

        $created = app(SocialPlanGenerator::class)->generateWeek($monday);

        $this->assertCount(6, $created);
        $this->assertFalse($created->contains(fn (SocialPost $p) => $p->publish_on->toDateString() === '2026-12-08'));
        $images = $created->pluck('media_ids')->flatten();
        $this->assertSame($images->count(), $images->unique()->count());
        $this->assertTrue($created->every(fn (SocialPost $p) => $p->status === 'planned' && $p->caption !== '' && $p->media_ids !== []));
        $this->assertCount(0, app(SocialPlanGenerator::class)->generateWeek($monday));
    }
}
