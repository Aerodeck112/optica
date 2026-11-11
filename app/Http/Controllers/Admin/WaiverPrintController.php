<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\PlayerWaivers\WaiverCodeActions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\LegalController;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Services\WaiverCodeService;
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

    /** Bilețelele cu codurile pentru tabletă (câte unul pentru fiecare jucător), fără date personale. */
    public function codes(Request $request, WaiverCodeService $codes): Response
    {
        abort_unless(WaiverCodeActions::canIssue(), 403);
        $booking = $request->filled('booking') ? Booking::query()->findOrFail((int) $request->query('booking')) : null;
        $session = ! $booking && $request->filled('session') ? GameSession::query()->findOrFail((int) $request->query('session')) : null;

        return response()->view('admin.waiver-codes', [
            'codes' => $codes->active($booking, $session)->whereNull('waiver_card_id')->values(),
            'booking' => $booking,
            'tabletUrl' => lroute('waiver.tablet', [], 'ro'),
        ])->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store, private');
    }
}
