<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-mailurile de rezervare primesc linkul „Plătește acum online”, imediat după total. Linkul apare doar când plata
 * online e posibilă (Stripe configurat, sumă de plată); altfel rândul lipsește din e-mail.
 */
return new class extends Migration
{
    private const LINES = [
        'ro' => '[Plătește acum online cu cardul ({{ booking.pay_amount }})]({{ booking.pay_url }})',
        'en' => '[Pay online now by card ({{ booking.pay_amount }})]({{ booking.pay_url }})',
        'hu' => '[Fizetés most online, kártyával ({{ booking.pay_amount }})]({{ booking.pay_url }})',
    ];

    public function up(): void
    {
        $templates = DB::table('notification_templates')->where('channel', 'email')->where('key', 'like', 'booking.%')
            ->where('body', 'like', '%{{ booking.total }}%')->where('body', 'not like', '%booking.pay_url%')->get(['id', 'locale', 'body']);
        foreach ($templates as $template) {
            $line = self::LINES[$template->locale] ?? self::LINES['ro'];
            $body = preg_replace('/^(.*\{\{ booking\.total \}\}.*)$/m', '$1'."\n".$line, $template->body, 1);
            DB::table('notification_templates')->where('id', $template->id)->update(['body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::LINES as $line) {
            DB::table('notification_templates')->where('body', 'like', '%booking.pay_url%')->get(['id', 'body'])
                ->each(fn ($t) => DB::table('notification_templates')->where('id', $t->id)->update(['body' => str_replace("\n".$line, '', $t->body)]));
        }
    }
};
