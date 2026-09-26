<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Actions;

use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Deactivate and reactivate, shared by the list's row menu and the view
 * page's header so the two cannot drift apart.
 *
 * No confirmation: undoing either is one click.
 */
final class CustomerActions
{
    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('customer.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->hidden(fn (Customer $record): bool => $record->isArchived())
            ->action(fn (Customer $record) => $record->archive());
    }

    public static function unarchive(): Action
    {
        return Action::make('unarchive')
            ->label(__('customer.actions.unarchive'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Customer $record): bool => $record->isArchived())
            ->action(fn (Customer $record) => $record->unarchive());
    }
}
