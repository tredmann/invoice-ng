<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Filament\Pages\Tenancy\SelectCompany;
use App\Livewire\Topbar;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
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
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Home is the company picker: the switcher's Firmen verwalten leads
            // there (the brand logo is hidden, top-bar trail spec §2.1).
            // Filament's default home is the user's default tenant — the first
            // company by name — which would make the choice the picker exists
            // to offer.
            ->homeUrl(fn (): string => url($panel->getPath()))
            // The current company lives in the URL, not only in the session:
            // every company-scoped screen sits under /admin/{company}. Held in
            // session alone, one URL would show different companies to the
            // same person and a second tab would fight the first.
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(CompanySettings::class)
            // Switching companies happens in the top-bar switcher, which does
            // nothing else (company-picker spec §2.1). Filament's own company
            // menu — with its settings, registration and custom entries — is off.
            ->tenantMenu(false)
            // The trail lives in the top bar, after the company switcher
            // (top-bar trail spec §2.1); the page draws none above its heading.
            ->breadcrumbs(false)
            ->topbarLivewireComponent(Topbar::class)
            // The sidebar exists only inside a company: every entry belongs to
            // one, and /admin has none (spec §2.3).
            ->navigation(fn (): bool => Filament::getTenant() !== null)
            ->navigationItems([
                NavigationItem::make(fn (): string => __('company.settings.title'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => route(CompanySettings::getRouteName(), ['tenant' => Filament::getTenant()]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(CompanySettings::getRouteName()))
                    ->sort(3),
            ])
            // Once after the brand for desktop, once before the user menu for
            // phones, where Filament hides the brand area; the phone copy is
            // hidden from 64rem by topbar-styles — Filament's shipped CSS has no
            // global responsive-hide class (spike findings).
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): string => $this->switcher('desktop'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => $this->switcher('phone'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.topbar-styles')->render())
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.panel-styles')->render())
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

    /**
     * The trail comes from the top bar, which is the component rendering when
     * this hook runs (spike findings). The phone copy draws none.
     */
    private function switcher(string $variant): string
    {
        $topbar = Livewire::current();

        return view('filament.topbar-trail', [
            'companies' => SelectCompany::getCompanies(),
            'current' => Filament::getTenant(),
            'trail' => $variant === 'desktop' && $topbar instanceof Topbar ? $topbar->trail : [],
            'variant' => $variant,
        ])->render();
    }
}
