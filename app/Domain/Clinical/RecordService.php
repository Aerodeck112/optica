<?php

namespace App\Domain\Clinical;

use App\Models\Appointment;
use App\Models\ClinicalRecord;
use App\Models\ClinicalRecordAmendment;
use App\Models\ClinicalTemplate;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Draft → final; after finalization, changes are amendments with author, time, reason and the
 * old and new values, so history is never rewritten silently (MED-03).
 */
class RecordService
{
    public function __construct(private readonly AuditLogger $audit, private readonly ClinicalAccess $access) {}

    public function createDraft(Patient $patient, ClinicalTemplate $template, User $user, int $locationId, ?Appointment $appointment = null): ClinicalRecord
    {
        if (! $template->isUsable()) {
            throw new ClinicalException(__('clinical.errors.template_not_validated'));
        }
        $specialistId = $this->access->specialistId($user) ?? throw new ClinicalException(__('clinical.errors.no_specialist_profile'));
        if ($appointment && $appointment->patient_id !== $patient->id) {
            throw new ClinicalException(__('clinical.errors.appointment_mismatch'));
        }

        $record = ClinicalRecord::create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment?->id,
            'location_id' => $appointment?->location_id ?? $locationId,
            'specialist_id' => $specialistId,
            'template_id' => $template->id,
            'data' => TemplateSchema::blank($template),
            'examined_at' => now(),
            'created_by' => $user->id,
        ]);
        $this->audit->log('medical_records.created', $record, ['patient_id' => $patient->id], $user, $record->location_id);

        return $record;
    }

    /** Saves a draft unless someone saved it since $lockVersion was read. */
    public function saveDraft(ClinicalRecord $record, array $input, int $lockVersion, User $user): ClinicalRecord
    {
        if ($record->isFinal()) {
            throw new ClinicalException(__('clinical.errors.already_final'));
        }
        $template = $record->template;
        $data = TemplateSchema::normalize($template, $input);
        Validator::make(['data' => $data], TemplateSchema::rules($template, false), [], TemplateSchema::attributes($template))->validate();

        $updated = ClinicalRecord::whereKey($record->id)->where('lock_version', $lockVersion)->where('status', 'draft')->update([
            // Encrypted exactly as the model cast does, so the conditional update stays one query.
            'data' => (new ClinicalRecord)->forceFill(['data' => $data])->getAttributes()['data'],
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => now(),
        ]);
        if ($updated === 0) {
            throw new ClinicalException(__('clinical.errors.conflict'));
        }

        return $record->refresh();
    }

    public function finalize(ClinicalRecord $record, array $input, int $lockVersion, User $user): ClinicalRecord
    {
        $template = $record->template;
        $data = TemplateSchema::normalize($template, $input);
        Validator::make(['data' => $data], TemplateSchema::rules($template, true), [], TemplateSchema::attributes($template))->validate();

        return DB::transaction(function () use ($record, $data, $lockVersion, $user) {
            $fresh = ClinicalRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($fresh->isFinal()) {
                throw new ClinicalException(__('clinical.errors.already_final'));
            }
            if ($fresh->lock_version !== $lockVersion) {
                throw new ClinicalException(__('clinical.errors.conflict'));
            }
            $fresh->forceFill([
                'data' => $data,
                'status' => 'final',
                'finalized_by' => $user->id,
                'finalized_at' => now(),
                'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $this->audit->log('medical_records.finalized', $fresh, [], $user, $fresh->location_id);

            return $fresh;
        });
    }

    /** @return ClinicalRecordAmendment the tracked correction */
    public function amend(ClinicalRecord $record, array $input, string $reason, User $user): ClinicalRecordAmendment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new ClinicalException(__('clinical.errors.reason_required'));
        }
        $template = $record->template;
        $data = TemplateSchema::normalize($template, $input);
        Validator::make(['data' => $data], TemplateSchema::rules($template, true), [], TemplateSchema::attributes($template))->validate();

        return DB::transaction(function () use ($record, $data, $reason, $user) {
            $fresh = ClinicalRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->isFinal()) {
                throw new ClinicalException(__('clinical.errors.not_final'));
            }
            $changes = [];
            foreach ($data as $key => $value) {
                $old = $fresh->data[$key] ?? '';
                if ($old !== $value) {
                    $changes[$key] = ['old' => $old, 'new' => $value];
                }
            }
            if (! $changes) {
                throw new ClinicalException(__('clinical.errors.no_changes'));
            }
            $amendment = ClinicalRecordAmendment::create([
                'clinical_record_id' => $fresh->id,
                'user_id' => $user->id,
                'reason' => mb_substr($reason, 0, 500),
                'changes' => $changes,
                'created_at' => now(),
            ]);
            $fresh->forceFill(['data' => $data, 'lock_version' => $fresh->lock_version + 1])->save();
            // The journal records which fields changed, never their clinical content.
            $this->audit->log('medical_records.amended', $fresh, ['fields' => array_keys($changes)], $user, $fresh->location_id);

            return $amendment;
        });
    }
}
