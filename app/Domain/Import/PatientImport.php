<?php

namespace App\Domain\Import;

use App\Domain\Patients\PatientRegistry;
use App\Models\Location;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * Patients from an export (MIG-01…03). A repeated import completes empty fields of patients it
 * created before and never overwrites what staff changed; possible duplicates are reported, not
 * merged. Clinical data is not part of this import.
 */
class PatientImport extends ImportTarget
{
    public function fields(): array
    {
        return [
            'external_ref' => ['required' => true, 'synonyms' => ['id', 'id pacient', 'cod pacient', 'patient id', 'nr fisa', 'cod', 'azonosito']],
            'last_name' => ['synonyms' => ['nume', 'nume familie', 'vezeteknev', 'last name']],
            'first_name' => ['synonyms' => ['prenume', 'keresztnev', 'first name']],
            'full_name' => ['synonyms' => ['nume complet', 'pacient', 'nev', 'name', 'nume si prenume']],
            'birth_date' => ['synonyms' => ['data nasterii', 'data nastere', 'nascut', 'szuletesi datum', 'birth date', 'dob']],
            'sex' => ['synonyms' => ['gen', 'nem']],
            'phone' => ['synonyms' => ['telefon', 'mobil', 'telefon mobil', 'tel', 'telefonszam']],
            'email' => ['synonyms' => ['e-mail', 'mail', 'adresa email']],
            'cnp' => ['synonyms' => ['cod numeric personal', 'cnp pacient']],
            'address' => ['synonyms' => ['adresa', 'strada', 'cim']],
            'city' => ['synonyms' => ['localitate', 'oras', 'varos', 'judet localitate']],
            'locale' => ['synonyms' => ['limba', 'nyelv', 'language']],
            'location' => ['synonyms' => ['locatie', 'punct de lucru', 'clinica', 'helyszin']],
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

        [$last, $first] = [self::clean($values['last_name'] ?? ''), self::clean($values['first_name'] ?? '')];
        if (($last === '' || $first === '') && ($full = self::clean($values['full_name'] ?? '')) !== '') {
            $parts = explode(' ', $full, 2);
            [$last, $first] = [$last ?: $parts[0], $first ?: ($parts[1] ?? '')];
        }
        if ($last === '' || $first === '') {
            return ['outcome' => 'error', 'messages' => ['name_missing']];
        }

        $data = ['last_name' => mb_substr($last, 0, 100), 'first_name' => mb_substr($first, 0, 100)];
        if (($raw = self::clean($values['birth_date'] ?? '')) !== '') {
            $date = self::parseDate($raw);
            $date && $date->year >= 1900 && $date->isPast() ? $data['birth_date'] = $date->toDateString() : $messages[] = 'birth_date_invalid';
        }
        if (($raw = self::clean($values['phone'] ?? '')) !== '') {
            Patient::normalizePhone($raw) ? $data['phone'] = mb_substr($raw, 0, 32) : $messages[] = 'phone_invalid';
        }
        if (($raw = mb_strtolower(self::clean($values['email'] ?? ''))) !== '') {
            filter_var($raw, FILTER_VALIDATE_EMAIL) ? $data['email'] = $raw : $messages[] = 'email_invalid';
        }
        $sex = mb_strtolower(self::clean($values['sex'] ?? ''));
        if ($sex !== '') {
            $data['sex'] = match (true) {
                in_array($sex, ['f', 'feminin', 'female', 'no', 'nő'], true) => 'female',
                in_array($sex, ['m', 'masculin', 'male', 'ferfi', 'férfi'], true) => 'male',
                default => null,
            };
            if ($data['sex'] === null) {
                unset($data['sex']);
                $messages[] = 'sex_unknown';
            }
        }
        foreach (['address' => 255, 'city' => 120] as $field => $max) {
            if (($raw = self::clean($values[$field] ?? '')) !== '') {
                $data[$field] = mb_substr($raw, 0, $max);
            }
        }
        $locale = mb_strtolower(self::clean($values['locale'] ?? ''));
        if ($locale !== '') {
            $data['locale'] = str_starts_with($locale, 'hu') || str_starts_with($locale, 'magh') || str_starts_with($locale, 'magy') ? 'hu' : 'ro';
        }
        if (($raw = self::clean($values['location'] ?? '')) !== '') {
            $location = Location::where('code', $raw)->orWhere('address', $raw)->first();
            $location ? $data['primary_location_id'] = $location->id : $messages[] = 'location_unknown';
        }
        $cnp = preg_replace('/\D+/', '', (string) ($values['cnp'] ?? ''));
        if ($cnp !== '' && ! self::validCnp($cnp)) {
            $messages[] = 'cnp_invalid';
            $cnp = '';
        }

        // Same record from an earlier run: complete what is empty, keep what staff changed.
        $existing = Patient::where('external_ref', $ref)->first();
        if ($existing) {
            $fill = array_filter($data, fn ($v, $k) => $existing->{$k} === null || $existing->{$k} === '', ARRAY_FILTER_USE_BOTH);
            $addCnp = $cnp !== '' && $existing->cnp_hash === null;
            if (! $fill && ! $addCnp) {
                return ['outcome' => 'unchanged', 'messages' => $messages];
            }
            if (! $dryRun) {
                $existing->fill($fill);
                if ($addCnp) {
                    $existing->setCnp($cnp);
                }
                $existing->save();
            }

            return ['outcome' => 'updated', 'messages' => $messages];
        }

        // An existing patient with the same CNP is the same person: the reference is attached to it.
        if ($cnp !== '' && ($same = Patient::where('cnp_hash', Patient::hashCnp($cnp))->whereNull('merged_into_id')->first())) {
            if ($same->external_ref && $same->external_ref !== $ref) {
                return ['outcome' => 'duplicate', 'messages' => [...$messages, 'cnp_belongs_to:'.$same->code]];
            }
            if (! $dryRun) {
                $same->update(['external_ref' => $ref]);
            }

            return ['outcome' => 'linked', 'messages' => [...$messages, 'linked_to:'.$same->code]];
        }
        // Same name and (phone or birth date): a person to check, never merged automatically (PAC-02).
        $candidate = Patient::whereNull('merged_into_id')->where('last_name', $data['last_name'])->where('first_name', $data['first_name'])
            ->where(function ($q) use ($data) {
                $phone = Patient::normalizePhone($data['phone'] ?? null);
                $q->when($phone, fn ($q) => $q->orWhere('phone_normalized', $phone))
                    ->when(isset($data['birth_date']), fn ($q) => $q->orWhere('birth_date', $data['birth_date']));
            })
            ->when(! Patient::normalizePhone($data['phone'] ?? null) && ! isset($data['birth_date']), fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
        if ($candidate) {
            return ['outcome' => 'duplicate', 'messages' => [...$messages, 'possible_duplicate:'.$candidate->code]];
        }

        if (! $dryRun) {
            DB::transaction(function () use ($data, $ref, $cnp, $context) {
                $patient = app(PatientRegistry::class)->create($data + ['locale' => $data['locale'] ?? 'ro'], null, $data['primary_location_id'] ?? null, 'import');
                $patient->fill(array_intersect_key($data, array_flip(['sex', 'address', 'city'])) + ['external_ref' => $ref, 'created_by' => $context->userId]);
                if ($cnp !== '') {
                    $patient->setCnp($cnp);
                }
                $patient->save();
            });
        }

        return ['outcome' => 'created', 'messages' => $messages];
    }

    /** Romanian CNP: 13 digits with the official control digit. */
    public static function validCnp(string $cnp): bool
    {
        if (! preg_match('/^[1-9]\d{12}$/', $cnp)) {
            return false;
        }
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnp[$i] * (int) $key[$i];
        }
        $control = $sum % 11 === 10 ? 1 : $sum % 11;

        return $control === (int) $cnp[12];
    }
}
