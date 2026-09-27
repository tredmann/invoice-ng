<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Actions;

use App\Actions\CheckReadiness;
use App\Actions\IssueDocument;
use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Exceptions\CompanyNotReady;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
     * **Rechnung ausstellen.** The modal is the Bereitschaftsprüfung the mockup
     * draws, not a generic „are you sure".
     *
     * A company that cannot issue is told *what* is missing and offered the way
     * to fix it, with no submit button — because a confirm button that leads to
     * an error is worse than no button. A company that can issue is told what
     * the click makes irreversible, since nothing after this can be changed or
     * deleted and the only way back is a Storno.
     */
    public static function issue(): Action
    {
        return Action::make('issue')
            ->label(__('invoice.view.issue'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (Document $record): bool => $record->status->isDraft())
            ->modalHeading(fn (Document $record): string => self::readinessFor($record)->canIssue()
                ? __('invoice.issue.heading')
                : __('invoice.issue.blocked_heading'))
            ->modalDescription(fn (Document $record): HtmlString => self::modalBody($record))
            ->modalSubmitAction(fn (Action $action, Document $record): Action|false => self::readinessFor($record)->canIssue()
                ? $action->label(__('invoice.issue.confirm'))
                : false)
            // Cancel far left, the saving action far right — the rule applies
            // to dialogs too (.ai/guidelines/ui/core.blade.php), and a narrow
            // container is where the distance matters most.
            ->modalFooterActionsAlignment(Alignment::Between)
            ->action(function (Document $record): void {
                try {
                    $issued = (new IssueDocument)($record, auth()->user());
                } catch (CompanyNotReady $exception) {
                    // Reachable when the settings changed between opening the
                    // dialog and confirming it. Rare, and the alternative is a
                    // stack trace.
                    self::failure(self::names($exception->readiness->blockers()));

                    return;
                } catch (Throwable $exception) {
                    report($exception);
                    self::failure(__('invoice.issue.failed_body'));

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('invoice.issue.done', ['number' => $issued->number]))
                    ->send();
            });
    }

    /**
     * The frozen file, served from storage and never re-rendered (§4).
     */
    public static function download(): Action
    {
        return Action::make('download')
            ->label(__('invoice.view.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Document $record): bool => $record->pdf_path !== null)
            ->action(function (Document $record): StreamedResponse {
                $path = $record->pdf_path;

                // Unreachable through the visible() above, and asserted rather
                // than assumed because the alternative is serving a download
                // from a null path.
                assert(is_string($path));

                return Storage::disk(config()->string('invoice.documents_disk'))
                    ->download($path, $record->number.'.pdf');
            });
    }

    private static function failure(string $body): void
    {
        Notification::make()
            ->danger()
            ->title(__('invoice.issue.failed'))
            ->body($body)
            ->send();
    }

    /**
     * Blockers first, then the quiet list of what is merely recommended. The
     * two are not equal items in one list: only what §14 UStG and §35a GmbHG
     * require can refuse, and a missing logo never stops an invoice.
     */
    private static function modalBody(Document $record): HtmlString
    {
        $readiness = self::readinessFor($record);

        if (! $readiness->canIssue()) {
            return new HtmlString(
                '<p>'.e(__('invoice.issue.blocked_body')).'</p>'
                .self::list($readiness->blockers())
                .'<p><a href="'.e(self::settingsUrl($record)).'" class="fi-link">'
                .e(__('invoice.issue.blocked_action')).'</a></p>'
            );
        }

        $body = '<p>'.e(__('invoice.issue.body')).'</p>';

        if ($readiness->warnings() !== []) {
            $body .= '<p class="fi-text-sm">'.e(__('invoice.issue.warnings_heading')).'</p>'
                .self::list($readiness->warnings());
        }

        return new HtmlString($body);
    }

    /**
     * @param  list<ReadinessItem>  $items
     */
    private static function list(array $items): string
    {
        return '<ul>'.implode('', array_map(
            fn (ReadinessItem $item): string => '<li>'.e($item->label()).' — '.e($item->hint()).'</li>',
            $items,
        )).'</ul>';
    }

    /**
     * @param  list<ReadinessItem>  $items
     */
    private static function names(array $items): string
    {
        return implode(', ', array_map(fn (ReadinessItem $item): string => $item->label(), $items));
    }

    private static function readinessFor(Document $record): Readiness
    {
        return (new CheckReadiness)(self::company($record));
    }

    private static function settingsUrl(Document $record): string
    {
        return route(CompanySettings::getRouteName(), ['tenant' => self::company($record)]);
    }

    private static function company(Document $record): Company
    {
        $company = $record->company;
        assert($company instanceof Company);

        return $company;
    }
}
