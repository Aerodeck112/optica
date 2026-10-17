<?php

namespace App\Livewire\Concerns;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for catalogue editors: server-side permission on every request (ACC-02),
 * audit trail, and no deletion while future appointments depend on the record (PRG-04).
 */
trait GuardsCatalog
{
    public function bootGuardsCatalog(): void
    {
        $this->authorize('catalog.manage');
    }

    protected function audit(string $action, Model $subject, array $meta = []): void
    {
        app(AuditLogger::class)->log($action, $subject, $meta);
    }

    protected function futureAppointmentsCount(string $column, int $id): int
    {
        return Appointment::query()
            ->where($column, $id)
            ->where('starts_at', '>=', now())
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->count();
    }

    /** @return array<string, array<string, string>> empty bilingual content for the given fields */
    protected function emptyContent(array $fields): array
    {
        $content = [];
        foreach (config('optica.locales') as $locale) {
            $content[$locale] = array_fill_keys($fields, '');
        }

        return $content;
    }

    protected function mergeContent(array $fields, ?array $stored): array
    {
        $content = $this->emptyContent($fields);
        foreach (config('optica.locales') as $locale) {
            foreach ($fields as $field) {
                $content[$locale][$field] = (string) ($stored[$locale][$field] ?? '');
            }
        }

        return $content;
    }
}
