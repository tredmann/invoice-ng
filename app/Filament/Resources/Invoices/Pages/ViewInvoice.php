<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Customer;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class ViewInvoice extends ViewRecord
{
    #[\Override]
    protected static string $resource = InvoiceResource::class;

    /**
     * A draft has no Belegnummer to be called by, so it is called what it is.
     */
    public function getTitle(): string|Htmlable
    {
        return $this->heading();
    }

    /**
     * The heading carries the status as a badge, as the board draws it. It is
     * repeated in the Status card on purpose: the header answers „what am I
     * looking at" at a glance, the card answers „what is true of it".
     */
    public function getHeading(): string|Htmlable
    {
        $status = $this->document()->status;

        return new HtmlString(
            '<span style="display: inline-flex; align-items: center; gap: 0.75rem">'
            .e($this->heading())
            .Blade::render(
                '<x-filament::badge color="{{ $color }}" size="sm">{{ $label }}</x-filament::badge>',
                ['color' => $status->getColor(), 'label' => $status->getLabel()],
            )
            .'</span>'
        );
    }

    /**
     * The Rechnungsempfänger, from the Festschreibung once there is one.
     *
     * Reading the live customer would put a name on this page that the PDF in
     * the customer's hands does not carry, the day after they rename themselves.
     * A draft has no frozen block and follows its Stammdaten, which is right:
     * a draft is a proposal.
     */
    #[\Override]
    public function getSubheading(): string|Htmlable|null
    {
        $frozen = $this->document()->frozen_block?->buyer->name;

        if ($frozen !== null) {
            return $frozen;
        }

        $customer = $this->document()->customer;

        return $customer instanceof Customer ? $customer->name : null;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        // An issued Beleg offers neither Bearbeiten nor Löschen — both are
        // refused by the model, and an action that always fails is worse than
        // none. What it offers instead is the frozen file.
        return [
            EditAction::make()
                ->label(__('invoice.actions.edit'))
                ->visible(fn (): bool => $this->document()->status->isDraft()),
            InvoiceActions::issue(),
            InvoiceActions::download(),
            ActionGroup::make([
                InvoiceActions::delete()
                    ->successRedirectUrl(fn (): string => InvoiceResource::getUrl('index')),
            ])->visible(fn (): bool => $this->document()->status->isDraft()),
        ];
    }

    private function heading(): string
    {
        $document = $this->document();

        return $document->number === null
            ? __('invoice.view.draft_heading')
            : (string) $document->number;
    }

    private function document(): Document
    {
        $record = $this->getRecord();
        assert($record instanceof Document);

        return $record;
    }
}
