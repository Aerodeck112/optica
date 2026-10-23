<?php

namespace App\Providers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
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
        // În dezvoltare semnalăm accesul la atribute inexistente și lazy-loading-ul (probleme de performanță).
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
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
}
