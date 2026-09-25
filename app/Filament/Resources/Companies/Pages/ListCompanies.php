<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    #[\Override]
    protected static string $resource = CompanyResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // A link to the registration page rather than a create form of its
            // own: one creation path means one form to keep correct.
            //
            // RegisterCompany::getUrl() does not exist: tenant-registration
            // pages use Filament\Pages\Concerns\HasRoutes, not
            // Filament\Pages\Page, so they carry no getUrl() of their own.
            // Filament's own tenant menu builds this same link with
            // Filament::getTenantRegistrationUrl().
            Action::make('register')
                ->label(__('company.actions.create'))
                ->url(fn (): ?string => Filament::getTenantRegistrationUrl()),
        ];
    }
}
