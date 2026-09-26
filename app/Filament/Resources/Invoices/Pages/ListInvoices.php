<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvoices extends ListRecords
{
    #[\Override]
    protected static string $resource = InvoiceResource::class;

    /**
     * Hidden while the list is empty, because the empty state carries the same
     * button and two of them read as two different things.
     *
     * @return array<Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('invoice.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => InvoiceResource::getUrl('create'))
                ->visible(fn (): bool => InvoiceResource::getEloquentQuery()->exists()),
        ];
    }
}
