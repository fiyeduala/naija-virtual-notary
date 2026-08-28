<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Naija Virtual Notary admin panel, served at /admin-panel.
 *
 * Access is gated to users with the admin role (see canAccessPanel on the User
 * model in the integration notes). The brand color pulls from the same palette
 * idea as the sitewide theme — adjust the primary hex once you set the brand.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin-panel')
            ->login()
            ->brandName('Naija Virtual Notary')
            // Closures, not values: the panel is built once per request, and an
            // admin who has just uploaded a logo should see it without the
            // config cache being cleared. Both fall back to the brand name and
            // Filament's own icon when nothing has been uploaded.
            ->brandLogo(fn () => \App\Support\Branding::logoUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn () => \App\Support\Branding::iconUrl())
            ->colors([
                'primary' => Color::hex('#54B435'),
            ])
            ->font('Poppins')
            // The push opt-in and the install button, in the panel's own topbar.
            // Admins live in here rather than on the /notary screens, and an
            // opt-in they never see is an opt-in nobody ever takes.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('partials.pwa-head'),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): View => view('partials.push-toggle', ['variant' => 'panel']),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The admin is also the platform's notary. The notarization tools
            // (verification call, editor, seal placement) live outside the panel
            // on the /notary screens, so the sidebar links straight into them
            // rather than duplicating the whole workflow here.
            ->navigationItems([
                NavigationItem::make('Notarization desk')
                    ->url(fn () => route('notary.dashboard'))
                    ->icon('heroicon-o-pencil-square')
                    ->group('Requests & sessions')
                    ->sort(0)
                    ->isActiveWhen(fn () => request()->routeIs('notary.*')
                        && ! request()->routeIs('notary.offsite.*')),

                // Offsite work has no marketplace request behind it and appears
                // in no queue on the desk, so without a link of its own an admin
                // has no way to reach it — the only other entrance is a notary's
                // dashboard, and admins work in here.
                NavigationItem::make('Offsite notarization')
                    ->url(fn () => route('notary.offsite.index'))
                    ->icon('heroicon-o-briefcase')
                    ->group('Requests & sessions')
                    ->sort(1)
                    ->isActiveWhen(fn () => request()->routeIs('notary.offsite.*')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
