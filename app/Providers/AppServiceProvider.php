<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Pages\Tenancy\SelectCompany;
use App\Http\Responses\LoginResponse;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Http\Controllers\RedirectToTenantController;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
