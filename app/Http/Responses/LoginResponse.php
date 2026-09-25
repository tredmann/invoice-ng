<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Lands a fresh login on the company picker at /admin.
 *
 * Filament's own response redirects to Filament::getUrl(), which under tenancy
 * resolves to the user's default company — skipping the picker. A link the
 * user was on the way to still wins, through intended().
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->intended(url(Filament::getDefaultPanel()->getPath()));
    }
}
