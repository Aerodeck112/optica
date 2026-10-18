<?php

namespace App\Domain\Patients;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lookup and quick registration of patients. The phone is never a unique key (families share
 * phones); possible duplicates are only suggested, never merged automatically (PAC-02).
 */
class PatientRegistry
{
    /** @return Collection<int, Patient> */
    public function search(string $query, int $limit = 8): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return new Collection;
        }
        $digits = preg_replace('/\D+/', '', $query);
        $terms = preg_split('/\s+/', $query);

        return Patient::query()
            ->whereNull('merged_into_id')
            ->whereNull('archived_at')
            ->where(function ($q) use ($query, $digits, $terms) {
                if (strlen($digits) >= 4) {
                    // Match the national part, whether typed as 07…, +407… or 00407….
                    $needle = ltrim($digits, '0');
                    if (str_starts_with($needle, '40') && strlen($needle) > 9) {
                        $needle = substr($needle, 2);
                    }
                    $q->orWhere('phone_normalized', 'like', '%'.$needle.'%')
                        ->orWhere('code', 'like', '%'.$query.'%');
                }
                $q->orWhere(function ($q) use ($terms) {
                    foreach ($terms as $term) {
                        $q->where(fn ($q) => $q->where('last_name', 'like', $term.'%')->orWhere('first_name', 'like', $term.'%'));
                    }
                });
                $q->orWhere('email', 'like', $query.'%');
            })
            ->orderBy('last_name')->orderBy('first_name')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Patient> same phone or same name + birth date */
    public function possibleDuplicates(array $data): Collection
    {
        $phone = Patient::normalizePhone($data['phone'] ?? null);

        return Patient::query()
            ->whereNull('merged_into_id')
            ->where(function ($q) use ($data, $phone) {
                if ($phone) {
                    $q->orWhere('phone_normalized', $phone);
                }
                $q->orWhere(fn ($q) => $q->where('last_name', $data['last_name'] ?? '')->where('first_name', $data['first_name'] ?? ''));
            })
            ->limit(5)
            ->get();
    }

    public function create(array $data, ?User $user = null, ?int $locationId = null, string $source = 'reception'): Patient
    {
        return DB::transaction(function () use ($data, $user, $locationId, $source) {
            $patient = new Patient([
                'code' => 'tmp-'.bin2hex(random_bytes(8)),
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'birth_date' => $data['birth_date'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'locale' => $data['locale'] ?? 'ro',
                'source' => $source,
                'primary_location_id' => $locationId,
                'created_by' => $user?->id,
            ]);
            $patient->save();
            // Readable, stable internal code (PAC-01).
            $patient->code = 'P'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT);
            $patient->save();

            return $patient;
        });
    }
}
