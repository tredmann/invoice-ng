<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tenant-aware, like every resource in this panel: Filament scopes the list
 * query and the record lookup to the company in the path, owned through
 * `Document::company()`.
 *
 * The model is `Invoice` rather than `Document`, so the resource sees only
 * Rechnungen. When a Storno and a Teilstorno arrive they will need to appear in
 * this list too — that is a query change on a table that already holds them,
 * not a second resource.
 */
class InvoiceResource extends Resource
{
    #[\Override]
    protected static ?string $model = Invoice::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    // Between Kunden (1) and Einstellungen, which moves to 3.
    #[\Override]
    protected static ?int $navigationSort = 2;

    #[\Override]
    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('invoice.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('invoice.plural_label');
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
