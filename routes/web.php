<?php

use App\Http\Controllers\Admin\AnafOAuthController;
use App\Http\Controllers\Admin\FinanceDocumentController;
use App\Http\Controllers\Admin\VoucherPrintController;
use App\Http\Controllers\Site\AccountController;
use App\Http\Controllers\Site\BookingApiController;
use App\Http\Controllers\Site\BookingController;
use App\Http\Controllers\Site\BookingManageController;
use App\Http\Controllers\Site\ChatController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\GameController;
use App\Http\Controllers\Site\LeadController;
use App\Http\Controllers\Site\LegalController;
use App\Http\Controllers\Site\NewsController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\PreviewController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\UnsubscribeController;
use App\Modules\Marketing\Services\ShortUnsubscribeLink;
use App\Support\Locales;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
| Rute fără limbă: API-ul public al motorului de rezervări, formulare, fișiere tehnice SEO.
*/
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

Route::prefix('api/booking')->middleware('throttle:booking-api')->group(function () {
    Route::get('/slots', [BookingApiController::class, 'slots'])->name('api.booking.slots');
    Route::post('/quote', [BookingApiController::class, 'quote'])->name('api.booking.quote');
    Route::post('/hold', [BookingApiController::class, 'hold'])->name('api.booking.hold');
    Route::post('/release', [BookingApiController::class, 'release'])->name('api.booking.release');
});

Route::middleware('throttle:public-forms')->group(function () {
    Route::post('/forms/booking', [BookingController::class, 'store'])->name('forms.booking');
    Route::post('/forms/lead', [LeadController::class, 'store'])->name('forms.lead');
    Route::post('/forms/contact', [ContactController::class, 'store'])->name('forms.contact');
    Route::post('/forms/account-login', [AccountController::class, 'sendLink'])->name('forms.account-login');
});

Route::post('/api/chat', [ChatController::class, 'message'])->middleware('throttle:ai-chat')->name('api.chat');
Route::post('/api/chat/confirm', [ChatController::class, 'confirm'])->middleware('throttle:ai-chat')->name('api.chat.confirm');

Route::get('/booking/{code}/calendar.ics', [BookingManageController::class, 'ics'])->name('booking.ics');
Route::post('/booking/manage/{token}/pay', [BookingManageController::class, 'pay'])->middleware('throttle:public-forms')->name('booking.manage.pay');
Route::post('/booking/manage/{token}/cancel', [BookingManageController::class, 'cancel'])->middleware('throttle:public-forms')->name('booking.manage.cancel');
Route::post('/booking/manage/{token}/reschedule', [BookingManageController::class, 'reschedule'])->middleware('throttle:public-forms')->name('booking.manage.reschedule');

Route::get('/u/{token}', function (string $token) {
    $resolved = ShortUnsubscribeLink::resolve($token);
    abort_unless($resolved, 404);

    return redirect(URL::signedRoute('unsubscribe.show', ['customer' => $resolved[0], 'channel' => $resolved[1]]));
})->where('token', '[0-9a-z]{10,20}')->middleware('throttle:public-forms')->name('unsubscribe.short');
Route::get('/unsubscribe/{customer}/{channel}', [UnsubscribeController::class, 'show'])->middleware('signed')->name('unsubscribe.show');
Route::post('/unsubscribe/{customer}/{channel}', [UnsubscribeController::class, 'confirm'])->middleware('signed')->name('unsubscribe.confirm');

Route::get('/account/login/{token}', [AccountController::class, 'consumeLink'])->name('account.consume');
Route::post('/account/logout', [AccountController::class, 'logout'])->name('account.logout');
Route::middleware('auth:customer')->group(function () {
    Route::post('/account/preferences', [AccountController::class, 'updatePreferences'])->name('account.preferences');
    Route::post('/account/erasure', [AccountController::class, 'requestErasure'])->name('account.erasure');
    Route::post('/account/bookings/{booking}/manage-link', [AccountController::class, 'resendManageLink'])->middleware('throttle:public-forms')->name('account.manage-link');
});

Route::get('/admin-anaf/authorize', [AnafOAuthController::class, 'authorize'])->middleware('auth')->name('admin.anaf.authorize');
Route::get('/admin-anaf/callback', [AnafOAuthController::class, 'callback'])->middleware('auth')->name('admin.anaf.callback');
Route::get('/admin-print/vouchers/{voucher}', VoucherPrintController::class)->middleware('auth')->name('admin.vouchers.print');
Route::get('/admin-print/invoices/{invoice}/pdf', [FinanceDocumentController::class, 'invoicePdf'])->middleware('auth')->name('admin.invoices.pdf');
Route::get('/admin-print/goods-receipts/{receipt}', [FinanceDocumentController::class, 'goodsReceipt'])->middleware('auth')->name('admin.goods-receipts.print');
Route::get('/admin-print/efactura/{submission}/{kind}', [FinanceDocumentController::class, 'efacturaFile'])->whereIn('kind', ['xml', 'response'])->middleware('auth')->name('admin.efactura.file');
Route::get('/cms-preview/{type}/{id}', PreviewController::class)->middleware(['auth', 'signed'])->name('cms.preview');

/*
| Rute publice pe limbi. Româna nu are prefix; celelalte limbi apar doar când sunt activate.
*/
foreach (Locales::enabled() as $locale) {
    $segment = fn (string $key) => trans('routes.'.$key, [], $locale);

    Route::prefix(Locales::prefix($locale))
        ->middleware("locale:{$locale}")
        ->name($locale.'.')
        ->group(function () use ($segment) {
            Route::get($segment('games'), [GameController::class, 'index'])->name('games.index');
            Route::get($segment('games').'/{slug}', [GameController::class, 'show'])->name('games.show');

            Route::get($segment('booking'), [BookingController::class, 'show'])->name('booking.show');
            Route::get($segment('booking').'/'.$segment('booking_confirmation').'/{code}', [BookingController::class, 'confirmation'])->name('booking.confirmation');
            Route::get($segment('booking').'/'.$segment('booking_manage').'/{token}', [BookingManageController::class, 'show'])->name('booking.manage');

            Route::get($segment('news'), [NewsController::class, 'index'])->name('news.index');
            Route::get($segment('news').'/{slug}', [NewsController::class, 'show'])->name('news.show');

            Route::get($segment('account'), [AccountController::class, 'show'])->name('account.show');

            Route::get($segment('legal').'/{slug}', [LegalController::class, 'show'])->name('legal.show');

            Route::get('/{slug?}', PageController::class)
                ->where('slug', '(?!(u|admin-print|admin-anaf|admin|livewire|filament|api|forms|build|media|storage|up|webhooks|booking|account|unsubscribe|cms-preview|fiscal-agent)$)[a-z0-9\-]+')
                ->name('page');
        });
}
