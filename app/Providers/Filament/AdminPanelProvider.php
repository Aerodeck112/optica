<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\RequireMfaForRole;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable(), EmailAuthentication::make()],
                isRequired: true,
            )
            // obligativitatea MFA este decisă per rol (User::MFA_REQUIRED_ROLES), nu global
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireMfaForRole::class)
            ->brandName('Another World Cluj')
            ->brandLogo(asset('media/brand/aw-logo-horizontal-dark.svg'))
            ->darkModeBrandLogo(asset('media/brand/aw-logo-horizontal.svg'))
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->colors([
                'primary' => array_map(fn (string $hex) => Color::convertToOklch($hex), [
                    50 => '#fff1f3', 100 => '#ffe0e5', 200 => '#ffc6d0', 300 => '#ff9cad', 400 => '#fb5f7c', 500 => '#f0325a',
                    600 => '#e61c4a', 700 => '#c01039', 800 => '#a01136', 900 => '#881334', 950 => '#4c0418',
                ]),
                'gray' => Color::Slate,
                'info' => Color::Violet,
            ])
            ->font('Onest', url: asset('css/aw-admin.css'), provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<meta name="robots" content="noindex, nofollow">'))
            ->renderHook(PanelsRenderHook::BODY_START, fn () => config('aw.is_staging') || app()->environment('local')
                ? new HtmlString('<div style="background:#7c3aed;color:#fff;text-align:center;font-size:.8125rem;padding:.25rem">Mediu '.e(app()->environment()).' — mesajele către clienți rulează în sandbox</div>')
                : '')
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups([
                NavigationGroup::make('Recepție'),
                NavigationGroup::make('CRM'),
                NavigationGroup::make('Conținut site'),
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Fidelizare'),
                NavigationGroup::make('Financiar'),
                NavigationGroup::make('Stocuri'),
                NavigationGroup::make('Asistent AI'),
                NavigationGroup::make('Configurare')->collapsed(),
                NavigationGroup::make('Sistem')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
