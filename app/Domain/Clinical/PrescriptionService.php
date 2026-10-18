<?php

namespace App\Domain\Clinical;

use App\Models\ClinicalRecord;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Prescriptions written by a specialist (MED-02): author, date, version. A correction creates a new
 * version and marks the previous one superseded; nothing is computed or suggested by the application.
 */
class PrescriptionService
{
    /** Per-eye fields: [min, max, step, unit, signed] */
    public const EYE_FIELDS = [
        'sph' => [-30, 30, 0.25, 'D', true],
        'cyl' => [-10, 10, 0.25, 'D', true],
        'axis' => [0, 180, 1, '°', false],
        'add' => [0, 4, 0.25, 'D', true],
        'bc' => [7, 10, 0.05, 'mm', false],
        'dia' => [12, 16, 0.1, 'mm', false],
    ];

    public const TYPE_FIELDS = [
        'glasses' => ['sph', 'cyl', 'axis', 'add'],
        'contact_lenses' => ['sph', 'cyl', 'axis', 'add', 'bc', 'dia'],
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly ClinicalAccess $access) {}

    public static function blank(): array
    {
        $eye = array_fill_keys(array_keys(self::EYE_FIELDS), '');

        return ['od' => $eye, 'os' => $eye, 'pd' => '', 'use' => '', 'notes' => ''];
    }

    public static function normalize(string $type, array $input): array
    {
        $data = self::blank();
        foreach (['od', 'os'] as $eye) {
            foreach (self::TYPE_FIELDS[$type] ?? [] as $field) {
                $value = $input[$eye][$field] ?? '';
                $data[$eye][$field] = is_scalar($value) ? ltrim(str_replace([',', ' '], ['.', ''], trim((string) $value)), '+') : '';
            }
        }
        $data['pd'] = is_scalar($input['pd'] ?? null) ? str_replace(',', '.', trim((string) $input['pd'])) : '';
        $data['use'] = in_array($input['use'] ?? '', ['distance', 'near', 'intermediate', 'progressive', 'bifocal', ''], true) ? ($input['use'] ?? '') : '';
        $data['notes'] = is_scalar($input['notes'] ?? null) ? trim(str_replace("\r\n", "\n", (string) $input['notes'])) : '';

        return $data;
    }

    public static function rules(string $type): array
    {
        $rules = ['data.pd' => ['nullable', 'numeric', 'between:40,80'], 'data.notes' => ['nullable', 'string', 'max:2000']];
        foreach (['od', 'os'] as $eye) {
            foreach (self::TYPE_FIELDS[$type] as $field) {
                [$min, $max, $step] = self::EYE_FIELDS[$field];
                $rules["data.$eye.$field"] = ['nullable', 'numeric', "between:$min,$max", function ($attribute, $value, $fail) use ($step) {
                    if (is_numeric($value) && abs((float) $value / $step - round((float) $value / $step)) > 1e-6) {
                        $fail(__('clinical.errors.step', ['step' => $step]));
                    }
                }];
            }
            // An axis is meaningful only with a cylinder, and a cylinder needs an axis.
            $rules["data.$eye.axis"][] = "required_with:data.$eye.cyl";
            $rules["data.$eye.cyl"][] = "required_with:data.$eye.axis";
            $rules["data.$eye.sph"][] = "required_with:data.$eye.cyl,data.$eye.add";
        }

        return $rules;
    }

    public static function attributes(): array
    {
        $names = ['data.pd' => __('clinical.rx.pd'), 'data.notes' => __('clinical.rx.notes')];
        foreach (['od', 'os'] as $eye) {
            foreach (array_keys(self::EYE_FIELDS) as $field) {
                $names["data.$eye.$field"] = __('clinical.rx.'.$field).' '.strtoupper($eye);
            }
        }

        return $names;
    }

    private function validate(string $type, array $data, ?string $validUntil): void
    {
        Validator::make(['type' => $type, 'data' => $data, 'valid_until' => $validUntil],
            ['type' => ['required', Rule::in(Prescription::TYPES)], 'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']] + self::rules($type),
            [], self::attributes() + ['valid_until' => __('clinical.rx.valid_until')])->validate();
        if (($data['od']['sph'] ?? '') === '' && ($data['os']['sph'] ?? '') === '') {
            throw new ClinicalException(__('clinical.errors.rx_empty'));
        }
    }

    public function issue(Patient $patient, string $type, array $input, User $user, int $locationId, ?ClinicalRecord $record = null, ?string $validUntil = null): Prescription
    {
        $specialistId = $this->access->specialistId($user) ?? throw new ClinicalException(__('clinical.errors.no_specialist_profile'));
        $data = self::normalize($type, $input);
        $this->validate($type, $data, $validUntil);
        if ($record && $record->patient_id !== $patient->id) {
            throw new ClinicalException(__('clinical.errors.appointment_mismatch'));
        }

        $prescription = Prescription::create([
            'patient_id' => $patient->id,
            'clinical_record_id' => $record?->id,
            'location_id' => $record?->location_id ?? $locationId,
            'specialist_id' => $specialistId,
            'type' => $type,
            'data' => $data,
            'issued_on' => now(config('optica.clinic_timezone'))->toDateString(),
            'valid_until' => $validUntil ?: null,
            'created_by' => $user->id,
        ]);
        $this->audit->log('prescriptions.created', $prescription, ['patient_id' => $patient->id], $user, $prescription->location_id);

        return $prescription;
    }

    public function amend(Prescription $previous, array $input, string $reason, User $user, ?string $validUntil = null): Prescription
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new ClinicalException(__('clinical.errors.reason_required'));
        }
        $data = self::normalize($previous->type, $input);
        $this->validate($previous->type, $data, $validUntil);
        $specialistId = $this->access->specialistId($user) ?? throw new ClinicalException(__('clinical.errors.no_specialist_profile'));

        return DB::transaction(function () use ($previous, $data, $reason, $user, $validUntil, $specialistId) {
            $locked = Prescription::whereKey($previous->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ClinicalException(__('clinical.errors.rx_not_active'));
            }
            $locked->update(['status' => 'superseded']);
            $next = Prescription::create([
                'patient_id' => $locked->patient_id,
                'clinical_record_id' => $locked->clinical_record_id,
                'location_id' => $locked->location_id,
                'specialist_id' => $specialistId,
                'type' => $locked->type,
                'data' => $data,
                'version' => $locked->version + 1,
                'supersedes_id' => $locked->id,
                'amend_reason' => mb_substr(trim($reason), 0, 500),
                'issued_on' => now(config('optica.clinic_timezone'))->toDateString(),
                'valid_until' => $validUntil ?: null,
                'created_by' => $user->id,
            ]);
            $this->audit->log('prescriptions.amended', $next, ['previous_id' => $locked->id], $user, $next->location_id);

            return $next;
        });
    }

    public static function display(string $field, mixed $value): string
    {
        if ($value === '' || $value === null) {
            return '—';
        }
        $signed = self::EYE_FIELDS[$field][4];
        $text = number_format((float) $value, match ($field) { 'axis' => 0, 'dia' => 1, default => 2 }, '.', '');

        return ($signed && (float) $value > 0 ? '+' : '').$text;
    }
}
