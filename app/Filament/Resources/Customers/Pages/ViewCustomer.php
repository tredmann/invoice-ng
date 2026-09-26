<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\Actions\CustomerActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only today, and largely a repeat of the form. It exists because the
 * invoicing wave adds the customer's documents here, and because the list's
 * Öffnen should lead somewhere that cannot be changed by accident.
 */
class ViewCustomer extends ViewRecord
{
    #[\Override]
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->customer()->name;
    }

    /**
     * The heading carries the Deaktiviert badge; the title stays plain text,
     * because it also ends up in the browser tab.
     */
    public function getHeading(): Htmlable
    {
        return CustomerResource::nameWithStatus($this->customer());
    }

    protected function customer(): Customer
    {
        $record = $this->getRecord();

        assert($record instanceof Customer);

        return $record;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('customer.actions.edit')),
            CustomerActions::archive(),
            CustomerActions::unarchive(),
        ];
    }
}
