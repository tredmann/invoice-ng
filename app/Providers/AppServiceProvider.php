<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Pages\Tenancy\SelectCompany;
use App\Http\Responses\LoginResponse;
use App\Livewire\RenderedPage;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Http\Controllers\RedirectToTenantController;
use Filament\Pages\Page;
use Illuminate\Support\ServiceProvider;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Filament registers GET /admin itself, after any route of ours, so a
        // route cannot take it over — the later registration wins. Its action
        // is resolved from the container, though, and a Livewire page is
        // invokable, so binding the controller class to the picker serves the
        // picker on Filament's own route, name and middleware unchanged.
        $this->app->bind(RedirectToTenantController::class, SelectCompany::class);

        $this->app->bind(LoginResponseContract::class, LoginResponse::class);

        $this->app->scoped(RenderedPage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The top bar reads the page's trail from here at mount; see
        // RenderedPage for why it cannot ask Livewire for the page itself.
        on('render', function (object $component): void {
            if ($component instanceof Page) {
                resolve(RenderedPage::class)->page = $component;
            }
        });
    }
}
