<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Tables\CompaniesTable;
use App\Models\Company;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The companies list.
 *
 * A list page and nothing else. Creating happens on the tenant-registration
 * page and editing on the tenant-profile page, so a create, view or edit page
 * here would be a second surface onto the same record.
 */
class CompanyResource extends Resource
{
    #[\Override]
    protected static ?string $model = Company::class;

    /**
     * Companies are what the tenant *is*, so scoping this resource to the
     * current tenant would reduce the list to the single company the user is
     * already inside — and make switching impossible.
     */
    #[\Override]
    protected static bool $isScopedToTenant = false;

    /**
     * Reached from the "Manage companies" entry in the tenant menu instead.
     * The sidebar is left empty for the company-scoped work of the next wave.
     */
    #[\Override]
    protected static bool $shouldRegisterNavigation = false;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Companies are what the tenant is, so this resource opts out of tenant
     * scoping — otherwise the list would show the single company the user is
     * already inside and switching would be impossible. Opting out of tenant
     * scoping is not opting out of all scoping: without this override the
     * query is `select * from companies`, and because Filament resolves row
     * actions through it, a user could archive a company they have no
     * membership in. The join table is the boundary everywhere else in this
     * wave, and it is the boundary here too.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('users', fn (Builder $query): Builder => $query->whereKey(Auth::id()));
    }

    public static function getModelLabel(): string
    {
        return __('company.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('company.resource.plural_label');
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
        ];
    }
}
