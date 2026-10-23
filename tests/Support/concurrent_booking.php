<?php

/**
 * Proces separat folosit de BookingConcurrencyTest: pornește aplicația, așteaptă bariera de timp comună
 * și încearcă o operație de rezervare. Scrie rezultatul ca JSON pe stdout.
 *
 * argv: <operation:create|hold|redeem> <package_id> <starts_at_iso> <barrier_unix_float> <source> <label>
 */

use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use Carbon\CarbonImmutable;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $operation, $packageId, $startsAt, $barrier, $source, $label] = $argv + [null, null, null, null, null, 'web', 'p'];

$package = Package::query()->findOrFail((int) $packageId);
$start = CarbonImmutable::parse($startsAt);

// așteptare activă până la bariera comună, ca toate procesele să lovească baza de date în același moment
while (microtime(true) < (float) $barrier) {
    usleep(200);
}

try {
    if ($operation === 'voucher') {
        $voucher = App\Modules\Loyalty\Models\Voucher::query()->findOrFail((int) $packageId);
        $tx = app(App\Modules\Loyalty\Services\VoucherService::class)->redeem($voucher->code, (int) $startsAt, 'test-'.$label, null);
        echo json_encode(['ok' => true, 'amount' => $tx->amount_minor]);
        exit(0);
    }
    if ($operation === 'points') {
        $customer = App\Modules\Crm\Models\Customer::query()->findOrFail((int) $packageId);
        $entry = app(App\Modules\Loyalty\Services\LoyaltyService::class)->redeemPoints($customer, (int) $startsAt, 'test-'.$label);
        echo json_encode(['ok' => true, 'delta' => $entry->delta]);
        exit(0);
    }
    if ($operation === 'hold') {
        $result = app(BookingService::class)->hold($package, $start, 4, [], $source);
        echo json_encode(['ok' => true, 'hold' => $result['hold']->id]);
        exit(0);
    }

    $result = app(BookingService::class)->create(new BookingData(
        package: $package,
        startsAt: $start,
        participants: 4,
        contactName: 'Proces '.$label,
        contactEmail: "proces-{$label}@example.test",
        contactPhone: null,
        source: $source,
        acceptedPolicyTypes: ['terms', 'booking_policy'],
        ageGroups: ['adults' => 4],
    ));
    echo json_encode(['ok' => true, 'booking' => $result['booking']->code]);
} catch (BookingException $e) {
    echo json_encode(['ok' => false, 'reason' => $e->reason]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'reason' => 'error', 'message' => $e->getMessage()]);
}
