<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Rules\Iban;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The only surface that edits a company.
 *
 * There is deliberately no edit page on the companies resource: two surfaces
 * onto one record drift apart, and the second exists only to duplicate the
 * first.
 *
 * This is also where the identity block is required. A company created through
 * registration has a name and a legal form and nothing else, so it is
 * incomplete until this page has been saved once. Issuing a document from an
 * incomplete company is refused in a later wave, not here.
 */
class CompanySettings extends EditTenantProfile
{
    /**
     * Gives the page the URL the design calls for: /admin/{company}/settings
     * rather than Filament's default /profile.
     */
    #[\Override]
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return __('company.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('company.sections.identity'))->schema([
                TextInput::make('name')
                    ->label(__('company.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('company.fields.slug'))
                    ->helperText(__('company.fields.slug_help'))
                    // Shown because it is the company's address on the web, and
                    // never written: dehydrated(false) keeps it out of the
                    // saved data even if the input is tampered with.
                    ->disabled()
                    ->dehydrated(false),
                Select::make('legal_form')
                    ->label(__('company.fields.legal_form'))
                    ->options(LegalForm::class)
                    ->required()
                    // The register and management sections appear and
                    // disappear with this, so the form has to re-render on
                    // change.
                    ->live(),
            ]),

            Section::make(__('company.sections.address'))->schema([
                TextInput::make('street')
                    ->label(__('company.fields.street'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('company.fields.postal_code'))
                    ->required()
                    ->maxLength(10),
                TextInput::make('city')
                    ->label(__('company.fields.city'))
                    ->required()
                    ->maxLength(255),
            ]),

            Section::make(__('company.sections.tax'))->schema([
                Select::make('vat_scheme')
                    ->label(__('company.fields.vat_scheme'))
                    ->options(VatScheme::class)
                    ->required(),
                TextInput::make('tax_number')
                    ->label(__('company.fields.tax_number'))
                    // Which identifier a company has depends on its scheme, so
                    // each is required only while the other is missing.
                    ->requiredWithout('vat_id')
                    ->maxLength(50),
                TextInput::make('vat_id')
                    ->label(__('company.fields.vat_id'))
                    ->requiredWithout('tax_number')
                    ->maxLength(50),
            ]),

            Section::make(__('company.sections.register'))
                ->visible(fn (Get $get): bool => $this->isRegisteredForm($get))
                ->schema([
                    TextInput::make('register_court')
                        ->label(__('company.fields.register_court'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('register_number')
                        ->label(__('company.fields.register_number'))
                        ->required()
                        ->maxLength(50),
                ]),

            Section::make(__('company.sections.management'))
                ->visible(fn (Get $get): bool => $this->isRegisteredForm($get))
                ->schema([
                    TextInput::make('managing_directors')
                        ->label(__('company.fields.managing_directors'))
                        ->helperText(__('company.fields.managing_directors_help'))
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make(__('company.sections.bank'))->schema([
                TextInput::make('bank_name')
                    ->label(__('company.fields.bank_name'))
                    ->maxLength(255),
                TextInput::make('iban')
                    ->label(__('company.fields.iban'))
                    ->rule(new Iban)
                    ->maxLength(42),
                TextInput::make('bic')
                    ->label(__('company.fields.bic'))
                    ->maxLength(11),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->legalFormIsRegistered($data['legal_form'] ?? null)) {
            return $data;
        }

        // A hidden Filament field is not dehydrated, so switching a GmbH to a
        // sole proprietorship would otherwise leave its HRB number in the
        // database — where the next document would print it.
        return [
            ...$data,
            'register_court' => null,
            'register_number' => null,
            'managing_directors' => null,
        ];
    }

    private function isRegisteredForm(Get $get): bool
    {
        return $this->legalFormIsRegistered($get('legal_form'));
    }

    /**
     * Coerces a loose value — the enum, its backing string, or null — to
     * whether it names a legal form entered in the commercial register.
     *
     * Used by both `isRegisteredForm()`, which decides whether the register
     * fields are rendered and validated, and `mutateFormDataBeforeSave()`,
     * which decides whether they are nulled. The two must agree: if they
     * diverge, a legal form can render as unregistered while saving as
     * registered (or the reverse), which either strands a stale HRB number on
     * a sole proprietorship or silently wipes a valid one from a GmbH — data
     * that prints on a statutory document.
     */
    private function legalFormIsRegistered(mixed $value): bool
    {
        if (! $value instanceof LegalForm) {
            $value = LegalForm::tryFrom((string) $value);
        }

        return $value?->isRegistered() ?? false;
    }

    /**
     * The switcher in the top bar names the company, and the top bar does not
     * re-render with the page. Without this a rename shows in the form and
     * nowhere else until the next navigation.
     */
    protected function afterSave(): void
    {
        $this->dispatch('refresh-topbar');
    }
}
