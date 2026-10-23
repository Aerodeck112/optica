<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Booking\Models\Resource;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Models\Media;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Models\OpeningHour;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Services\LeadService;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Date DEMONSTRATIVE, complet fictive (clienți, prețuri, program). Nu rulează în producție.
 * Prețurile și programul de aici NU sunt ale arenei din Cluj.
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo-AW-2026!';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder nu poate rula în producție.');
        }

        $settings = app(SettingsRepository::class);
        $settings->setMany([
            'booking.auto_confirm' => true,
            'booking.free_cancellation_hours' => 24,
            'booking.free_reschedule_hours' => 24,
            'booking.max_reschedules' => 1,
            'booking.min_lead_minutes' => 60,
            'contact.phone_main' => '+40 700 000 000',
            'contact.email_general' => 'contact@example.test',
            'contact.email_bookings' => 'rezervari@example.test',
            'contact.email_privacy' => 'gdpr@example.test',
            'contact.notifications_staff' => 'echipa@example.test',
            'company.legal_name' => 'DEMO SRL (date fictive)',
            'loyalty.enabled' => true,
        ]);

        foreach (range(1, 7) as $day) {
            OpeningHour::query()->updateOrCreate(['weekday' => $day], ['opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => $day === 1]);
        }

        $arena = Resource::query()->where('type', 'arena')->first();
        $arena->update(['capacity' => 10]);

        foreach (['kernel-bunker', 'kernel-raven', 'shmooter-cubes', 'shmooter-forts', 'shmooter-space-battle-2', 'ghost-tower-conacul-fantomelor', 'ghost-tower-dance', 'ghost-tower-fishing', 'mysterious-tower-miners', 'starbase-expedition', 'taverna', 'safe-night'] as $i => $slug) {
            $game = Game::query()->where('slug->ro', $slug)->first();
            if (! $game) {
                continue;
            }
            $ref = collect($game->reference_data['sources'] ?? [])->first();
            [$min, $max] = array_map('intval', explode('–', str_replace('-', '–', $ref['players'] ?? '2–10')) + [1 => 10]);
            $game->update([
                'cluj_status' => 'available',
                'min_age' => (int) rtrim($ref['age'] ?? '12+', '+'),
                'min_players' => $min,
                'max_players' => min($max, 10),
                'play_minutes' => 45,
                'intensity' => $game->reference_data['suggested_intensity'] ?? 2,
                'is_featured' => $i < 6,
            ]);
        }

        $session = Package::query()->updateOrCreate(['type' => 'session', 'slot_minutes' => 60], [
            'slug' => ['ro' => 'sesiune-vr'],
            'name' => ['ro' => 'Sesiune VR pentru grup'],
            'summary' => ['ro' => 'Arena rezervată pentru grupul tău. [DEMO — preț fictiv]'],
            'included' => ['ro' => [['text' => 'Instructaj și echipare'], ['text' => 'Unul sau mai multe jocuri, în limita timpului de joc']]],
            'session_mode' => 'private', 'min_participants' => 2, 'max_participants' => 10,
            'play_minutes' => 45, 'buffer_after_minutes' => 15,
            'pricing_model' => 'per_person', 'price_minor' => 6000, 'deposit_type' => 'none',
            'booking_mode' => 'instant', 'resource_ids' => [$arena->id],
            'is_active' => true, 'is_featured' => true, 'published_locales' => ['ro'], 'sort' => 1,
            'cover_id' => Media::query()->where('static_key', 'group-arena')->value('id'),
        ]);
        $session->games()->sync(Game::query()->where('cluj_status', 'available')->pluck('id'));

        Package::query()->updateOrCreate(['type' => 'birthday'], [
            'slug' => ['ro' => 'aniversare-vr'],
            'name' => ['ro' => 'Aniversare VR'],
            'summary' => ['ro' => 'Pachet pentru aniversări, confirmat de echipă. [DEMO]'],
            'session_mode' => 'private', 'min_participants' => 6, 'max_participants' => 10,
            'slot_minutes' => 90, 'play_minutes' => 60, 'buffer_after_minutes' => 15,
            'pricing_model' => 'flat_plus_extra', 'price_minor' => 70000, 'included_participants' => 8, 'extra_person_price_minor' => 7000,
            'deposit_type' => 'percent', 'deposit_value' => 3000,
            'booking_mode' => 'request', 'resource_ids' => [$arena->id],
            'is_active' => true, 'published_locales' => ['ro'], 'sort' => 2,
        ]);
        Package::query()->updateOrCreate(['type' => 'event'], [
            'slug' => ['ro' => 'team-building'],
            'name' => ['ro' => 'Team building'],
            'summary' => ['ro' => 'Propunere personalizată pentru echipe. [DEMO]'],
            'session_mode' => 'private', 'min_participants' => 6, 'max_participants' => 40,
            'pricing_model' => 'per_person', 'price_minor' => null,
            'booking_mode' => 'request', 'resource_ids' => [$arena->id],
            'is_active' => true, 'published_locales' => ['ro'], 'sort' => 3,
        ]);
        $addon = Addon::query()->updateOrCreate(['extra_minutes' => 30], ['name' => ['ro' => 'Prelungire 30 minute [DEMO]'], 'pricing_model' => 'per_person', 'price_minor' => 2500, 'is_active' => true]);
        $session->addons()->sync([$addon->id]);

        LegalDocument::query()->update(['status' => 'published', 'effective_at' => now()->subDay()]);
        Integration::query()->where('key', 'email')->update(['mode' => 'sandbox', 'status' => 'sandbox_tested', 'driver' => 'smtp']);

        foreach (['super_admin', 'manager', 'reception', 'operator', 'marketing', 'accountant', 'editor'] as $role) {
            $user = User::query()->updateOrCreate(['email' => "{$role}@example.test"], [
                'name' => 'Demo '.ucfirst(str_replace('_', ' ', $role)),
                'password' => self::DEMO_PASSWORD,
                'is_active' => true,
            ]);
            $user->syncRoles([$role]);
        }

        $service = app(BookingService::class);
        $names = ['Andrei Pop', 'Ioana Rus', 'Mihai Stan', 'Elena Ilie', 'Radu Marin', 'Ana Toma', 'Vlad Dinu', 'Irina Lazăr'];
        foreach ($names as $i => $name) {
            $date = LocalTime::now()->addDays(($i % 5) + 1)->toDateString();
            $time = ['12:00', '14:00', '16:00', '18:00', '20:00'][$i % 5];
            try {
                $service->create(new BookingData(
                    package: $session,
                    startsAt: LocalTime::fromLocal($date, $time),
                    participants: 2 + $i % 6,
                    contactName: $name,
                    contactEmail: 'client'.($i + 1).'@example.test',
                    contactPhone: '07000000'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT),
                    source: $i % 3 === 0 ? 'reception' : 'web',
                    acceptedPolicyTypes: ['terms', 'booking_policy'],
                    ageGroups: ['adults' => 2 + $i % 6],
                    marketingConsent: ['email' => $i % 2 === 0],
                    consentContext: ['form' => 'demo'],
                ));
            } catch (\App\Modules\Booking\Services\BookingException $e) {
                $this->command?->warn("Rezervare demo omisă ({$date} {$time}): {$e->getMessage()}");
            }
        }

        $leads = app(LeadService::class);
        foreach ([['birthday', 'Corina Vlad', 12], ['corporate', 'Paul Neagu', 24], ['private', 'Dana Mureșan', 15]] as [$type, $name, $participants]) {
            $leads->create([
                'type' => $type,
                'contact_name' => $name,
                'contact_email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
                'contact_phone' => '0700000099',
                'company_name' => $type === 'corporate' ? 'Firma Demo SRL' : null,
                'participants' => $participants,
                'preferred_date' => LocalTime::now()->addDays(14)->toDateString(),
                'preferred_time' => 'afternoon',
                'message' => 'Cerere demonstrativă.',
            ], 'web');
        }
    }
}
