<?php

namespace Tests\Support;

use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\VatRate;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Models\OpeningHour;
use App\Modules\Core\Settings\SettingsRepository;

/**
 * Date fictive minime pentru testele motorului de rezervări.
 */
trait BookingFixtures
{
    protected function seedSchedule(string $opens = '10:00', string $closes = '22:00'): void
    {
        foreach (range(1, 7) as $day) {
            OpeningHour::query()->updateOrCreate(['weekday' => $day], ['opens_at' => $opens, 'closes_at' => $closes, 'is_closed' => false]);
        }
    }

    protected function arena(array $attributes = []): Resource
    {
        return Resource::query()->create($attributes + ['name' => 'Arena', 'type' => 'arena', 'capacity' => 10, 'is_active' => true, 'is_public' => true])->fresh();
    }

    protected function package(Resource $resource, array $attributes = []): Package
    {
        VatRate::query()->firstOrCreate(['group' => 'standard', 'valid_from' => '2025-08-01'], ['name' => 'TVA standard (test)', 'rate' => 21, 'category_code' => 'S']);

        return Package::query()->create($attributes + [
            'slug' => ['ro' => 'sesiune-test-'.uniqid()],
            'name' => ['ro' => 'Sesiune test'],
            'type' => 'session',
            'session_mode' => 'private',
            'min_participants' => 1,
            'max_participants' => 10,
            'slot_minutes' => 60,
            'play_minutes' => 45,
            'buffer_after_minutes' => 15,
            'pricing_model' => 'per_person',
            'price_minor' => 5000,
            'deposit_type' => 'none',
            'booking_mode' => 'instant',
            'resource_ids' => [$resource->id],
            'is_active' => true,
            'published_locales' => ['ro'],
        ])->fresh();
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingsRepository::class)->set($key, $value);
    }

    protected function publishPolicies(): void
    {
        foreach (['terms', 'booking_policy', 'minors'] as $type) {
            LegalDocument::query()->create([
                'type' => $type,
                'version' => '1.0',
                'title' => ['ro' => $type],
                'body' => ['ro' => 'Text de test'],
                'slug' => ['ro' => $type],
                'status' => 'published',
                'effective_at' => now()->subDay(),
            ]);
        }
    }
}
