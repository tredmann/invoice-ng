<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Resources\Pages\ViewRecord;

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

    protected function customer(): Customer
    {
        $record = $this->getRecord();

        assert($record instanceof Customer);

        return $record;
    }
}
