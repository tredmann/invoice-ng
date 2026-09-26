<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HandlesLineItems;
use App\Filament\Concerns\SeparatesFormActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateInvoice extends CreateRecord
{
    use HandlesLineItems, SeparatesFormActions;

    #[\Override]
    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('invoice.actions.create');
    }

    #[\Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('invoice.create.subheading');
    }

    #[\Override]
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('invoice.create.save'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->liftLineItems($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $company = Filament::getTenant();

        throw_unless($company instanceof Company, LogicException::class, 'An invoice is created inside a company.');

        // The panel does not use databaseTransactions(), so the document and
        // its Positionen are made atomic here: a Beleg saved with half its
        // Positionen would be worse than one that failed to save at all.
        return DB::transaction(function () use ($data, $company): Invoice {
            $invoice = new Invoice($data);

            // Associated before save, not after: Filament associates the
            // tenant in a creating listener of its own, and nothing here may
            // depend on which listener registered first.
            $invoice->company()->associate($company);
            $invoice->save();

            $this->writeLineItems($invoice);

            return $invoice;
        });
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
