<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No header actions: there is no delete (customers are deactivated, never
 * deleted), and deactivation lives on the view page and the list.
 */
class EditCustomer extends EditRecord
{
    #[\Override]
    protected static string $resource = CustomerResource::class;

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
