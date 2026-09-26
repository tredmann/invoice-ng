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

    public function getHeading(): string|Htmlable
    {
        return $this->heading();
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
