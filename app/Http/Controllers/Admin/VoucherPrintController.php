<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Loyalty\Models\Voucher;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Varianta tipăribilă (A5) a unui voucher, pentru recepție. */
class VoucherPrintController extends Controller
{
    public function __invoke(Request $request, Voucher $voucher): Response
    {
        abort_unless($request->user()?->can('vouchers.manage'), 403);
        app(AuditLogger::class)->log('voucher.printed', $voucher);

        return self::render($voucher);
    }

    /** Pagina voucherului (folosită și pentru voucherele cumpărate online, prin link semnat). */
    public static function render(Voucher $voucher): Response
    {
        $qr = (new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString($voucher->code);
        $terms = $voucher->legal_document_version_id ? LegalDocument::query()->find($voucher->legal_document_version_id) : LegalDocument::current('voucher_terms');

        return response()->view('admin.voucher-print', ['voucher' => $voucher->load('package'), 'qr' => $qr, 'terms' => $terms])
            ->header('X-Robots-Tag', 'noindex');
    }
}
