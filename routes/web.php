<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LocaleController;
use App\Http\Controllers\Crm\PatientFileController;
use App\Http\Controllers\Site\AvailabilityController;
use App\Http\Controllers\Site\SitePageController;
use App\Http\Controllers\Site\UnsubscribeController;
use App\Http\Controllers\Webhooks\MessagingWebhookController;
use App\Livewire\Admin\ClinicalTemplates;
use App\Livewire\Admin\Communication\ChannelAccounts;
use App\Livewire\Admin\Communication\MessageRules;
use App\Livewire\Admin\Communication\MessageTemplates;
use App\Livewire\Admin\ExceptionIndex;
use App\Livewire\Admin\LocationEditor;
use App\Livewire\Admin\LocationIndex;
use App\Livewire\Admin\OfferingEditor;
use App\Livewire\Admin\RoleEditor;
use App\Livewire\Admin\RoleIndex;
use App\Livewire\Admin\ScheduleEditor;
use App\Livewire\Admin\ScheduleIndex;
use App\Livewire\Admin\ServiceEditor;
use App\Livewire\Admin\ServiceIndex;
use App\Livewire\Admin\SpecialistEditor;
use App\Livewire\Admin\SpecialistIndex;
use App\Livewire\Admin\UserEditor;
use App\Livewire\Admin\UserIndex;
use App\Livewire\Cms\MediaManager;
use App\Livewire\Cms\MenuEditor;
use App\Livewire\Cms\OfferEditor;
use App\Livewire\Cms\OfferIndex;
use App\Livewire\Cms\PageEditor;
use App\Livewire\Cms\PageIndex;
use App\Livewire\Cms\RedirectManager;
use App\Livewire\Cms\SiteSettings;
use App\Livewire\Crm\Calendar;
use App\Livewire\Crm\Campaigns;
use App\Livewire\Crm\ClinicalRecordEditor;
use App\Livewire\Crm\LeadIndex;
use App\Livewire\Crm\LeadShow;
use App\Livewire\Crm\MessageLog;
use App\Livewire\Crm\OrderEditor;
use App\Livewire\Crm\OrderIndex;
use App\Livewire\Crm\PatientIndex;
use App\Livewire\Crm\PatientShow;
use App\Livewire\Crm\PaymentIndex;
use App\Livewire\Crm\TaskList;
use App\Livewire\Site\BookingWizard;
use App\Livewire\Site\ManageBooking;
use Illuminate\Support\Facades\Route;

/*
 | Public site: Romanian without prefix, Hungarian under /hu (D-011). Each route exists twice,
 | named "site.x" and "site.hu.x".
 */
foreach (['' => 'site.', 'hu' => 'site.hu.'] as $prefix => $name) {
    Route::prefix($prefix)->name($name)->middleware('site.locale')->group(function () {
        Route::livewire('programare', BookingWizard::class)->name('booking');
        Route::livewire('programare/gestionare/{token}', ManageBooking::class)->name('booking.manage')->middleware('throttle:60,1');
        Route::get('servicii/{code}', [SitePageController::class, 'service'])->name('service');
        Route::get('medici/{code}', [SitePageController::class, 'specialist'])->name('specialist');
        Route::get('puncte-lucru/{code}', [SitePageController::class, 'location'])->name('location');
        Route::get('reduceri-actuale/{slug}', [SitePageController::class, 'offer'])->name('offer');
        Route::get('previzualizare/pagina/{page}', [SitePageController::class, 'preview'])->middleware(['auth', 'active', 'two-factor'])->name('preview');
    });
}
// Provider callbacks: authenticated by signature, not by session or CSRF (COM-03).
Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:240,1')->group(function () {
    Route::get('whatsapp/{account}', [MessagingWebhookController::class, 'whatsappVerify'])->name('whatsapp.verify');
    Route::post('whatsapp/{account}', [MessagingWebhookController::class, 'whatsapp'])->name('whatsapp');
    Route::post('twilio/{account}', [MessagingWebhookController::class, 'twilio'])->name('twilio');
});
Route::get('dezabonare/{uuid}', [UnsubscribeController::class, 'show'])->middleware(['signed', 'throttle:30,1'])->name('unsubscribe.show');
Route::post('dezabonare/{uuid}', [UnsubscribeController::class, 'store'])->middleware(['signed', 'throttle:30,1'])->name('unsubscribe.store');
Route::get('api/disponibilitate', AvailabilityController::class)->middleware('throttle:60,1')->name('api.availability');
Route::get('sitemap.xml', [SitePageController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitePageController::class, 'robots'])->name('robots');

/*
 | Staff area. Language comes from the employee profile (LNG-02).
 */
