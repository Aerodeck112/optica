<?php

namespace App\Domain\Import;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Appointments from an export (MIG-01, MIG-03, MIG-05). Patients are found by the reference of
 * the patient import, so the relation is kept. Location, specialist and service must exist in
 * the platform; a missing one is reported, never invented. Imported appointments send no
 * messages, unless chosen for future ones, so no reminder goes out retroactively.
 */
class AppointmentImport extends ImportTarget
{
    private const STATUS_WORDS = [
        'completed' => ['finalizat', 'finalizata', 'efectuat', 'efectuata', 'realizat', 'prezent', 'completed', 'done', 'teljesitve', 'megtortent'],
        'cancelled' => ['anulat', 'anulata', 'cancelled', 'canceled', 'lemondva', 'torolve'],
        'no_show' => ['neprezentat', 'absent', 'no show', 'no_show', 'nem jelent meg'],
        'confirmed' => ['confirmat', 'confirmata', 'confirmed', 'programat', 'programata', 'visszaigazolva'],
        'pending' => ['in asteptare', 'neconfirmat', 'pending', 'fuggoben'],
    ];

    /** @var array<string, array<string, int>> */
    private array $lookups = [];

    public function fields(): array
    {
        return [
            'external_ref' => ['required' => true, 'synonyms' => ['id', 'id programare', 'cod programare', 'appointment id', 'idopont azonosito']],
            'patient_ref' => ['required' => true, 'synonyms' => ['id pacient', 'cod pacient', 'patient id', 'paciens azonosito']],
            'location' => ['required' => true, 'synonyms' => ['locatie', 'punct de lucru', 'clinica', 'helyszin']],
            'specialist' => ['required' => true, 'synonyms' => ['medic', 'doctor', 'specialist', 'optometrist', 'orvos']],
            'service' => ['required' => true, 'synonyms' => ['serviciu', 'tip consultatie', 'consultatie', 'szolgaltatas']],
            'date' => ['required' => true, 'synonyms' => ['data', 'data programare', 'datum', 'ziua']],
            'time' => ['synonyms' => ['ora', 'ora programare', 'idopont', 'ora inceput']],
            'duration' => ['synonyms' => ['durata', 'minute', 'idotartam']],
            'status' => ['synonyms' => ['stare', 'statut', 'allapot']],
        ];
    }

