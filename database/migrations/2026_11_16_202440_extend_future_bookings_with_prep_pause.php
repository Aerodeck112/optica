<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pauza de pregătire (implicit 20 de minute) se aplică și rezervărilor viitoare deja făcute: intervalul ocupat al
 * fiecărei rezervări active se prelungește până la sfârșitul jocului plus pauza, ca nimeni să nu se poată programa în ea.
 * Rezervările existente nu se mută; dacă două erau deja lipite, apar în calendar una lângă alta, ca până acum.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prep = (int) (DB::table('settings')->where('key', 'booking.prep_minutes')->value('value') ?? 20);
        if ($prep <= 0) {
            return;
        }
        $rows = DB::table('booking_resources as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->join('packages as p', 'p.id', '=', 'b.package_id')
            ->where('br.is_active', true)
            ->where('b.starts_at', '>', now())
            ->whereNotIn('b.status', ['cancelled', 'expired', 'completed', 'no_show'])
            ->get(['br.id', 'br.ends_at', 'p.buffer_after_minutes']);
        foreach ($rows as $row) {
            // intervalul ocupat = joc (+ timp suplimentar) + pauza pachetului; pauza devine cea mai mare dintre cele două
            $packageBuffer = (int) $row->buffer_after_minutes;
            $extra = max(0, $prep - $packageBuffer);
            $wanted = CarbonImmutable::parse($row->ends_at)->addMinutes($extra);
            if ($extra > 0) {
                DB::table('booking_resources')->where('id', $row->id)->update(['ends_at' => $wanted, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // intervalele prelungite rămân (pauza nu se scoate retroactiv)
    }
};
