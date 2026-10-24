<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Date confirmate de proprietar (24.10.2026): firma este plătitoare de TVA, sediul este în județul Mureș,
 * e-mailul este office@anotherworldcluj.ro, codul poștal al arenei este 400591; acordul părinților pentru
 * imaginile cu minori din materialele francizei este confirmat; notele despre sursa imaginilor nu se mai afișează.
 */
return new class extends Migration
{
    private const EMAIL = 'office@anotherworldcluj.ro';

    /** Se completează doar dacă sunt goale. */
    private const VALUES = [
        'company.vat_payer' => 'yes',
        'company.county_code' => 'RO-MS',
        'venue.address_postal_code' => '400591',
        'contact.email_general' => self::EMAIL,
        'contact.email_bookings' => self::EMAIL,
        'contact.email_events' => self::EMAIL,
        'contact.email_billing' => self::EMAIL,
        'contact.email_privacy' => self::EMAIL,
        'contact.notifications_staff' => self::EMAIL,
    ];

    public function up(): void
    {
        foreach (self::VALUES as $key => $value) {
            $this->fillIfEmpty($key, $value);
        }

        // plătitor de TVA: codul de înregistrare în scopuri de TVA are prefixul RO
        $taxId = DB::table('settings')->where('key', 'company.tax_id')->value('value');
        if (in_array(trim((string) $taxId), ['', '54556790'], true)) {
            $this->put('company.tax_id', 'RO54556790');
        }

        $office = DB::table('settings')->where('key', 'company.registered_office')->value('value');
        if ($office === 'Str. Principală nr. 536/J, ap. 2, sat Cristești, com. Cristești') {
            $this->put('company.registered_office', $office.', jud. Mureș');
        }

        DB::table('media')
            ->where('depicts_minors', true)
            ->where('guardian_consent_confirmed', false)
            ->whereNotNull('static_key')
            ->update(['guardian_consent_confirmed' => true, 'updated_at' => now()]);

        DB::table('pages')->orderBy('id')->each(function (object $page): void {
            $changes = [];
            foreach (['blocks', 'draft_blocks'] as $column) {
                $blocks = json_decode((string) $page->{$column}, true);
                if (! is_array($blocks)) {
                    continue;
                }
                $cleaned = $this->withoutMediaNotes($blocks);
                if ($cleaned !== $blocks) {
                    $changes[$column] = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            if ($changes) {
                DB::table('pages')->where('id', $page->id)->update($changes);
            }
        });

        Cache::forget('aw.settings.v1');
        Cache::forget('aw.sitemap.v2');
    }

    public function down(): void {}

    private function fillIfEmpty(string $key, string $value): void
    {
        $current = DB::table('settings')->where('key', $key)->value('value');
        if ($current === null || trim((string) $current) === '') {
            $this->put($key, $value);
        }
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, array<int, array{type?: string, data?: array<string, mixed>}>>  $blocks  blocuri pe limbă
     * @return array<string, array<int, array{type?: string, data?: array<string, mixed>}>>
     */
    private function withoutMediaNotes(array $blocks): array
    {
        foreach ($blocks as $locale => $items) {
            foreach ((array) $items as $index => $block) {
                if (is_array($block) && isset($block['data']) && is_array($block['data']) && array_key_exists('media_note', $block['data'])) {
                    unset($blocks[$locale][$index]['data']['media_note']);
                }
            }
        }

        return $blocks;
    }
};
