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

    #[\Override]
    public function getSubheading(): string|Htmlable|null
    {
        $customer = $this->document()->customer;

        return $customer instanceof Customer ? $customer->name : null;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('invoice.actions.edit')),
            InvoiceActions::issue(),
            ActionGroup::make([
                InvoiceActions::delete()
                    ->successRedirectUrl(fn (): string => InvoiceResource::getUrl('index')),
            ]),
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
