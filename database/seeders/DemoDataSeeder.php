<?php

namespace Database\Seeders;

use App\Domain\Patients\PatientRegistry;
use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Resource;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Specialist;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fictitious data for demonstrations and screenshots only (ARC-08, GEN-03, SEC-07).
 * Every name carries "DEMO"; it refuses to run in production.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is never seeded in production.');
        }

        $location = Location::firstWhere('code', 'tgm-postei-3') ?? throw new RuntimeException('Run SiteContentSeeder first.');
        $tz = config('optica.clinic_timezone');
        Resource::firstOrCreate(['location_id' => $location->id, 'type' => 'oct'], ['name' => ['ro' => 'OCT (DEMO)', 'hu' => 'OCT (DEMO)']]);

        $doctors = [
            ['DEMO-1', 'Dr. Ana Exemplu (DEMO)', 'ophthalmologist', ['ro', 'hu']],
            ['DEMO-2', 'Bence Minta (DEMO)', 'optometrist', ['hu', 'ro']],
        ];
        $services = Service::orderBy('sort_order')->take(4)->get();

        foreach ($doctors as $i => [$code, $name, $type, $langs]) {
            $specialist = Specialist::firstOrCreate(['code' => $code], [
                'display_name' => $name, 'type' => $type, 'languages' => $langs, 'is_active' => true, 'is_public' => true,
                'content' => ['ro' => ['title' => 'Profil demonstrativ'], 'hu' => ['title' => 'Bemutató profil']],
            ]);
            $specialist->published_content = $specialist->content;
            $specialist->published_at = now();
            $specialist->save();
            LocationSpecialist::firstOrCreate(['location_id' => $location->id, 'specialist_id' => $specialist->id], ['is_active' => true, 'online_enabled' => true]);
            foreach ($services as $j => $service) {
                Offering::firstOrCreate(
                    ['location_id' => $location->id, 'specialist_id' => $specialist->id, 'service_id' => $service->id],
                    ['online_enabled' => true, 'is_active' => true, 'capacity' => 1, 'resource_type' => $j === 3 ? 'oct' : null],
                );
            }
            if (! Schedule::where(['specialist_id' => $specialist->id, 'location_id' => $location->id])->exists()) {
                $schedule = Schedule::create([
                    'specialist_id' => $specialist->id, 'location_id' => $location->id, 'name' => $name,
                    'valid_from' => now($tz)->startOfYear()->toDateString(), 'cycle_weeks' => 1,
                    'cycle_anchor_date' => now($tz)->startOfWeek()->toDateString(),
                ]);
                foreach (range(1, 5) as $day) {
                    $schedule->segments()->create(['weekday' => $day, 'segment' => 'before_noon', 'start_time' => $i ? '10:00' : '09:00', 'end_time' => '13:00', 'slot_minutes' => 15, 'online_enabled' => true]);
                    $schedule->segments()->create(['weekday' => $day, 'segment' => 'afternoon', 'start_time' => '14:00', 'end_time' => $i ? '18:00' : '17:00', 'slot_minutes' => 15, 'online_enabled' => $day !== 5]);
                }
            }
        }

        $registry = app(PatientRegistry::class);
        $booking = app(BookingService::class);
        $offerings = Offering::with('service')->where('location_id', $location->id)->whereIn('specialist_id', Specialist::where('code', 'like', 'DEMO-%')->pluck('id'))->get();
        $start = CarbonImmutable::now($tz)->startOfDay();
        $plan = [[0, '09:00', 0], [0, '09:30', 1], [0, '10:30', 2], [1, '10:00', 0], [1, '11:15', 1], [0, '14:00', 3], [1, '15:30', 2], [0, '11:00', 1]];
        $workdays = [];
        for ($d = $start; count($workdays) < 2; $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $workdays[] = $d;
            }
        }
        foreach ($workdays as $offsetDay => $date) {
            foreach ($plan as $k => [$doctor, $time, $svc]) {
                $offering = $offerings->where('specialist_id', Specialist::firstWhere('code', 'DEMO-'.($doctor + 1))->id)->values()[$svc] ?? null;
                if (! $offering) {
                    continue;
                }
                $patient = $registry->create(['last_name' => 'Demo', 'first_name' => 'Pacient '.($offsetDay * 10 + $k + 1), 'phone' => '0700 000 '.str_pad((string) ($offsetDay * 10 + $k), 3, '0', STR_PAD_LEFT), 'locale' => $k % 3 === 0 ? 'hu' : 'ro'], null, $location->id);
                try {
                    $a = $booking->book($offering, [
                        'starts_at' => CarbonImmutable::parse($date->toDateString().' '.$time, $tz),
                        'source' => $k % 4 === 0 ? AppointmentSource::Online : AppointmentSource::Reception,
                        'status' => $k % 4 === 0 ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
                        'patient_id' => $patient->id, 'locale' => $patient->locale,
                        'allow_outside_schedule' => true,
                    ]);
                    if ($offsetDay === 0 && $k === 0) {
                        $booking->changeStatus($a, AppointmentStatus::Confirmed);
                        $booking->changeStatus($a->fresh(), AppointmentStatus::Arrived);
                    }
                } catch (BookingException) {
                    // Slot already taken in an earlier run.
                }
            }
        }

        $admin = User::firstOrCreate(['email' => 'demo.admin@example.test'], ['name' => 'Admin DEMO', 'password' => 'DemoParola2026', 'all_locations' => true]);
        $admin->roles()->syncWithoutDetaching([Role::where('key', 'operations_admin')->value('id')]);
        $reception = User::firstOrCreate(['email' => 'demo.receptie@example.test'], ['name' => 'Recepție DEMO', 'password' => 'DemoParola2026', 'locale' => 'ro']);
        $reception->roles()->syncWithoutDetaching([Role::where('key', 'reception')->value('id')]);
        $reception->locations()->syncWithoutDetaching([$location->id]);
        $editor = User::firstOrCreate(['email' => 'demo.site@example.test'], ['name' => 'Editor site DEMO', 'password' => 'DemoParola2026', 'locale' => 'hu']);
        $editor->roles()->syncWithoutDetaching([Role::where('key', 'site_editor')->value('id')]);
    }
}
