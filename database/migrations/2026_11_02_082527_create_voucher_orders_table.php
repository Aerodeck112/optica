<?php

use App\Modules\Cms\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comenzile online de vouchere: cumpărătorul plătește prin procesatorul de plăți, iar voucherul se emite
 * automat la confirmarea plății (webhook). Butoanele de pe pagina „Vouchere cadou” duc la formularul de comandă.
 */
return new class extends Migration
{
    public const EMAIL_SUBJECT = 'Voucherul tău Another World Cluj: {{ voucher.name }}';

    public const EMAIL_BODY = "Bună, {{ customer.first_name }}!\n\nÎți mulțumim pentru comandă. Voucherul cadou este gata:\n\n**Voucher:** {{ voucher.name }}\n**Cod:** {{ voucher.code }}\n**Pentru:** {{ voucher.recipient }}\n**Valabil până la:** {{ voucher.valid_until }}\n\n[Descarcă sau printează voucherul]({{ voucher.url }})\n\nPentru folosire, destinatarul rezervă din timp, în funcție de disponibilitate, și comunică codul voucherului. Voucherul nu se returnează și nu se schimbă pe bani.\n\nTe așteptăm,\nEchipa Another World Cluj";

    public function up(): void
    {
        Schema::create('voucher_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('product', 20);                    // vr | doi | squad | privat
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('participants');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RON');
            $table->string('buyer_name', 120);
            $table->string('buyer_email', 190);
            $table->string('buyer_phone', 40)->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->string('message', 300)->nullable();
            $table->string('status', 12)->default('pending'); // pending | issued | cancelled
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'voucher.purchased', 'channel' => 'email', 'locale' => 'ro'],
            ['subject' => self::EMAIL_SUBJECT, 'body' => self::EMAIL_BODY, 'category' => 'service', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );

        // butoanele de comandă de pe pagina de vouchere duc la formularul de comandă online
        $page = Page::query()->where('key', 'vouchers')->first();
        if ($page) {
            $products = ['Voucher VR' => 'vr', 'Voucher pentru doi' => 'doi', 'Voucher Squad' => 'squad', 'Experiență privată' => 'privat'];
            foreach (['blocks', 'draft_blocks'] as $column) {
                $blocks = (array) $page->{$column};
                if (! isset($blocks['ro'])) {
                    continue;
                }
                foreach ($blocks['ro'] as $i => $block) {
                    $data = (array) ($block['data'] ?? []);
                    foreach (['primary_link'] as $field) {
                        if (($data[$field] ?? null) === 'page:contact') {
                            $blocks['ro'][$i]['data'][$field] = '/voucher/comanda';
                        }
                    }
                    foreach ((array) ($data['items'] ?? []) as $j => $item) {
                        foreach ($products as $prefix => $key) {
                            if (str_starts_with((string) ($item['title'] ?? ''), $prefix) && ($item['link'] ?? null) === 'page:contact') {
                                $blocks['ro'][$i]['data']['items'][$j]['link'] = '/voucher/comanda?tip='.$key;
                            }
                        }
                    }
                }
                $page->{$column} = $blocks;
            }
            $page->save();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_orders');
    }
};
