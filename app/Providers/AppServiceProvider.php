<?php

namespace App\Providers;

use App\Http\View\SiteComposer;
use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Listeners\AuthenticationAuditSubscriber;
use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Banner;
use App\Modules\Cms\Models\GalleryItem;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Post;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\Lead;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Loyalty\Models\Referral;
use App\Modules\Loyalty\Models\Voucher;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Notifications\Listeners\NotificationSubscriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        $this->configureRateLimits();

        Event::subscribe(NotificationSubscriber::class);
        Event::subscribe(AuthenticationAuditSubscriber::class);

        View::composer(['site.*'], SiteComposer::class);
        Blade::componentNamespace('App\\View\\Components', 'aw');

        // În dezvoltare semnalăm atributele ignorate la mass-assignment (greșeli de nume de coloană).
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Nume stabile pentru relațiile polimorfice (nu depind de namespace-ul clasei).
        Relation::enforceMorphMap([
            'user' => User::class,
            'customer' => Customer::class,
            'booking' => Booking::class,
            'lead' => Lead::class,
            'page' => Page::class,
            'post' => Post::class,
            'game' => Game::class,
            'package' => Package::class,
            'media' => Media::class,
            'gallery_item' => GalleryItem::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'voucher' => Voucher::class,
            'referral' => Referral::class,
            'goods_receipt' => GoodsReceipt::class,
            'inventory_count' => InventoryCount::class,
            'purchase_invoice' => PurchaseInvoice::class,
            'setting' => Setting::class,
            'integration' => Integration::class,
            'legal_document' => LegalDocument::class,
            'campaign' => Campaign::class,
            'fiscal_receipt' => FiscalReceipt::class,
            'resource' => \App\Modules\Booking\Models\Resource::class,
            'banner' => Banner::class,
        ]);
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('booking-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('public-forms', fn (Request $request) => [
            Limit::perMinute(6)->by('form:'.$request->ip()),
            Limit::perHour(40)->by('form-h:'.$request->ip()),
        ]);
        RateLimiter::for('ai-chat', fn (Request $request) => [
            Limit::perMinute((int) config('aw.ai.requests_per_minute_per_ip', 8))->by('ai:'.$request->ip()),
            Limit::perDay(300)->by('ai-d:'.$request->ip()),
        ]);
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('fiscal-agent', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
