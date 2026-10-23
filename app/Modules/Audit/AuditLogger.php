<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Jurnal pentru modificări sensibile. Nu înregistrează parole, tokenuri sau secrete:
 * cheile cunoscute sunt mascate înainte de salvare.
 */
class AuditLogger
{
    private const SENSITIVE = ['password', 'token', 'secret', 'credentials', 'api_key', 'recovery', 'iban_full', 'card'];

    private ?array $actorOverride = null;

    public function as(string $type, ?int $id = null): static
    {
        $clone = clone $this;
        $clone->actorOverride = [$type, $id];

        return $clone;
    }

    public function log(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
    {
        [$actorType, $actorId] = $this->actorOverride ?? $this->resolveActor();

        return AuditLog::query()->create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ? self::scrub($changes) : null,
            'context' => $context ? self::scrub($context) : null,
            'ip_hash' => request()?->ip() ? hash('sha256', request()->ip().config('app.key')) : null,
        ]);
    }

    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = '••••';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveActor(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['system', null];
        }
        if ($user = Auth::guard('web')->user()) {
            return ['user', $user->getKey()];
        }
        if ($customer = Auth::guard('customer')->user()) {
            return ['customer', $customer->getKey()];
        }

        return ['system', null];
    }
}
