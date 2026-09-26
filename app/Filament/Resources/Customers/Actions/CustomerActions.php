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
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('customer.actions.deactivate'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->hidden(fn (Customer $record): bool => $record->isDeactivated())
            ->action(fn (Customer $record) => $record->deactivate());
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('customer.actions.reactivate'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Customer $record): bool => $record->isDeactivated())
            ->action(fn (Customer $record) => $record->reactivate());
    }
}
