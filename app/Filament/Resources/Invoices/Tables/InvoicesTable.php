<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Money\Euro;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The Betrag of every row is computed from its Positionen, so
            // without this the list is one query per invoice. The empty query
            // log in DocumentTest is what makes the eager load worth having.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'lineItems']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('invoice.columns.number'))
                    ->placeholder(__('invoice.not_yet'))
                    ->weight(FontWeight::Medium),
                TextColumn::make('status')
                    ->label(__('invoice.columns.status'))
                    ->badge(),
                TextColumn::make('customer.name')
                    ->label(__('invoice.columns.customer')),
                TextColumn::make('issued_on')
                    ->label(__('invoice.columns.issued_on'))
                    ->date('d.m.Y')
                    ->sortable(),
                // No Fälligkeitsdatum until a document is issued: it is the
                // Ausstellungsdatum plus the Zahlungsziel, and the first half
                // does not exist yet.
                TextColumn::make('due_on')
                    ->label(__('invoice.columns.due_on'))
                    ->placeholder(__('invoice.not_yet')),
                TextColumn::make('total')
                    ->label(__('invoice.columns.total'))
                    ->alignEnd()
                    ->weight(FontWeight::Medium)
                    ->state(fn (Invoice $record): string => self::money($record)),
            ])
            ->defaultSort('issued_on', 'desc')
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => self::applySearch($query, $search))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('invoice.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
                    EditAction::make()->label(__('invoice.actions.edit')),
                    InvoiceActions::delete(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(fn (HasTable $livewire): string => self::isSearching($livewire)
                ? __('invoice.list.no_results')
                : __('invoice.list.empty'))
            ->emptyStateDescription(fn (HasTable $livewire): string => self::isSearching($livewire)
                ? __('invoice.list.no_results_help')
                : __('invoice.list.empty_help'))
            ->emptyStateActions([
                Action::make('createFirst')
                    ->label(__('invoice.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => InvoiceResource::getUrl('create'))
                    ->hidden(fn (HasTable $livewire): bool => self::isSearching($livewire)),
            ]);
    }

    /**
     * Over the Kunde and the Positionen's Bezeichnungen — the two things
     * someone remembers about an invoice they cannot find. Not over the
     * Belegnummer yet, because no document has one.
     *
     * @param  Builder<Invoice>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $pattern = '%'.addcslashes(trim($search), '\\%_').'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->whereHas('customer', fn (Builder $customer) => $customer->where('name', 'ilike', $pattern))
                ->orWhereHas('lineItems', fn (Builder $line) => $line->where('title', 'ilike', $pattern));
        });
    }

    private static function money(Invoice $invoice): string
    {
        return Euro::format($invoice->totals()->gross);
    }

    private static function isSearching(HasTable $livewire): bool
    {
        return $livewire->hasTableSearch();
    }
}
