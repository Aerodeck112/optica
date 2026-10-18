<?php

namespace App\Domain\Clinical;

use App\Models\ClinicalTemplate;

/**
 * Structured clinical fields (MED-01, MED-02): units, ranges and steps come from the template.
 * Drafts may be incomplete; required fields are enforced at finalization. Values are never
 * interpreted: the application does not derive diagnoses or treatments.
 */
class TemplateSchema
{
    public const TYPES = ['text', 'line', 'number', 'date', 'choice'];

    public const SECTIONS = ['anamnesis', 'acuity', 'refraction', 'pressure', 'investigations', 'conclusion', 'other'];

    /** @return list<array<string, mixed>> */
    public static function fields(ClinicalTemplate $template): array
    {
        return array_values(array_filter($template->fields ?? [], fn ($f) => is_array($f) && isset($f['key'], $f['type'])));
    }

    /** Empty data shaped like the template. */
    public static function blank(ClinicalTemplate $template): array
    {
        $data = [];
        foreach (self::fields($template) as $field) {
            $data[$field['key']] = ($field['eyes'] ?? false) ? ['od' => '', 'os' => ''] : '';
        }

        return $data;
    }

    /** Keeps only template keys, as strings, so stored data always matches the template. */
    public static function normalize(ClinicalTemplate $template, array $input): array
    {
        $data = [];
        foreach (self::fields($template) as $field) {
            $value = $input[$field['key']] ?? '';
            if ($field['eyes'] ?? false) {
                $data[$field['key']] = [
                    'od' => self::scalar(is_array($value) ? ($value['od'] ?? '') : '', $field['type']),
                    'os' => self::scalar(is_array($value) ? ($value['os'] ?? '') : '', $field['type']),
                ];
            } else {
                $data[$field['key']] = self::scalar($value, $field['type']);
            }
        }

        return $data;
    }

    private static function scalar(mixed $value, string $type): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $value = trim(str_replace("\r\n", "\n", (string) $value));

        // Romanian and Hungarian keyboards type decimals with a comma; "+1,25" is stored as "1.25".
        return $type === 'number' ? ltrim(str_replace([',', ' '], ['.', ''], $value), '+') : $value;
    }

    /** @return array<string, list<mixed>> Laravel rules for data.* */
    public static function rules(ClinicalTemplate $template, bool $final, string $prefix = 'data'): array
    {
        $rules = [];
        foreach (self::fields($template) as $field) {
            $base = [$final && ($field['required'] ?? false) ? 'required' : 'nullable'];
            $base = array_merge($base, match ($field['type']) {
                'number' => ['numeric', ...(isset($field['min'], $field['max']) ? ['between:'.$field['min'].','.$field['max']] : []), self::stepRule($field)],
                'date' => ['date_format:Y-m-d'],
                'choice' => ['in:'.implode(',', array_column($field['options'] ?? [], 'value'))],
                'line' => ['string', 'max:300'],
                default => ['string', 'max:10000'],
            });
            if ($field['eyes'] ?? false) {
                $rules["$prefix.{$field['key']}.od"] = $base;
                $rules["$prefix.{$field['key']}.os"] = $base;
            } else {
                $rules["$prefix.{$field['key']}"] = $base;
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function attributes(ClinicalTemplate $template, string $prefix = 'data'): array
    {
        $names = [];
        foreach (self::fields($template) as $field) {
            $label = self::label($field);
            if ($field['eyes'] ?? false) {
                $names["$prefix.{$field['key']}.od"] = $label.' OD';
                $names["$prefix.{$field['key']}.os"] = $label.' OS';
            } else {
                $names["$prefix.{$field['key']}"] = $label;
            }
        }

        return $names;
    }

    public static function label(array $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($field['label'][$locale] ?? $field['label']['ro'] ?? $field['key']);
    }

    /** "+1.25 D" style display; text values are returned as written. */
    public static function display(array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if ($field['type'] === 'choice') {
            foreach ($field['options'] ?? [] as $option) {
                if ((string) $option['value'] === (string) $value) {
                    return self::label($option);
                }
            }
        }
        if ($field['type'] === 'number') {
            $number = (float) $value;
            $decimals = isset($field['step']) && str_contains((string) $field['step'], '.') ? strlen(substr(strrchr((string) $field['step'], '.'), 1)) : 0;
            $text = number_format($number, $decimals, '.', '');
            if (($field['signed'] ?? false) && $number > 0) {
                $text = '+'.$text;
            }

            return $text.(($field['unit'] ?? '') !== '' ? ' '.$field['unit'] : '');
        }
        if ($field['type'] === 'date') {
            return \Carbon\CarbonImmutable::parse($value)->format('d.m.Y');
        }

        return (string) $value;
    }

    private static function stepRule(array $field): \Closure
    {
        $step = (float) ($field['step'] ?? 0);

        return function (string $attribute, mixed $value, \Closure $fail) use ($step) {
            if ($step <= 0 || ! is_numeric($value)) {
                return;
            }
            $ratio = (float) $value / $step;
            if (abs($ratio - round($ratio)) > 1e-6) {
                $fail(__('clinical.errors.step', ['step' => rtrim(rtrim(number_format($step, 2, '.', ''), '0'), '.')]));
            }
        };
    }
}
