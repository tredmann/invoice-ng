<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\LegalForm;
use App\Models\Company;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The only place a company is created.
 *
 * It asks for the two things a company needs in order to exist and be
 * routable, and then lands the user on its settings page for the rest. Being
 * Filament's tenant-registration page, it is also where a user with no
 * companies is sent — which is how the first company comes into being, with no
 * seeder and no signup.
 */
class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('company.register.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('company.fields.name'))
                ->helperText(__('company.register.name_help'))
                ->required()
                ->maxLength(255),
            Select::make('legal_form')
                ->label(__('company.fields.legal_form'))
                ->options(LegalForm::class)
                ->required(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Company
    {
        $company = Company::query()->create($data);

        // Without this the user has created a company that fails
        // canAccessTenant() and is therefore unreachable.
        $company->users()->attach(Auth::id());

        return $company;
    }

    protected function getRedirectUrl(): ?string
    {
        // Filament would otherwise send the user to the company's dashboard.
        // A freshly created company has an incomplete identity block, so the
        // settings page is the only useful next screen.
        return route(CompanySettings::getRouteName(), ['tenant' => $this->tenant]);
    }
}
