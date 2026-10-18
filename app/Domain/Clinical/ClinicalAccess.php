<?php

namespace App\Domain\Clinical;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ClinicalRecord;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use App\Support\Access\AccessControl;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see clinical data (ACC-01): "view" covers the user's locations, "view_own" only patients
 * the user's specialist profile has treated or is scheduled to see. Clinical rights are never implicit.
 */
class ClinicalAccess
{
    public function __construct(private readonly AccessControl $access) {}

    public function specialistId(User $user): ?int
    {
        return $user->specialist?->id;
    }

    /** The patient has (had) an appointment or a visit with this user's specialist profile. */
    public function treats(User $user, Patient|int $patient): bool
    {
        $specialistId = $this->specialistId($user);
        if (! $specialistId) {
            return false;
        }
        $patientId = $patient instanceof Patient ? $patient->id : $patient;

        return Visit::where('patient_id', $patientId)->where('specialist_id', $specialistId)->exists()
            || Appointment::where('patient_id', $patientId)->where('specialist_id', $specialistId)
                ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::Expired->value, AppointmentStatus::Held->value])->exists();
    }

    public function canSeeAnyRecords(User $user, Patient $patient): bool
    {
        return $this->access->allows($user, 'medical_records.view')
            || ($this->access->allows($user, 'medical_records.view_own') && $this->treats($user, $patient));
    }

    /** @param Builder<ClinicalRecord> $query */
    public function scopeRecords(Builder $query, User $user, Patient $patient): Builder
    {
        $locations = $this->access->locationIdsFor($user, 'medical_records.view');
        $own = $this->access->allows($user, 'medical_records.view_own') && $this->treats($user, $patient);

        return $query->where('patient_id', $patient->id)->where(function ($q) use ($locations, $own, $user) {
            if ($locations === null && $this->access->allows($user, 'medical_records.view')) {
                $q->orWhereRaw('1 = 1');
            } elseif ($locations) {
                $q->orWhereIn('location_id', $locations);
            }
            if ($own) {
                $q->orWhereRaw('1 = 1');
            } elseif ($id = $this->specialistId($user)) {
                // A specialist always sees the records they wrote.
                $q->orWhere('specialist_id', $id);
            }
            $q->orWhereRaw('1 = 0');
        });
    }

    public function canViewRecord(User $user, ClinicalRecord $record): bool
    {
        if ($this->access->allows($user, 'medical_records.view', $record->location_id)) {
            return true;
        }
        if (! $this->access->allows($user, 'medical_records.view_own')) {
            return false;
        }

        return $record->specialist_id === $this->specialistId($user) || $this->treats($user, $record->patient_id);
    }

    /** Drafts are edited only by the specialist who owns them. */
    public function canEditDraft(User $user, ClinicalRecord $record): bool
    {
        return ! $record->isFinal()
            && $this->access->allows($user, 'medical_records.create')
            && $record->specialist_id === $this->specialistId($user);
    }

    public function canFinalize(User $user, ClinicalRecord $record): bool
    {
        return ! $record->isFinal()
            && $this->access->allows($user, 'medical_records.finalize')
            && $record->specialist_id === $this->specialistId($user);
    }

    public function canAmend(User $user, ClinicalRecord $record): bool
    {
        return $record->isFinal()
            && $this->access->allows($user, 'medical_records.amend')
            && $this->canViewRecord($user, $record);
    }

    public function canViewPrescription(User $user, Prescription $prescription): bool
    {
        if (! $this->access->allows($user, 'prescriptions.view')) {
            return false;
        }

        return $this->access->allows($user, 'prescriptions.view', $prescription->location_id)
            || $prescription->specialist_id === $this->specialistId($user)
            || $this->treats($user, $prescription->patient_id);
    }
}
