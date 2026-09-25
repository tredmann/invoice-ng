<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

/**
 * Filament's login page, landing an already signed-in visitor on the company
 * picker at /admin.
 *
 * The parent's mount() redirects such a visitor to Filament::getUrl(), which
 * under tenancy is the user's default company. That path is separate from the
 * login response, which covers only a submitted login form.
 */
class Login extends BaseLogin
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(url(Filament::getDefaultPanel()->getPath()));

            return;
        }

        parent::mount();
    }
}
