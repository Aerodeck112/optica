<?php

namespace Tests\Feature\System;

use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Page;
use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyDetailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_only_empty_company_and_venue_fields(): void
    {
        DB::table('settings')->whereIn('key', ['company.tax_id', 'venue.address_street'])->delete();
        DB::table('settings')->updateOrInsert(['key' => 'contact.phone_main'], ['value' => '+40 700 000 000', 'is_encrypted' => false]);
        DB::table('settings')->updateOrInsert(['key' => 'company.legal_name'], ['value' => '', 'is_encrypted' => false]);

        (require database_path('migrations/2026_10_24_160601_fill_company_and_venue_details.php'))->up();

        $settings = app(SettingsRepository::class);
        $settings->flush();
        $this->assertSame('54556790', $settings->get('company.tax_id'));
        $this->assertSame('VIRTUAL REALITY ARENA S.R.L.', $settings->get('company.legal_name'));
        $this->assertSame('Str. Teodor Mihali nr. 31-35', $settings->get('venue.address_street'));
        $this->assertSame('+40 700 000 000', $settings->get('contact.phone_main'));
    }

    public function test_confirmed_details_set_vat_prefix_consent_and_remove_image_notes(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'company.tax_id'], ['value' => '54556790', 'is_encrypted' => false]);
        DB::table('settings')->updateOrInsert(['key' => 'contact.email_bookings'], ['value' => 'rezervari@example.test', 'is_encrypted' => false]);
        $franchiseKids = Media::query()->create(['directory' => 'media/static', 'filename' => 'kids.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'rights_status' => 'licensed_franchise', 'depicts_minors' => true, 'static_key' => 'kids-team']);
        $uploadedKids = Media::query()->create(['directory' => 'media', 'filename' => 'upload.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'rights_status' => 'owned', 'depicts_minors' => true]);
        $block = ['type' => 'gallery_mosaic', 'data' => ['heading' => 'Galerie', 'media_note' => 'Fotografii din alte locații.']];
        $page = Page::query()->create([
            'key' => 'galerie', 'template' => 'blocks', 'slug' => ['ro' => 'galerie'], 'title' => ['ro' => 'Galerie'],
            'blocks' => ['ro' => [$block]], 'draft_blocks' => ['ro' => [$block]], 'published_locales' => ['ro'], 'status' => 'published',
        ]);

        (require database_path('migrations/2026_10_24_161743_confirm_vat_county_email_and_media_consent.php'))->up();

        $settings = app(SettingsRepository::class);
        $settings->flush();
        $this->assertSame('RO54556790', $settings->get('company.tax_id'));
        $this->assertSame('yes', $settings->get('company.vat_payer'));
        $this->assertSame('RO-MS', $settings->get('company.county_code'));
        $this->assertSame('office@anotherworldcluj.ro', $settings->get('contact.email_general'));
        $this->assertSame('rezervari@example.test', $settings->get('contact.email_bookings'));
        $this->assertTrue($franchiseKids->refresh()->isPublishable());
        $this->assertFalse($uploadedKids->refresh()->isPublishable());
        $page->refresh();
        $this->assertSame(['heading' => 'Galerie'], $page->blocks['ro'][0]['data']);
        $this->assertSame(['heading' => 'Galerie'], $page->draft_blocks['ro'][0]['data']);
    }
}
