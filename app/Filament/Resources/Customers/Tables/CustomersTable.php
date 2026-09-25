<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\Actions\CustomerActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * No filters, no bulk actions and no created_at column
 * (.ai/guidelines/ui/core.blade.php). Deactivated customers stay in the list,
 * greyed and badged, rather than behind a filter.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (int $state): string => Customer::formatNumber($state))
                    ->color('gray'),
                TextColumn::make('name')
                    ->label(__('customer.fields.name'))
                    ->formatStateUsing(fn (Customer $record): Htmlable => CustomerResource::nameWithStatus($record))
                    ->weight(FontWeight::Medium)
                    ->color(fn (Customer $record): ?string => $record->isArchived() ? 'gray' : null)
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('customer.fields.type'))
                    ->badge(),
                TextColumn::make('email')
                    ->label(__('customer.fields.email'))
                    ->color('gray'),
                TextColumn::make('city')
                    ->label(__('customer.fields.city')),
            ])
            ->defaultSort('name')
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => self::applySearch($query, $search))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('customer.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
                    EditAction::make()
                        ->label(__('customer.actions.edit')),
                    CustomerActions::archive(),
                    CustomerActions::unarchive(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(fn (HasTable $livewire): string => self::isSearching($livewire)
                ? __('customer.list.no_results')
                : __('customer.list.empty'))
            ->emptyStateDescription(function (HasTable $livewire): ?string {
                if (self::isSearching($livewire)) {
                    return null;
                }

                return __('customer.list.empty_description');
            })
            ->emptyStateActions([
                Action::make('createFirst')
                    ->label(__('customer.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => CustomerResource::getUrl('create'))
                    ->hidden(fn (HasTable $livewire): bool => self::isSearching($livewire)),
            ]);
    }

    /**
     * One search box over number, name, email and city.
     *
     * The number matches exactly on the parsed integer — "4" finds customer 4
     * and not 14 — and the text columns as case-insensitive substrings with
     * LIKE wildcards escaped. Every condition sits inside one group so the
     * search stays self-contained — defence in depth for any query where
     * tenancy is a plain `where` rather than a global scope; Laravel already
     * isolates ORs added here from a tenant condition added later by a global
     * scope, which is how Filament scopes this query.
     *
     * @param  Builder<Customer>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);
        $number = Customer::numberFromSearch($search);
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        $query->where(function (Builder $query) use ($number, $pattern): void {
            $query->where('name', 'ilike', $pattern)
                ->orWhere('email', 'ilike', $pattern)
                ->orWhere('city', 'ilike', $pattern);

            if ($number !== null) {
                $query->orWhere('number', $number);
            }
        });
    }

    /**
     * Filament shows one empty state for "no customers" and "no customers
     * matching"; this tells them apart.
     */
    private static function isSearching(HasTable $livewire): bool
    {
        return $livewire->hasTableSearch();
    }
}
