<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Admin\VoucherPrintController;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoucherOrderRequest;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Finance\Services\Payments\PaymentException;
use App\Modules\Loyalty\Models\VoucherOrder;
use App\Modules\Loyalty\Services\VoucherOrderService;
use App\Support\Locales;
use Illuminate\Http\Request;

/** Comanda online a unui voucher cadou: formular → plată → pagina comenzii cu voucherul de descărcat. */
class VoucherOrderController extends Controller
{
    public function __construct(private readonly VoucherOrderService $orders) {}

    public function create(Request $request)
    {
        $products = $this->orders->products();
        $selected = array_key_exists((string) $request->query('tip'), $products) ? (string) $request->query('tip') : array_key_first($products);

        return view('site.vouchers.order', [
            'products' => $products,
            'selected' => old('product', $selected),
            'online' => $this->orders->onlineAvailable(),
            'seo' => Seo::make(__('site.voucher_order.title'), __('site.voucher_order.seo_description'))
                ->alternates(collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('voucher.order.create', [], $l)])->all()),
        ]);
    }

    public function store(StoreVoucherOrderRequest $request)
    {
        if ($request->isSpam()) {
            return back()->withInput()->withErrors(['buyer_name' => __('site.forms.sent_error')]);
        }

        try {
            $order = $this->orders->start($request->only(['product', 'buyer_name', 'buyer_email', 'buyer_phone', 'recipient_name', 'message', 'locale']));
        } catch (PaymentException $e) {
            return back()->withInput()->withErrors(['product' => $e->getMessage()]);
        }

        return redirect()->away($order->payment->checkout_url);
    }

    public function show(Request $request, VoucherOrder $order)
    {
        return response()->view('site.vouchers.order-status', [
            'order' => $order->load(['voucher', 'payment']),
            'label' => $this->orders->label($order),
            'downloadUrl' => $order->voucher ? $this->orders->downloadUrl($order) : null,
            'paymentReturned' => $request->boolean('paid'),
            'seo' => Seo::make(__('site.voucher_order.status_title'))->noindex(),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function download(VoucherOrder $order)
    {
        abort_unless($order->voucher, 404);

        return VoucherPrintController::render($order->voucher)->header('Referrer-Policy', 'no-referrer');
    }
}
