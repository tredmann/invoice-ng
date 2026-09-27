<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\DocumentStatus;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        // A company with no invoices at all gets no column headers, no search
        // box and no pagination — just the invitation. A fruitless *search*
        // keeps its furniture, because there the table is the thing that came
        // back empty. Same trick as CustomersTable.
        $hasAny = self::hasAnyInvoice();

        return $table
            // The Betrag of every row is computed from its Positionen, so
            // without this the list is one query per invoice. The empty query
            // log in DocumentTest is what makes the eager load worth having.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'lineItems']))
            ->columns($hasAny ? [
                TextColumn::make('number')
                    ->label(__('invoice.columns.number'))
                    ->placeholder(__('invoice.not_yet'))
                    ->weight(FontWeight::Medium),
                TextColumn::make('status')
                    ->label(__('invoice.columns.status'))
                    ->badge(),
                // Überfällig is derived, not a status (§3.5), so it is its own
                // column rather than a sixth case in the enum — and it is blank
                // on every row that is not late, which is most of them.
                TextColumn::make('overdue')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->state(fn (Invoice $record): ?string => $record->isOverdue()
                        ? (string) __('invoice.view.overdue')
                        : null),
                TextColumn::make('customer.name')
                    ->label(__('invoice.columns.customer')),
                TextColumn::make('issued_on')
                    ->label(__('invoice.columns.issued_on'))
                    ->date('d.m.Y')
                    ->sortable(),
                // Blank while a Beleg is a draft: the Fälligkeitsdatum is the
                // Ausstellungsdatum plus the Zahlungsziel, and until the Beleg
                // is issued the first half is only a proposal.
                TextColumn::make('due_on')
                    ->label(__('invoice.columns.due_on'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder(__('invoice.not_yet')),
                TextColumn::make('total')
                    ->label(__('invoice.columns.total'))
                    ->alignEnd()
                    ->weight(FontWeight::Medium)
                    ->state(fn (Invoice $record): string => self::money($record)),
            ] : [])
            ->defaultSort('issued_on', 'desc')
            // Earned now rather than reserved: §9 names finding overdue
            // invoices as a task, and there is finally more than one status to
            // filter by. A filter with a single option would have been
            // furniture, which is why the drafts wave left it out.
            ->filters($hasAny ? [
                SelectFilter::make('status')
                    ->label(__('invoice.columns.status'))
                    ->options(DocumentStatus::class),
            ] : [])
            ->searchable($hasAny)
            ->paginated($hasAny)
            ->searchUsing(fn (Builder $query, string $search) => self::applySearch($query, $search))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('invoice.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
                    EditAction::make()
                        ->label(__('invoice.actions.edit'))
                        ->visible(fn (Invoice $record): bool => $record->status->isDraft()),
                    InvoiceActions::issue(),
                    InvoiceActions::download(),
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

    private static function hasAnyInvoice(): bool
    {
        return InvoiceResource::getEloquentQuery()->exists();
    }

    /**
     * Over the Belegnummer, the Kunde and the Positionen's Bezeichnungen — the
     * three things someone remembers about an invoice they cannot find. The
     * number joined the list when documents started having one.
     *
     * @param  Builder<Invoice>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $pattern = '%'.addcslashes(trim($search), '\\%_').'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('number', 'ilike', $pattern)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'ilike', $pattern))
                ->orWhereHas('lineItems', fn (Builder $line) => $line->where('title', 'ilike', $pattern));
        });
    }

    private static function money(Invoice $invoice): string
    {
        return Euro::format($invoice->grossAmount());
    }

    private static function isSearching(HasTable $livewire): bool
    {
        return $livewire->hasTableSearch();
    }
}
