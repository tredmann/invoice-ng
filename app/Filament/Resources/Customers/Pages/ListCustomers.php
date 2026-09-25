<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCustomers extends ListRecords
{
    #[\Override]
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('customer.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => CustomerResource::getUrl('create'))
                // With no customers the empty state carries this button;
                // showing it in the header too would put it on the page twice.
                ->visible(fn (): bool => CustomerResource::getEloquentQuery()->exists()),
        ];
    }
}
