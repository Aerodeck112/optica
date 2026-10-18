<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\UseStaffLocale;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Order;
use App\Models\Patient;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Support\Access\AccessControl;
use App\Support\Access\PermissionCatalog;
use App\Support\Translation\OverrideFileLoader;
use App\Support\Translation\RecordingTranslator;
use App\Support\Translation\TranslationCatalog;
use App\Support\Translation\TranslationOverrides;
use App\Support\Translation\TranslationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per request (and per queued job): permission caches never leak between users.
        $this->app->scoped(AccessControl::class);

        $this->app->singleton(TranslationOverrides::class);
        $this->app->scoped(TranslationRecorder::class);
        $this->app->scoped(TranslationCatalog::class);
        // Same translator as Laravel's, able to report displayed texts in translation mode (TRD-01).
        $this->app->extend('translator', function ($translator, $app) {
            $recording = new RecordingTranslator($app['translation.loader'], $translator->getLocale());
            $recording->setFallback($translator->getFallback());

            return $recording;
        });
        // Database corrections on top of the shipped language files (D-008).
        $this->app->extend('translation.loader', function (FileLoader $loader, $app) {
            $overrideLoader = new OverrideFileLoader($app['files'], $loader->paths());
            foreach ($loader->jsonPaths() as $path) {
                $overrideLoader->addJsonPath($path);
            }
            foreach ($loader->namespaces() as $namespace => $hint) {
                $overrideLoader->addNamespace($namespace, $hint);
            }

            return $overrideLoader;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Short, stable names for polymorphic links (tasks, audit), independent of class names.
        Relation::morphMap([
            'lead' => Lead::class, 'patient' => Patient::class, 'appointment' => Appointment::class,
            'order' => Order::class, 'service_charge' => ServiceCharge::class,
        ]);

        // Livewire update requests re-run these, so a component never escapes auth, 2FA or the staff locale.
        Livewire::addPersistentMiddleware([
            UseStaffLocale::class,
            EnsureActiveUser::class,
            EnsureTwoFactor::class,
        ]);

        ResetPassword::createUrlUsing(fn (User $user, string $token) => route('crm.password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        // Catalog permissions ("module.action") are resolved with deny-by-default and location scope.
        Gate::before(function (User $user, string $ability, array $arguments) {
            $scope = $arguments[0] ?? null;
            // With a model argument, the model's Policy decides (it also checks the permission).
            if (! PermissionCatalog::exists($ability) || ($scope !== null && ! $scope instanceof Location && ! is_int($scope))) {
                return null;
            }

            return app(AccessControl::class)->allows($user, $ability, $scope);
        });
    }
}
