<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\Payments\PaymentException;
use App\Modules\Finance\Services\Payments\PaymentGatewayFactory;
use App\Modules\Loyalty\Models\VoucherOrder;
use App\Modules\Notifications\Services\Notifier;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Vânzarea online a voucherelor cadou: comandă → plată la procesator → voucher emis automat la confirmarea plății.
 * Prețul vine din pachetul pe care îl acoperă voucherul (sesiunea VR × persoane sau arena privată).
 */
class VoucherOrderService
{
    /** @var array<string, array{label: string, people: int, package: string, text: string}> */
    public const PRODUCTS = [
        'vr' => ['label' => 'Voucher VR', 'people' => 1, 'package' => 'sesiune-vr', 'text' => 'Pentru 1 persoană'],
        'doi' => ['label' => 'Voucher pentru doi', 'people' => 2, 'package' => 'sesiune-vr', 'text' => 'Pentru 2 persoane'],
        'squad' => ['label' => 'Voucher Squad', 'people' => 4, 'package' => 'sesiune-vr', 'text' => 'Pentru până la 4 persoane'],
        'privat' => ['label' => 'Experiență privată', 'people' => 8, 'package' => 'arena-privata', 'text' => 'Până la 8 persoane, arena doar pentru voi'],
    ];

    public const VALID_MONTHS = 6;

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly VoucherService $vouchers,
        private readonly Notifier $notifier,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function onlineAvailable(): bool
    {
        return $this->gateways->active() !== null && $this->products() !== [];
    }

    /** @return array<string, array{label: string, people: int, text: string, price_minor: int, package: Package}> produsele cu pachet activ și preț */
    public function products(): array
    {
        $packages = Package::query()->active()->get()->keyBy(fn (Package $p) => $p->getTranslation('slug', 'ro'));
        $products = [];
        foreach (self::PRODUCTS as $key => $product) {
            $package = $packages[$product['package']] ?? null;
            if (! $package || ! $package->hasPrice()) {
                continue;
            }
            $price = $package->pricing_model === 'per_person' ? (int) $package->price_minor * $product['people'] : (int) $package->price_minor;
            $products[$key] = array_merge($product, ['price_minor' => $price, 'package' => $package]);
        }

        return $products;
    }

    /**
     * Creează comanda și sesiunea de plată. Întoarce comanda cu plata (link de checkout).
     *
     * @param  array{product: string, buyer_name: string, buyer_email: string, buyer_phone?: ?string, recipient_name?: ?string, message?: ?string}  $data
     */
    public function start(array $data): VoucherOrder
    {
        $gateway = $this->gateways->active() ?? throw new PaymentException('unavailable', 'Plata online nu este disponibilă momentan.');
        $product = $this->products()[$data['product']] ?? throw new PaymentException('product', 'Voucherul ales nu este disponibil momentan.');

        [$order, $payment] = DB::transaction(function () use ($data, $product, $gateway) {
            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'method' => 'online',
                'purpose' => 'voucher_sale',
                'amount_minor' => $product['price_minor'],
                'currency' => 'RON',
                'status' => 'pending',
                'provider' => $gateway->name(),
                'note' => $product['label'],
            ]);
            $order = VoucherOrder::query()->create([
                'uuid' => (string) Str::uuid(),
                'product' => $data['product'],
                'package_id' => $product['package']->id,
                'participants' => $product['people'],
                'amount_minor' => $product['price_minor'],
                'buyer_name' => trim($data['buyer_name']),
                'buyer_email' => trim($data['buyer_email']),
                'buyer_phone' => filled($data['buyer_phone'] ?? null) ? trim($data['buyer_phone']) : null,
                'recipient_name' => filled($data['recipient_name'] ?? null) ? trim($data['recipient_name']) : null,
                'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
                'locale' => in_array($data['locale'] ?? null, Locales::enabled(), true) ? $data['locale'] : Locales::DEFAULT,
                'payment_id' => $payment->id,
            ]);

            return [$order, $payment];
        });

        try {
            $session = $gateway->createCheckout(
                $payment,
                'Another World Cluj — '.$this->label($order).' (voucher)',
                URL::signedRoute($order->locale.'.voucher.order.show', ['order' => $order->uuid, 'paid' => 1]),
                lroute('voucher.order.create', [], $order->locale).'?tip='.$order->product,
            );
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => 'failed', 'note' => 'Sesiunea de plată nu a putut fi creată'])->save();
            $order->forceFill(['status' => 'cancelled'])->save();
            report($e);

            throw new PaymentException('provider', 'Plata online nu a putut fi pornită. Încearcă din nou sau scrie-ne.');
        }
        $payment->forceFill(['checkout_url' => $session['url'], 'provider_reference' => $session['reference'], 'expires_at' => $session['expires_at']])->save();

        return $order->setRelation('payment', $payment);
    }

    /** Emite voucherul pentru o plată de voucher confirmată. Idempotent: o comandă se emite o singură dată. */
    public function fulfil(Payment $payment): ?VoucherOrder
    {
        $order = DB::transaction(function () use ($payment) {
            $order = VoucherOrder::query()->where('payment_id', $payment->id)->lockForUpdate()->first();
            if (! $order || $order->voucher_id || $payment->status !== 'succeeded') {
                return null;
            }
            $voucher = $this->vouchers->issue([
                'type' => 'package',
                'package_id' => $order->package_id,
                'package_participants' => $order->participants,
                'allows_partial_use' => false,
                'valid_from' => now()->toDateString(),
                'valid_until' => now()->addMonthsNoOverflow(self::VALID_MONTHS)->toDateString(),
                'origin' => 'sale',
                'recipient_name' => $order->recipient_name,
                'message' => $order->message,
                'legal_document_version_id' => LegalDocument::current('voucher_terms')?->id,
            ]);
            $order->forceFill(['voucher_id' => $voucher->id, 'status' => 'issued', 'issued_at' => now()])->save();
            $this->audit->log('voucher.sold_online', $voucher, ['order' => $order->uuid, 'amount_minor' => $order->amount_minor]);

            return $order;
        });

        if ($order) {
            $this->notify($order->load('voucher.package'));
        }

        return $order;
    }

    public function downloadUrl(VoucherOrder $order): string
    {
        return URL::signedRoute(($order->locale ?: Locales::DEFAULT).'.voucher.order.download', ['order' => $order->uuid]);
    }

    /** Denumirea voucherului în limba comenzii. */
    public function label(VoucherOrder $order): string
    {
        $key = 'site.voucher_order.products.'.$order->product.'.label';

        return trans()->has($key) ? __($key, [], $order->locale ?: Locales::DEFAULT) : (self::PRODUCTS[$order->product]['label'] ?? 'Voucher');
    }

    private function notify(VoucherOrder $order): void
    {
        try {
            $this->notifier->sendToAddress('email', $order->buyer_email, 'voucher.purchased', ['voucher_order' => $order], 'service', 'voucher-order:'.$order->uuid, $order->locale);
            $staff = $this->settings->filled('contact.notifications_staff');
            if ($staff) {
                $this->notifier->sendRaw('email', $staff, 'Voucher vândut online: '.$this->label($order).' — '.money($order->amount_minor),
                    "Cod: **{$order->voucher?->code}**\n\nCumpărător: {$order->buyer_name} ({$order->buyer_email})\n\nVoucherul apare în panou la Fidelizare → Vouchere cadou.",
                    'service', null, 'voucher-order-staff:'.$order->uuid);
            }
        } catch (\Throwable $e) {
            // voucherul este deja emis; e-mailul se poate retrimite din panou
            report($e);
        }
    }
}