    public function process(array $values, ImportContext $context, bool $dryRun): array
    {
        $messages = [];
        $refRaw = self::clean($values['external_ref'] ?? '');
        if ($refRaw === '') {
            return ['outcome' => 'error', 'messages' => ['external_ref_missing']];
        }
        $ref = $context->ref($refRaw);
        if (isset($context->seen[$ref])) {
            return ['outcome' => 'duplicate', 'messages' => ['repeated_in_file']];
        }
        $context->seen[$ref] = true;
        if (Appointment::where('external_ref', $ref)->exists()) {
            return ['outcome' => 'unchanged', 'messages' => []];
        }

        $errors = [];
        $patientRef = self::clean($values['patient_ref'] ?? '');
        $patientId = $patientRef !== '' ? Patient::where('external_ref', $context->ref($patientRef))->value('id') : null;
        $locationId = $this->find('location', self::clean($values['location'] ?? ''));
        $specialistId = $this->find('specialist', self::clean($values['specialist'] ?? ''));
        $serviceId = $this->find('service', self::clean($values['service'] ?? ''));
        foreach (['patient' => $patientId, 'location' => $locationId, 'specialist' => $specialistId, 'service' => $serviceId] as $what => $id) {
            if (! $id) {
                $errors[] = $what.'_unknown';
            }
        }

        $tz = config('optica.clinic_timezone');
        $dateRaw = self::clean($values['date'] ?? '');
        $timeRaw = self::clean($values['time'] ?? '');
        $start = null;
        if ($timeRaw !== '' && preg_match('/^(\d{1,2})[:.](\d{2})/', $timeRaw, $t) && ($day = self::parseDate($dateRaw))) {
            $start = CarbonImmutable::create($day->year, $day->month, $day->day, (int) $t[1], (int) $t[2], 0, $tz);
        } elseif ($timeRaw === '' && ($withTime = self::parseDate($dateRaw)) && $withTime->format('H:i') !== '00:00') {
            $start = CarbonImmutable::create($withTime->year, $withTime->month, $withTime->day, $withTime->hour, $withTime->minute, 0, $tz);
        }
        if (! $start) {
            $errors[] = 'datetime_invalid';
        }
        if ($errors) {
            return ['outcome' => 'error', 'messages' => $errors];
        }

        $duration = (int) preg_replace('/\D+/', '', (string) ($values['duration'] ?? ''));
        if ($duration < 5 || $duration > 480) {
            $duration = (int) (Service::whereKey($serviceId)->value('default_duration') ?: 30);
            if (($values['duration'] ?? '') !== '') {
                $messages[] = 'duration_invalid';
            }
        }
        $status = $this->status(self::clean($values['status'] ?? ''), $start);
        if ($status === null) {
            $messages[] = 'status_unknown';
            $status = $start->isPast() ? AppointmentStatus::Completed : AppointmentStatus::Confirmed;
        }

        if (! $dryRun) {
            $startUtc = $start->utc();
            $end = $startUtc->addMinutes($duration);
            $patient = Patient::find($patientId);
            Appointment::create([
                'uuid' => (string) Str::uuid(),
                'location_id' => $locationId, 'specialist_id' => $specialistId, 'service_id' => $serviceId, 'patient_id' => $patientId,
                'starts_at' => $startUtc, 'ends_at' => $end, 'block_starts_at' => $startUtc, 'block_ends_at' => $end,
                'status' => $status, 'source' => AppointmentSource::Import, 'locale' => $patient?->locale ?? 'ro',
                'contact_name' => $patient?->fullName(), 'contact_phone' => $patient?->phone,
                'cancelled_at' => $status === AppointmentStatus::Cancelled ? $startUtc : null,
                // Never a retroactive message; future ones only when chosen for this import (MIG-05).
                'notifications_disabled' => ! (($context->options['notify_future'] ?? false) && $start->isFuture()),
                'external_ref' => $ref,
                'created_by' => $context->userId,
            ]);
        }

        return ['outcome' => 'created', 'messages' => $messages];
    }

    private function status(string $raw, CarbonImmutable $start): ?AppointmentStatus
    {
        if ($raw === '') {
            return $start->isPast() ? AppointmentStatus::Completed : AppointmentStatus::Confirmed;
        }
        $normal = str_replace('_', ' ', self::normalHeader($raw));
        foreach (self::STATUS_WORDS as $status => $words) {
            if (in_array($normal, $words, true)) {
                return AppointmentStatus::from($status);
            }
        }

        return null;
    }

    /** Matches by code, external reference or name (RO/HU), ignoring case and diacritics. */
    private function find(string $kind, string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (! isset($this->lookups[$kind])) {
            $map = [];
            $models = match ($kind) {
                'location' => Location::all(),
                'specialist' => Specialist::all(),
                'service' => Service::all(),
            };
            foreach ($models as $m) {
                $keys = [$m->code, $m->external_ref, $m->display_name ?? null, $m->address ?? null];
                foreach (config('optica.locales') as $locale) {
                    $keys[] = $m->content[$locale]['name'] ?? null;
                }
                foreach (array_filter($keys) as $key) {
                    $map[self::normalHeader((string) $key)] ??= $m->id;
                    if (str_contains((string) $key, ':')) {
                        $map[self::normalHeader(substr((string) $key, strpos((string) $key, ':') + 1))] ??= $m->id;
                    }
                }
            }
            $this->lookups[$kind] = $map;
        }

        return $this->lookups[$kind][self::normalHeader($value)] ?? null;
    }
}
