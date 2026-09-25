<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;

/**
 * The page at /admin: one tile per company the user can switch to.
 *
 * Filament's tenancy would otherwise redirect /admin straight into the user's
 * default company, which leaves nowhere to see every company side by side.
 * This page takes over that route — AppServiceProvider binds Filament's
 * RedirectToTenantController to it — so it sits outside any company, which is
 * why it is a simple page with no company navigation.
 *
 * It is not discovered as a panel page: discovery only picks up tenant-scoped
 * `Page` subclasses, and a `SimplePage` is not one.
 */
class SelectCompany extends SimplePage
{
    #[\Override]
    protected string $view = 'filament.pages.select-company';

    public function mount(): void
    {
        // Nothing to pick from: the first company still comes into being
        // through registration, as it did before this page existed.
        if ($this->getCompanies()->isEmpty()) {
            $this->redirect(Filament::getTenantRegistrationUrl());
        }
    }

    public function getTitle(): string
    {
        return __('company.picker.title');
    }

    /**
     * The same list as the company menu, so the two cannot disagree about
     * which companies exist — archived ones stay out of both.
     *
     * @return Collection<int, Company>
     */
    public function getCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->getTenants(Filament::getDefaultPanel());
    }

    public function getCompanyUrl(Company $company): string
    {
        return Filament::getDefaultPanel()->getUrl($company) ?? '';
    }

    public function getRegistrationUrl(): string
    {
        return Filament::getTenantRegistrationUrl() ?? '';
    }

    public function getMaxWidth(): Width
    {
        return Width::ThreeExtraLarge;
    }
}
