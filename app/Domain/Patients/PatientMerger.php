<?php

namespace App\Domain\Patients;

use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Controlled merge of duplicate files (PAC-02): every reference moves to the kept file, the
 * duplicate stays as an archived record pointing to it, and the operation is journaled with a
 * snapshot of the duplicate's data. Never automatic.
 */
class PatientMerger
{
    /** table => column; every place a patient id is stored. */
    private const REFERENCES = [
        'appointments' => 'patient_id',
        'visits' => 'patient_id',
        'waitlist_entries' => 'patient_id',
        'consents' => 'patient_id',
        'leads' => 'patient_id',
        'clinical_records' => 'patient_id',
        'prescriptions' => 'patient_id',
        'patient_documents' => 'patient_id',
    ];

    /** Fields copied from the duplicate when the kept file has them empty. */
    private const FILL_EMPTY = ['birth_date', 'sex', 'phone', 'email', 'address', 'city', 'legal_guardian_id', 'external_ref'];

    public function merge(Patient $duplicate, Patient $keep, User $user): PatientMerge
    {
        if ($duplicate->id === $keep->id) {
            throw new RuntimeException('Same patient');
        }

        return DB::transaction(function () use ($duplicate, $keep, $user) {
            // Lock both in id order so two simultaneous merges cannot deadlock or cross.
            $locked = Patient::whereIn('id', [$duplicate->id, $keep->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$duplicate->id];
            $target = $locked[$keep->id];
            if ($source->merged_into_id || $target->merged_into_id) {
                throw new RuntimeException('Already merged');
            }

            $moved = [];
            foreach (self::REFERENCES as $table => $column) {
                $moved[$table] = DB::table($table)->where($column, $source->id)->update([$column => $target->id]);
            }
            $moved['tasks'] = DB::table('tasks')->where('subject_type', 'patient')->where('subject_id', $source->id)->update(['subject_id' => $target->id]);
            $moved['guardian_of'] = Patient::where('legal_guardian_id', $source->id)->where('id', '!=', $target->id)->update(['legal_guardian_id' => $target->id]);

            // Family relations: keep the target's own links, drop self-links and duplicates.
            $moved['relations'] = 0;
            foreach (DB::table('patient_relations')->where('patient_id', $source->id)->orWhere('related_patient_id', $source->id)->get() as $relation) {
                $from = $relation->patient_id === $source->id ? $target->id : $relation->patient_id;
                $to = $relation->related_patient_id === $source->id ? $target->id : $relation->related_patient_id;
                DB::table('patient_relations')->where('id', $relation->id)->delete();
                if ($from !== $to && ! DB::table('patient_relations')->where('patient_id', $from)->where('related_patient_id', $to)->exists()) {
                    DB::table('patient_relations')->insert(['patient_id' => $from, 'related_patient_id' => $to, 'relation' => $relation->relation, 'created_at' => now(), 'updated_at' => now()]);
                    $moved['relations']++;
                }
            }

            if ((int) $target->legal_guardian_id === $source->id) {
                $target->legal_guardian_id = null;
            }
            foreach (self::FILL_EMPTY as $field) {
                if (blank($target->{$field}) && filled($source->{$field}) && ! ($field === 'legal_guardian_id' && (int) $source->{$field} === $target->id)) {
                    $target->{$field} = $source->{$field};
                }
            }
            if (! $target->cnp_hash && $source->cnp_hash) {
                $target->forceFill(['cnp_encrypted' => $source->cnp_encrypted, 'cnp_hash' => $source->cnp_hash]);
            }
            if ($source->admin_notes) {
                $target->admin_notes = trim(($target->admin_notes ? $target->admin_notes."\n\n" : '').'['.$source->code.'] '.$source->admin_notes);
            }
            $target->save();

            $snapshot = $source->only(['code', 'first_name', 'last_name', 'birth_date', 'sex', 'phone', 'email', 'locale', 'address', 'city', 'source', 'admin_notes', 'external_ref']);
            $source->forceFill(['merged_into_id' => $target->id, 'archived_at' => now(), 'cnp_encrypted' => null, 'cnp_hash' => null])->save();

            $merge = PatientMerge::create([
                'source_id' => $source->id,
                'target_id' => $target->id,
                'user_id' => $user->id,
                'moved' => $moved,
                'source_snapshot' => $snapshot,
                'created_at' => now(),
            ]);
            app(AuditLogger::class)->log('patients.merged', $target, ['source' => $source->code, 'moved' => array_filter($moved)], $user);

            return $merge;
        });
    }
}
