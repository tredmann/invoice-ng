<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
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
