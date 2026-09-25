<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Filament\Pages\Tenancy\SelectCompany;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            // The current company lives in the URL, not only in the session:
            // every company-scoped screen sits under /admin/{company}. Held in
            // session alone, one URL would show different companies to the
            // same person and a second tab would fight the first.
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(CompanySettings::class)
            ->tenantMenuItems([
                MenuItem::make()
                    ->label(fn (): string => __('company.actions.all'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    // The company picker, which has taken over /admin.
                    ->url(fn (): string => url($panel->getPath())),
                MenuItem::make()
                    ->label(fn (): string => __('company.actions.manage'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    // A closure so the URL is built when the menu renders and
                    // a tenant is in the route.
                    ->url(fn (): string => CompanyResource::getUrl('index')),
            ])
            // With no company in the URL — only on /admin — Filament's company
            // menu cannot render: it passes the null tenant to getTenantName().
            // A placeholder menu takes its place.
            ->tenantMenu(fn (): bool => Filament::getTenant() !== null)
            // Every navigation URL needs a company. An empty builder keeps the
            // sidebar and drops its links; navigation(false) would drop the
            // sidebar itself.
            ->navigation(fn (): NavigationBuilder|bool => Filament::getTenant() === null
                ? new NavigationBuilder
                : true)
            // SIDEBAR_START, not SIDEBAR_NAV_START: inside the nav the menu
            // inherits the nav's padding and scrollbar gutter. Here it lands in
            // the exact box Filament's own company menu occupies on desktop.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_START,
                fn (): string => Filament::getTenant() === null
                    ? view('filament.company-picker-menu', ['companies' => SelectCompany::getCompanies()])->render()
                    : '',
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
