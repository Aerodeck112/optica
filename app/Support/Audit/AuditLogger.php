<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Security journal (ACC-02, SEC-03). Callers must never pass passwords, tokens or clinical text in $meta.
 */
class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $meta = [], ?User $user = null, ?int $locationId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'location_id' => $locationId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