Route::prefix('crm')->name('crm.')->middleware('staff.locale')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:30,1')->name('login.store');
        Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
        Route::get('two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.verify');
    });

    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('two-factor/verify', [TwoFactorController::class, 'challenge'])->name('two-factor.reverify');
        Route::post('two-factor/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.reverify.store');
        Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
        Route::post('two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,1')->name('two-factor.confirm');

        Route::middleware('two-factor')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::post('locale', LocaleController::class)->name('locale');
            Route::livewire('calendar', Calendar::class)->name('calendar');
            Route::livewire('patients', PatientIndex::class)->name('patients.index');
            Route::livewire('patients/{patient}', PatientShow::class)->name('patients.show');
            Route::livewire('records/{record}', ClinicalRecordEditor::class)->name('records.edit');
            Route::get('records/{record}/pdf', [PatientFileController::class, 'recordPdf'])->middleware('throttle:30,1')->name('records.pdf');
            Route::get('prescriptions/{prescription}/pdf', [PatientFileController::class, 'prescriptionPdf'])->middleware('throttle:30,1')->name('prescriptions.pdf');
            Route::get('documents/{document}', [PatientFileController::class, 'document'])->middleware('throttle:60,1')->name('documents.download');
            Route::livewire('leads', LeadIndex::class)->name('leads.index');
            Route::livewire('leads/{lead}', LeadShow::class)->name('leads.show');
            Route::livewire('tasks', TaskList::class)->name('tasks');
            Route::livewire('orders', OrderIndex::class)->name('orders.index');
            Route::livewire('orders/create', OrderEditor::class)->name('orders.create');
            Route::livewire('orders/{order}', OrderEditor::class)->name('orders.edit');
            Route::get('orders/{order}/handover', [PatientFileController::class, 'orderHandover'])->middleware('throttle:30,1')->name('orders.handover');
            Route::livewire('payments', PaymentIndex::class)->name('payments.index');
            Route::livewire('messages', MessageLog::class)->name('messages.index');
            Route::livewire('campaigns', Campaigns::class)->name('campaigns');
        });
    });
});

Route::prefix('admin')->name('admin.')->middleware(['staff.locale', 'auth', 'active', 'two-factor'])->group(function () {
    Route::livewire('locations', LocationIndex::class)->name('locations.index');
    Route::livewire('locations/create', LocationEditor::class)->name('locations.create');
    Route::livewire('locations/{location}', LocationEditor::class)->name('locations.edit');
    Route::livewire('specialists', SpecialistIndex::class)->name('specialists.index');
    Route::livewire('specialists/create', SpecialistEditor::class)->name('specialists.create');
    Route::livewire('specialists/{specialist}', SpecialistEditor::class)->name('specialists.edit');
    Route::livewire('specialists/{specialist}/services', OfferingEditor::class)->name('specialists.services');
    Route::livewire('services', ServiceIndex::class)->name('services.index');
    Route::livewire('services/create', ServiceEditor::class)->name('services.create');
    Route::livewire('services/{service}', ServiceEditor::class)->name('services.edit');
    Route::livewire('schedules', ScheduleIndex::class)->name('schedules.index');
    Route::livewire('schedules/create', ScheduleEditor::class)->name('schedules.create');
    Route::livewire('schedules/{schedule}', ScheduleEditor::class)->name('schedules.edit');
    Route::livewire('exceptions', ExceptionIndex::class)->name('exceptions.index');
    Route::livewire('users', UserIndex::class)->name('users.index');
    Route::livewire('users/create', UserEditor::class)->name('users.create');
    Route::livewire('users/{user}', UserEditor::class)->name('users.edit')->withTrashed();
    Route::livewire('roles', RoleIndex::class)->name('roles.index');
    Route::livewire('roles/create', RoleEditor::class)->name('roles.create');
    Route::livewire('roles/{role}', RoleEditor::class)->name('roles.edit');
    Route::livewire('clinical-templates', ClinicalTemplates::class)->name('clinical-templates');
    Route::livewire('communication/channels', ChannelAccounts::class)->name('communication.channels');
    Route::livewire('communication/templates', MessageTemplates::class)->name('communication.templates');
    Route::livewire('communication/rules', MessageRules::class)->name('communication.rules');
});

/*
 | Site content (CMS). Each component checks its own permission on every request.
 */
Route::prefix('cms')->name('cms.')->middleware(['staff.locale', 'auth', 'active', 'two-factor'])->group(function () {
    Route::livewire('pages', PageIndex::class)->name('pages.index');
    Route::livewire('pages/{page}', PageEditor::class)->name('pages.edit');
    Route::livewire('media', MediaManager::class)->name('media');
    Route::livewire('menus', MenuEditor::class)->name('menus');
    Route::livewire('offers', OfferIndex::class)->name('offers.index');
    Route::livewire('offers/create', OfferEditor::class)->name('offers.create');
    Route::livewire('offers/{offer}', OfferEditor::class)->name('offers.edit')->withTrashed();
    Route::livewire('redirects', RedirectManager::class)->name('redirects');
    Route::livewire('settings', SiteSettings::class)->name('settings');
});

/*
 | CMS pages: registered last so they never shadow the routes above.
 */
Route::prefix('hu')->name('site.hu.')->middleware('site.locale')->group(function () {
    Route::get('{slug?}', [SitePageController::class, 'page'])->where('slug', '[a-z0-9\-]*')->name('page');
});
Route::name('site.')->middleware('site.locale')->group(function () {
    Route::get('{slug?}', [SitePageController::class, 'page'])->where('slug', '(?!hu$)[a-z0-9\-]*')->name('page');
});
