<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('company.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('legal_form')
                    ->label(__('company.fields.legal_form'))
                    ->badge(),
                TextColumn::make('archived_at')
                    ->label(__('company.fields.archived_at'))
                    // No ->placeholder(): TextColumn has no such method in
                    // Filament 5. An active company simply shows an empty cell.
                    ->since(),
            ])
            ->defaultSort('name')
            // No filters and no created_at column. With a handful of rows they
            // would be speculative, and a filter nobody asked for is the sort
            // of affordance that is much harder to remove than to add.
            ->recordActions([
                // One vertical-ellipsis dropdown, never a row of buttons. Loose
                // row actions are a ratchet: every feature adds one, none ever
                // removes one, and the destructive action ends up a few pixels
                // from the common one.
                ActionGroup::make([
                    Action::make('open')
                        ->label(__('company.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Company $record): string => route(
                            CompanySettings::getRouteName(),
                            ['tenant' => $record],
                        )),
                    Action::make('archive')
                        ->label(__('company.actions.archive'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        // Hidden rather than disabled: the model throws on the
                        // last active company, and an action the user cannot
                        // take is not worth showing.
                        ->visible(fn (Company $record): bool => $record->canBeArchived())
                        ->action(fn (Company $record) => $record->archive()),
                    Action::make('unarchive')
                        ->label(__('company.actions.unarchive'))
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Company $record): bool => $record->isArchived())
                        ->action(fn (Company $record) => $record->unarchive()),
                ]),
            ])
            ->emptyStateHeading(__('company.list.empty'));
    }
}
