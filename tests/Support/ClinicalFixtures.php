<?php

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\ClinicalTemplate;
use App\Models\Location;
use App\Models\Patient;
use App\Models\Specialist;
use App\Models\User;
use Database\Seeders\ClinicalTemplateSeeder;

/** Fictitious patients and clinical staff for tests (GEN-03). */
trait ClinicalFixtures
{
    use SchedulingFixtures;

    protected function makePatient(array $overrides = []): Patient
    {
        static $n = 0;
        $n++;

        return Patient::create($overrides + [
            'code' => 'P9'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'first_name' => 'Demo'.$n,
            'last_name' => 'Pacient',
            'phone' => '0700 000 '.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'locale' => 'ro',
        ]);
    }

    /** A user with the default "specialist" role, linked to a specialist profile, working at $location. */
    protected function doctor(Location $location, array $userAttributes = []): User
    {
        $user = $this->staff('specialist', $userAttributes, [$location->id]);
        $this->makeSpecialist(['user_id' => $user->id]);

        return $user->fresh();
    }

    protected function validatedTemplate(): ClinicalTemplate
    {
        $this->seed(ClinicalTemplateSeeder::class);
        $template = ClinicalTemplate::where('key', 'ophthalmology_general')->firstOrFail();
        $template->update(['status' => 'validated', 'validated_at' => now()]);

        return $template;
    }

    protected function appointmentFor(Patient $patient, Specialist $specialist, Location $location, string $status = 'confirmed'): Appointment
    {
        $service = $this->makeService();

        return Appointment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'patient_id' => $patient->id,
            'location_id' => $location->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'block_starts_at' => now()->addDay(),
            'block_ends_at' => now()->addDay()->addMinutes(30),
            'status' => $status,
            'source' => 'reception',
        ]);
    }

    protected function filledData(): array
    {
        return [
            'reason' => 'Control de rutină (DEMO)',
            'va_sc' => ['od' => '0,8', 'os' => '1'],
            'sph' => ['od' => '-1,25', 'os' => '+0.5'],
            'cyl' => ['od' => '-0.5', 'os' => ''],
            'axis' => ['od' => '90', 'os' => ''],
            'iop' => ['od' => '15', 'os' => '16'],
            'diagnosis' => 'Text liber DEMO scris de medic',
        ];
    }
}
