<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HandlesLineItems;
use App\Filament\Concerns\SeparatesFormActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Document;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditInvoice extends EditRecord
{
    use HandlesLineItems, SeparatesFormActions;

    #[\Override]
    protected static string $resource = InvoiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // A stored Leistungsdatum comes back into `performed_from`, which is
        // the field the form sends and the one the model reads as
        // authoritative.
        $data['performed_from'] ??= $data['performed_on'] ?? null;
        $data['line_items'] = $this->lineItemsFor($this->document());

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->liftLineItems($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->fill($data)->save();

            if ($record instanceof Document) {
                $this->writeLineItems($record);
            }

            return $record;
        });
    }

    private function document(): Document
    {
        $record = $this->getRecord();
        assert($record instanceof Document);

        return $record;
    }
}
