<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\CustomerResolver;
use App\Modules\Marketing\Models\ContactImport;
use App\Support\ContactNormalizer;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Import de contacte din CSV. Fiecare import are obligatoriu sursa și dovada permisiunii.
 * Consimțământul de marketing se înregistrează DOAR pe rândurile care îl conțin explicit (cu dată),
 * pe canalele bifate la import; restul rândurilor devin contacte fără consimțământ de marketing.
 */
class ContactImportService
{
    public const COLUMNS = ['email', 'phone', 'first_name', 'last_name', 'company_name', 'consent_email', 'consent_sms', 'consent_date'];

    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    public function run(ContactImport $import, string $absolutePath): ContactImport
    {
        if (blank($import->source_description) || blank($import->permission_proof)) {
            throw new RuntimeException('Importul are nevoie de sursa contactelor și de dovada permisiunii.');
        }
        $handle = fopen($absolutePath, 'r');
        if (! $handle) {
            throw new RuntimeException('Fișierul nu poate fi citit.');
        }
        $first = fgets($handle);
        $delimiter = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), str_getcsv((string) $first, $delimiter, '"', ''));
        if (! in_array('email', $header, true) && ! in_array('phone', $header, true)) {
            fclose($handle);
            throw new RuntimeException('Fișierul trebuie să aibă cel puțin coloana „email” sau „phone”. Coloane acceptate: '.implode(', ', self::COLUMNS));
        }

        $total = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $channels = (array) $import->channels;
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $total++;
            if ($total > 50000) {
                $errors[] = 'Limita de 50.000 de rânduri a fost atinsă; restul au fost ignorate.';
                break;
            }
            $row = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
            $email = ContactNormalizer::email($row['email'] ?? null);
            $phone = ContactNormalizer::phone($row['phone'] ?? null);
            if (! $email && ! $phone) {
                $skipped++;
                if (count($errors) < 50) {
                    $errors[] = "Rândul {$total}: fără e-mail sau telefon valid.";
                }

                continue;
            }
            try {
                $customer = $this->customers->resolve([
                    'first_name' => $row['first_name'] ?? null, 'last_name' => $row['last_name'] ?? null,
                    'email' => $row['email'] ?? null, 'phone' => $row['phone'] ?? null, 'company_name' => $row['company_name'] ?? null,
                ], 'import');
                $date = $this->date($row['consent_date'] ?? null);
                foreach (['email' => 'consent_email', 'sms' => 'consent_sms'] as $channel => $column) {
                    if (in_array($channel, $channels, true) && $date && $this->yes($row[$column] ?? null) && ($channel === 'email' ? $email : $phone)) {
                        $this->consents->grant($customer, $channel, 'import', [
                            'import_id' => $import->id,
                            'source' => $import->source_description,
                            'permission_proof' => $import->permission_proof,
                            'original_consent_date' => $date->toDateString(),
                            'row' => $total,
                        ], 'user', $import->created_by);
                    }
                }
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                if (count($errors) < 50) {
                    $errors[] = "Rândul {$total}: ".mb_substr($e->getMessage(), 0, 120);
                }
            }
        }
        fclose($handle);
        @unlink($absolutePath); // fișierul original nu se păstrează după procesare

        $import->forceFill(['status' => 'completed', 'rows_total' => $total, 'rows_imported' => $imported, 'rows_skipped' => $skipped, 'errors' => $errors])->save();
        $this->audit->log('contacts.imported', $import, ['rows' => $total, 'imported' => $imported]);

        return $import;
    }

    private function yes(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'da', 'yes', 'y', 'true', 'x'], true);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value && $date->isPast()) {
                return $date;
            }
        }

        return null;
    }
}
