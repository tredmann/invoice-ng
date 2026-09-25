<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The page at /admin: one tile per company the user can switch to.
 *
 * It sits outside any company but in the full panel layout, so it looks like
 * every other screen (company-picker spec §1). Filament would otherwise
 * redirect /admin into the default company, or into registration when there is
 * none; AppServiceProvider binds Filament's RedirectToTenantController to this
 * page instead, so it is served on Filament's own route. It never redirects.
 *
 * Not discovered: discovery would also register it under every company.
 */
class SelectCompany extends Page
{
    #[\Override]
    protected static bool $isDiscovered = false;

    public function getTitle(): string
    {
        return __('company.picker.title');
    }

    /**
     * The same list as the company menu, so the two cannot disagree — archived
     * companies stay out of both.
     *
     * @return Collection<int, Company>
     */
    public static function getCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->getTenants(Filament::getDefaultPanel());
    }

    public function content(Schema $schema): Schema
    {
        $companies = static::getCompanies();

        if ($companies->isEmpty()) {
            return $schema->components([
                EmptyState::make(__('company.picker.empty'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->footer([$this->createCompanyAction()]),
            ]);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema($companies
                    ->map(fn (Company $company): View => View::make('filament.pages.company-tile')
                        ->key("company-{$company->getKey()}")
                        ->viewData([
                            'name' => $company->name,
                            'legalForm' => $company->legal_form->getLabel(),
                            'url' => Filament::getDefaultPanel()->getUrl($company),
                        ]))
                    ->all()),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // With no company the empty state carries this action; showing it in
        // the header as well would put the same button on the page twice.
        return static::getCompanies()->isEmpty() ? [] : [$this->createCompanyAction()];
    }

    private function createCompanyAction(): Action
    {
        return Action::make('createCompany')
            ->label(__('company.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(Filament::getTenantRegistrationUrl());
    }
}
