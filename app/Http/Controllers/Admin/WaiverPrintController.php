<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\LegalController;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Cms\Services\SafeMarkdown;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Declarația semnată (pentru dosar sau o reclamație) și afișul cu cod QR pentru recepție. */
class WaiverPrintController extends Controller
{
    public function waiver(Request $request, PlayerWaiver $waiver): Response
    {
        abort_unless($request->user()?->can('waivers.view'), 403);
        app(AuditLogger::class)->log('waiver.printed', $waiver);
        $document = $waiver->document;
        $body = $document ? SafeMarkdown::render(LegalController::fillPlaceholders((string) $document->getTranslation('body', $waiver->locale))) : '';

        return response()->view('admin.waiver-print', ['waiver' => $waiver->load('booking'), 'document' => $document, 'body' => $body])
            ->header('X-Robots-Tag', 'noindex');
    }

    public function qr(Request $request): Response
    {
        abort_unless($request->user()?->can('bookings.checkin') || $request->user()?->can('waivers.view'), 403);
        $writer = new Writer(new ImageRenderer(new RendererStyle(420, 1), new SvgImageBackEnd));
        $urls = ['ro' => lroute('waiver.show', [], 'ro'), 'en' => lroute('waiver.show', [], 'en')];

        return response()->view('admin.waiver-qr', ['urls' => $urls, 'qr' => array_map(fn ($url) => $writer->writeString($url), $urls)])
            ->header('X-Robots-Tag', 'noindex');
    }
}
