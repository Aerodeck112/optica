<?php

namespace Tests\Feature\Admin;

use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Livewire\Admin\LocationEditor;
use App\Livewire\Admin\ServiceEditor;
use App\Livewire\Admin\SpecialistEditor;
use App\Livewire\Admin\SpecialistIndex;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Service;
use App\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    public function test_users_without_catalog_permission_are_refused_on_page_and_component(): void
    {
        $reception = $this->staff('reception');

        $this->signIn($reception)->get('/admin/locations')->assertForbidden();
        $this->signIn($reception)->get('/admin/specialists/create')->assertForbidden();
        // Calling the component directly (as a crafted Livewire request would) is refused too.
        Livewire::actingAs($reception)->test(SpecialistEditor::class)->assertForbidden();
    }

    public function test_admin_pages_render_in_both_languages(): void
    {
        $this->seed(SiteContentSeeder::class);
        $admin = $this->staff('operations_admin', ['locale' => 'ro', 'all_locations' => true]);

        $this->signIn($admin)->get('/admin/locations')->assertOk()->assertSee('Piața Republicii nr. 5')->assertSee('Adaugă locație');
        $admin->update(['locale' => 'hu']);
        $this->signIn($admin)->get('/admin/locations')->assertOk()->assertSee('Szentgyörgy tér')->assertSee('Új telephely');
        $this->signIn($admin)->get('/admin/specialists')->assertOk()->assertSee('Még nincs szakember hozzáadva');
        $this->signIn($admin)->get('/admin/services')->assertOk()->assertSee('Réslámpavizsgálat');
    }

    public function test_specialist_create_edit_publish_and_delete(): void
    {
        Storage::fake('public');
        $location = $this->makeLocation();
        $admin = $this->staff('operations_admin', ['all_locations' => true]);

        Livewire::actingAs($admin)->test(SpecialistEditor::class)
            ->set('display_name', 'Dr. Demo Test')
            ->set('type', 'optometrist')
            ->set('languages', ['ro', 'hu'])
            ->set('content.ro.title', 'Optometrist')
            ->set('content.hu.title', 'Optometrista')
            ->set("links.{$location->id}.assigned", true)
            ->set("links.{$location->id}.online", true)
            ->set('photo', UploadedFile::fake()->image('portret.jpg', 600, 800))
            ->call('save')
            ->assertHasNoErrors();

        $specialist = Specialist::firstWhere('display_name', 'Dr. Demo Test');
        $this->assertSame('dr-demo-test', $specialist->code);
        $this->assertSame(['ro', 'hu'], $specialist->languages);
        $this->assertStringEndsWith('.webp', $specialist->photo_path);
        Storage::disk('public')->assertExists($specialist->photo_path);
        $this->assertTrue(LocationSpecialist::where(['location_id' => $location->id, 'specialist_id' => $specialist->id, 'online_enabled' => true])->exists());
        // Saved, not yet published: the public site sees nothing.
        $this->assertNull($specialist->published_content);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.specialist_created', 'subject_id' => $specialist->id]);

        Livewire::actingAs($admin)->test(SpecialistEditor::class, ['specialist' => $specialist])
            ->set('content.hu.title', 'Optometrista szakember')
            ->call('publish')
            ->assertHasNoErrors();
        $this->assertSame('Optometrista szakember', $specialist->fresh()->publicValue('title', 'hu'));

        Livewire::actingAs($admin)->test(SpecialistEditor::class, ['specialist' => $specialist->fresh()])
            ->call('delete')
            ->assertRedirect(route('admin.specialists.index'));
        $this->assertSoftDeleted($specialist);
        $this->assertFalse((bool) LocationSpecialist::where('specialist_id', $specialist->id)->value('online_enabled'));

        Livewire::actingAs($admin)->test(SpecialistIndex::class)->set('showDeleted', true)->call('restore', $specialist->id);
        $this->assertNotSoftDeleted($specialist);
    }

    public function test_photo_upload_rejects_non_images(): void
    {
        Storage::fake('public');
        $admin = $this->staff('operations_admin', ['all_locations' => true]);

        Livewire::actingAs($admin)->test(SpecialistEditor::class)
            ->set('display_name', 'Dr. Demo')
            ->set('photo', UploadedFile::fake()->create('script.php', 10, 'application/x-php'))
            ->call('save')
            ->assertHasErrors('photo');
        $this->assertSame(0, Specialist::count());
    }

    public function test_delete_is_blocked_while_future_appointments_exist(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', 'Europe/Bucharest'));
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService());
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);
        app(BookingService::class)->book($offering, ['starts_at' => CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Bucharest'), 'source' => AppointmentSource::Reception]);
        $admin = $this->staff('operations_admin', ['all_locations' => true]);

        Livewire::actingAs($admin)->test(SpecialistEditor::class, ['specialist' => $specialist])->call('delete')->assertHasErrors('delete');
        Livewire::actingAs($admin)->test(LocationEditor::class, ['location' => $location])->call('delete')->assertHasErrors('delete');
        $this->assertNotSoftDeleted($specialist);
        $this->assertNotSoftDeleted($location);
    }

    public function test_location_editor_validates_hours_and_publishes_bilingual_content(): void
    {
        $this->seed(SiteContentSeeder::class);
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = Location::firstWhere('code', 'sovata-principala-196');

        Livewire::actingAs($admin)->test(LocationEditor::class, ['location' => $location])
            ->set('hours.1.open', '18:00')->set('hours.1.close', '08:00')
            ->call('save')
            ->assertHasErrors('hours.1.close');

        Livewire::actingAs($admin)->test(LocationEditor::class, ['location' => $location])
            ->set('content.hu.description', 'Szovátai optika')
            ->set('hours.6.closed', false)->set('hours.6.open', '09:00')->set('hours.6.close', '13:00')
            ->call('publish')
            ->assertHasNoErrors();

        $location->refresh();
        $this->assertSame([['09:00', '13:00']], $location->opening_hours[6]);
        $this->assertSame('Szovátai optika', $location->publicValue('description', 'hu'));
    }

    public function test_service_create_requires_romanian_name(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);

        Livewire::actingAs($admin)->test(ServiceEditor::class)
            ->set('content.hu.name', 'Csak magyar')
            ->call('save')
            ->assertHasErrors('content.ro.name');

        Livewire::actingAs($admin)->test(ServiceEditor::class)
            ->set('content.ro.name', 'Adaptare lentile de contact')
            ->set('content.hu.name', 'Kontaktlencse-illesztés')
            ->set('default_duration', 45)
            ->call('save')
            ->assertHasNoErrors();

        $service = Service::firstWhere('code', 'adaptare-lentile-de-contact');
        $this->assertSame(45, $service->default_duration);
        $this->assertSame('Kontaktlencse-illesztés', $service->name('hu'));
    }

    public function test_site_content_seeder_is_idempotent_and_keeps_edits(): void
    {
        $this->seed(SiteContentSeeder::class);
        $this->assertSame(8, Location::count());
        $this->assertSame(12, Service::count());
        $this->assertSame(0, Specialist::count());
        $this->assertTrue(Location::firstWhere('code', 'tgm-1dec1918-49')->online_booking_enabled);

        Location::firstWhere('code', 'reghin-scolii-11')->update(['phone' => '+40 265 000 000']);
        $this->seed(SiteContentSeeder::class);

        $this->assertSame(8, Location::count());
        $this->assertSame('+40 265 000 000', Location::firstWhere('code', 'reghin-scolii-11')->phone);
    }
}
