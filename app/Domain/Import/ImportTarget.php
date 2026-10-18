<?php

namespace App\Domain\Import;

use Carbon\CarbonImmutable;

/**
 * One kind of record that can be imported. A row is normalised, validated and then either
 * created, completed (empty fields only), linked, left unchanged, reported as a duplicate for a
 * decision, or rejected with its reasons (MIG-02). With $dryRun nothing is written.
 */
abstract class ImportTarget
{
    /** @return array<string, array{required?: bool, synonyms: list<string>}> */
    abstract public function fields(): array;

    /**
     * @param  array<string, string>  $values  mapped values of one row
     * @return array{outcome: string, messages: list<string>}
     */
    abstract public function process(array $values, ImportContext $context, bool $dryRun): array;

    /** Column name → field, from the header names usually found in exports. */
    public function guessMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $normal = self::normalHeader($header);
            foreach ($this->fields() as $field => $meta) {
                if (in_array($field, $mapping, true)) {
                    continue;
                }
                if ($normal === self::normalHeader($field) || in_array($normal, array_map([self::class, 'normalHeader'], $meta['synonyms']), true)) {
                    $mapping[$index] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    public static function normalHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }

    /** d.m.Y, d/m/Y, d-m-Y, Y-m-d (with optional time) or an Excel serial number. */
    public static function parseDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value);
        }
        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'Y.m.d', 'd.m.Y H:i', 'd/m/Y H:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd.m.Y H:i:s'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    public static function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
