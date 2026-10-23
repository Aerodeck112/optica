<?php

namespace App\Providers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        \Illuminate\Support\Facades\Event::subscribe(\App\Modules\Notifications\Listeners\NotificationSubscriber::class);

        \Illuminate\Support\Facades\View::composer(['site.*'], \App\Http\View\SiteComposer::class);
        \Illuminate\Support\Facades\Blade::componentNamespace('App\\View\\Components', 'aw');

        // În dezvoltare semnalăm atributele ignorate la mass-assignment (greșeli de nume de coloană).
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Nume stabile pentru relațiile polimorfice (nu depind de namespace-ul clasei).
        Relation::enforceMorphMap([
            'user' => \App\Models\User::class,
            'customer' => \App\Modules\Crm\Models\Customer::class,
            'booking' => \App\Modules\Booking\Models\Booking::class,
            'lead' => \App\Modules\Crm\Models\Lead::class,
            'page' => \App\Modules\Cms\Models\Page::class,
            'post' => \App\Modules\Cms\Models\Post::class,
            'game' => \App\Modules\Catalog\Models\Game::class,
            'package' => \App\Modules\Catalog\Models\Package::class,
            'media' => \App\Modules\Cms\Models\Media::class,
            'gallery_item' => \App\Modules\Cms\Models\GalleryItem::class,
            'invoice' => \App\Modules\Finance\Models\Invoice::class,
            'payment' => \App\Modules\Finance\Models\Payment::class,
            'voucher' => \App\Modules\Loyalty\Models\Voucher::class,
            'referral' => \App\Modules\Loyalty\Models\Referral::class,
            'goods_receipt' => \App\Modules\Inventory\Models\GoodsReceipt::class,
            'inventory_count' => \App\Modules\Inventory\Models\InventoryCount::class,
            'purchase_invoice' => \App\Modules\Inventory\Models\PurchaseInvoice::class,
            'setting' => \App\Modules\Core\Models\Setting::class,
            'integration' => \App\Modules\Core\Models\Integration::class,
            'legal_document' => \App\Modules\Cms\Models\LegalDocument::class,
            'campaign' => \App\Modules\Marketing\Models\Campaign::class,
            'fiscal_receipt' => \App\Modules\Finance\Models\FiscalReceipt::class,
            'resource' => \App\Modules\Booking\Models\Resource::class,
            'banner' => \App\Modules\Cms\Models\Banner::class,
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
