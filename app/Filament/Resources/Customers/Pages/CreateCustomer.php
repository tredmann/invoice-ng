<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    #[\Override]
    protected static string $resource = CustomerResource::class;

    // One obvious primary action; "create and create another" is a second.
    #[\Override]
    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('customer.actions.create');
    }

    /**
     * The create form has no number field — it is assigned on save. Saying so
     * here is cheaper than an owner hunting for where to type one.
     */
    public function getSubheading(): string|Htmlable|null
    {
        return __('customer.create.subheading');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('customer.actions.save'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $customer = new Customer($data);

        // Filament associates the tenant in a `creating` listener of its own.
        // Whether that runs before or after Customer's — which numbers the
        // customer within its company, and so needs the company — depends on
        // which listener was registered first. Associating here, before
        // saving, makes the order irrelevant.
        /** @var Company $company */
        $company = Filament::getTenant();

        $customer->company()->associate($company);
        $customer->save();

        return $customer;
    }
}
