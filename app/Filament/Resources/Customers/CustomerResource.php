<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * A company's customers, at /admin/{company}/customers.
 *
 * Tenant-aware, like every resource in this panel: Filament scopes the list
 * query and the URL lookup of a record to the company in the path, and a
 * customer of another company is a 404. Owned through Customer::company(),
 * which is the ownership relationship name Filament derives from Company.
 */
class CustomerResource extends Resource
{
    #[\Override]
    protected static ?string $model = Customer::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    // Above Firmendaten, which the panel provider registers at sort 2.
    #[\Override]
    protected static ?int $navigationSort = 1;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    // A title attribute would otherwise switch on the panel's global search
    // box — an affordance no task here needs.
    #[\Override]
    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('customer.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customer.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
