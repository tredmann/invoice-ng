<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Actions;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;

/**
 * Shared by the table's row menu and the detail page's header, so the two
 * cannot offer different things.
 */
final class InvoiceActions
{
    /**
     * Only an Entwurf. It never received a Belegnummer, so nothing is left
     * behind (§3.4) — and it is the only deletion this system performs, which
     * is why it asks first. Deactivating a customer does not, because that is
     * one click to undo and this is not.
     */
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('invoice.actions.delete'))
            ->modalHeading(__('invoice.delete.heading'))
            ->modalDescription(__('invoice.delete.body'))
            ->modalSubmitActionLabel(__('invoice.delete.confirm'))
            ->successNotificationTitle(__('invoice.delete.done'))
            ->visible(fn (Document $record): bool => $record->status->isDraft());
    }

    /**
     * Disabled rather than absent: the board makes it the primary action, and
     * a page that simply lacked it would read as a different design rather
     * than as an unfinished one.
     */
    public static function issue(): Action
    {
        return Action::make('issue')
            ->label(__('invoice.view.issue'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->disabled()
            ->tooltip(__('invoice.view.issue_disabled'));
    }
}
