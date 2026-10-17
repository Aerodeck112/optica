<?php

namespace App\Providers;

use App\Models\Location;
use App\Models\User;
use App\Support\Access\AccessControl;
use App\Support\Access\PermissionCatalog;
use App\Support\Translation\OverrideFileLoader;
use App\Support\Translation\TranslationOverrides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;

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
