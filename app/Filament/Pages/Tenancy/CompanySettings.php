<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\LegalForm;
use App\Enums\PaymentTerm;
use App\Enums\VatScheme;
use App\Models\Company;
use App\Models\NumberRange;
use App\Models\TaxRate;
use App\Rules\Iban;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * The only surface that edits a company.
 *
 * There is deliberately no edit page on the companies resource: two surfaces
 * onto one record drift apart, and the second exists only to duplicate the
 * first.
 *
 * Four tabs rather than four pages, because the mockups show one heading, one
 * tab bar and one „Speichern" — so it is one form. A page that saves in place
 * has nothing to separate, so it keeps its single action at the far right and
 * takes no `SeparatesFormActions` (`.ai/guidelines/ui/core.blade.php`).
 *
 * This is also where the identity block is required. A company created through
 * registration has a name and a legal form and nothing else, so it is
 * incomplete until this page has been saved once. Whether it is complete enough
 * to issue from is `CheckReadiness`, and refusing to issue is a later wave.
 *
 * The Steuersätze and the Nummernkreis live in other tables, so they are not
 * part of the tenant's own attributes. They are lifted out of the form data
 * before the company is saved and written in `afterSave()`, which Filament runs
 * inside the same transaction — so a failure there takes the whole save with it
 * rather than leaving the company updated and its rates half-written.
 */
class CompanySettings extends EditTenantProfile
{
    /**
     * Gives the page the URL the design calls for: /admin/{company}/settings
     * rather than Filament's default /profile.
     */
    #[\Override]
    protected static ?string $slug = 'settings';

    /** @var list<array<string, mixed>> */
    protected array $taxRateState = [];

    /** @var array<string, mixed> */
    protected array $numberRangeState = [];

    public static function getLabel(): string
    {
        return __('company.settings.title');
    }

    /**
     * One saving action, at the far right.
     *
     * Filament's default is `Alignment::Start`, so a page that saves in place
     * puts its Speichern at the left unless it says otherwise — and
     * `.ai/guidelines/ui/core.blade.php` says otherwise. `SeparatesFormActions`
     * is the wrong tool here: it exists to put distance between cancel and
     * save, and this page has no cancel to separate.
     */
    #[\Override]
    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('settings')
                ->columnSpanFull()
                ->tabs([
                    Tabs\Tab::make(__('company.tabs.company'))->schema($this->companyTab()),
                    Tabs\Tab::make(__('company.tabs.tax'))->schema($this->taxTab()),
                    Tabs\Tab::make(__('company.tabs.bank'))->schema($this->bankTab()),
                    Tabs\Tab::make(__('company.tabs.number_range'))->schema($this->numberRangeTab()),
                ]),
        ]);
    }

    /**
     * @return list<Component>
     */
    private function companyTab(): array
    {
        return [
            Section::make(__('company.sections.identity'))
                ->description(__('company.sections.identity_help'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('company.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('legal_form')
                        ->label(__('company.fields.legal_form'))
                        ->options(LegalForm::class)
                        ->required()
                        // The register fields appear and disappear with this,
                        // so the form has to re-render on change.
                        ->live(),
                    TextInput::make('slug')
                        ->label(__('company.fields.slug'))
                        ->helperText(__('company.fields.slug_help'))
                        // Shown because it is the company's address on the web,
                        // and never written: dehydrated(false) keeps it out of
                        // the saved data even if the input is tampered with.
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('street')
                        ->label(__('company.fields.street'))
                        ->required()
                        ->maxLength(255),
                    Grid::make(4)->schema([
                        TextInput::make('postal_code')
                            ->label(__('company.fields.postal_code'))
                            ->required()
                            ->maxLength(10),
                        TextInput::make('city')
                            ->label(__('company.fields.city'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(3),
                    ]),
                    TextInput::make('managing_directors')
                        ->label(__('company.fields.managing_directors'))
                        ->helperText(__('company.fields.managing_directors_help'))
                        ->required()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $this->isRegisteredForm($get)),
                ]),

            Section::make(__('company.sections.register'))
                ->description(__('company.sections.register_help'))
                ->visible(fn (Get $get): bool => $this->isRegisteredForm($get))
                ->schema([
                    // Two columns, not two rows: the mockup puts Registergericht
                    // and Registernummer side by side.
                    Grid::make(2)->schema([
                        TextInput::make('register_court')
                            ->label(__('company.fields.register_court'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('register_number')
                            ->label(__('company.fields.register_number'))
                            ->required()
                            ->maxLength(50),
                    ]),
                ]),

            Section::make(__('company.sections.logo'))
                ->description(__('company.sections.logo_help'))
                ->schema([
                    FileUpload::make('logo_path')
                        ->hiddenLabel()
                        ->image()
                        ->disk((string) config('invoice.logos_disk'))
                        ->directory('companies/'.$this->company()->getKey())
                        ->maxSize(2048),
                ]),
        ];
    }

    /**
     * @return list<Component>
     */
    private function taxTab(): array
    {
        return [
            Section::make(__('company.sections.taxation'))
                ->description(__('company.sections.taxation_help'))
                ->schema([
                    ToggleButtons::make('vat_scheme')
                        ->hiddenLabel()
                        ->options(VatScheme::class)
                        ->inline()
                        ->required()
                        // The Steuersätze card disappears for a
                        // Kleinunternehmer, so the form re-renders on change.
                        ->live(),
                ]),

            Section::make(__('company.sections.tax_numbers'))
                ->description(__('company.sections.tax_numbers_help'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('tax_number')
                            ->label(__('company.fields.tax_number'))
                            // Which identifier a company has depends on its
                            // scheme, so each is required only while the other
                            // is missing.
                            ->requiredWithout('vat_id')
                            ->maxLength(50),
                        TextInput::make('vat_id')
                            ->label(__('company.fields.vat_id'))
                            ->requiredWithout('tax_number')
                            ->maxLength(50),
                    ]),
                ]),

            Section::make(__('company.sections.tax_rates'))
                ->description(__('company.sections.tax_rates_help'))
                // A §19 company issues at 0 % and has no rate to choose, so the
                // card is not shown rather than shown and ignored.
                ->visible(fn (Get $get): bool => ! $this->isSmallBusiness($get))
                ->schema([
                    Repeater::make('tax_rates')
                        ->hiddenLabel()
                        ->addActionLabel(__('company.tax_rate.add'))
                        ->minItems(1)
                        ->reorderable(false)
                        ->table([
                            TableColumn::make(__('company.tax_rate.columns.rate'))->width('9rem'),
                            TableColumn::make(__('company.tax_rate.columns.name')),
                            TableColumn::make(__('company.tax_rate.columns.default'))->width('7rem'),
                        ])
                        ->schema([
                            TextInput::make('rate')
                                ->hiddenLabel()
                                ->required()
                                ->suffix('%')
                                // Two rows reading „19 %" would give the
                                // Positions picker two entries a user cannot
                                // tell apart; the unique index refuses them
                                // anyway, and this says so before the save.
                                ->distinct()
                                ->validationMessages(['distinct' => __('company.tax_rate.duplicate')])
                                ->rule('regex:/^\d{1,2}([.,]\d{1,2})?$/'),
                            TextInput::make('name')
                                ->hiddenLabel()
                                ->required()
                                ->maxLength(255),
                            Toggle::make('is_default')
                                ->hiddenLabel()
                                ->inline(false),
                            Hidden::make('id'),
                        ]),
                ]),

            Section::make(__('company.sections.tax_rates'))
                ->visible(fn (Get $get): bool => $this->isSmallBusiness($get))
                ->schema([
                    Callout::make(__('company.tax_rate.small_business'))->color('gray'),
                ]),
        ];
    }

    /**
     * @return list<Component>
     */
    private function bankTab(): array
    {
        return [
            Section::make(__('company.sections.bank'))
                ->description(__('company.sections.bank_help'))
                ->schema([
                    TextInput::make('bank_name')
                        ->label(__('company.fields.bank_name'))
                        ->maxLength(255),
                    Grid::make(3)->schema([
                        TextInput::make('iban')
                            ->label(__('company.fields.iban'))
                            ->rule(new Iban)
                            ->maxLength(42)
                            ->columnSpan(2),
                        TextInput::make('bic')
                            ->label(__('company.fields.bic'))
                            ->maxLength(11),
                    ]),
                ]),

            // „Zahlungsziel", not „Zahlungsbedingungen": CONTEXT.md puts the
            // latter on the Vermeiden list. The mockup says otherwise and is
            // being corrected, not followed.
            Section::make(__('company.sections.payment_term'))
                ->description(__('company.sections.payment_term_help'))
                ->schema([
                    Select::make('payment_term')
                        ->hiddenLabel()
                        ->options(PaymentTerm::class)
                        ->required(),
                ]),
        ];
    }

    /**
     * @return list<Component>
     */
    private function numberRangeTab(): array
    {
        return [
            Section::make(__('company.sections.number_range'))
                ->description(__('company.sections.number_range_help'))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('number_range.prefix')
                            ->label(__('company.fields.prefix'))
                            ->maxLength(20)
                            ->live(onBlur: true),
                        TextInput::make('number_range.padding')
                            ->label(__('company.fields.padding'))
                            ->helperText(__('company.fields.padding_help'))
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(12)
                            ->live(onBlur: true),
                        TextInput::make('number_range.next_value')
                            ->label(__('company.fields.next_value'))
                            ->helperText(__('company.fields.next_value_help'))
                            ->numeric()
                            ->required()
                            ->minValue(fn (): int => $this->lowestAllowedStart())
                            ->validationMessages(['min' => __('company.number_range.start_locked')])
                            ->live(onBlur: true),
                    ]),
                    Toggle::make('number_range.include_year')
                        ->label(__('company.fields.include_year'))
                        ->helperText(__('company.fields.include_year_help'))
                        ->live(),
                    Toggle::make('number_range.reset_yearly')
                        ->label(__('company.fields.reset_yearly'))
                        ->helperText(__('company.fields.reset_yearly_help'))
                        ->live(),
                    // Reads, never draws. A preview built by calling
                    // DrawNextNumber would look identical on screen and burn a
                    // Belegnummer on every page load.
                    //
                    // Boxed rather than a bare field: it is the one thing on
                    // this tab that is an answer rather than a setting.
                    //
                    // Not a Section: a section heading is prominent and its
                    // body plain, and this needs the opposite — a quiet 12px
                    // label over a 22px value, as the mockup draws it. The
                    // markup carries classes from panel-styles.blade.php
                    // instead, because there is no CSS build.
                    Text::make(fn (Get $get): Htmlable => new HtmlString(
                        '<div class="app-number-preview">'
                        .'<span class="app-number-preview-label">'
                        .e(__('company.fields.next_number'))
                        .'</span><span class="app-number-preview-value">'
                        .e($this->previewNumber($get))
                        .'</span></div>'
                    ))->columnSpanFull(),
                    Callout::make(__('company.number_range.warning'))->color('warning'),
                ]),
        ];
    }

    /**
     * The Startwert may be set freely until a Belegnummer has been drawn, and
     * only raised afterwards. The model refuses a lowering outright; this is
     * what turns that into a message on the field instead of an exception.
     */
    private function lowestAllowedStart(): int
    {
        $range = $this->company()->numberRange()->first();

        if (! $range instanceof NumberRange || $range->drawn_count === 0) {
            return 1;
        }

        return (int) $range->next_value;
    }

    private function previewNumber(Get $get): string
    {
        $range = new NumberRange([
            'prefix' => $get('number_range.prefix'),
            'padding' => max(1, (int) $get('number_range.padding')),
            'next_value' => max(1, (int) $get('number_range.next_value')),
            'include_year' => (bool) $get('number_range.include_year'),
            'reset_yearly' => (bool) $get('number_range.reset_yearly'),
        ]);

        return $range->nextNumber();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $company = $this->company();

        $data['tax_rates'] = $company->taxRates()
            ->whereNull('deactivated_at')
            ->orderByDesc('rate')
            ->get()
            ->map(fn (TaxRate $rate): array => [
                'id' => $rate->getKey(),
                'rate' => TaxRate::formatPercent((int) $rate->rate),
                'name' => (string) $rate->name,
                'is_default' => (bool) $rate->is_default,
            ])
            ->all();

        $range = $company->numberRange()->first();

        // Defaults rather than an empty tab when no range exists yet: the row
        // is created by the first save, and showing what saving would produce
        // is more use than showing nothing.
        $data['number_range'] = $range instanceof NumberRange
            ? [
                'prefix' => $range->prefix,
                'padding' => $range->padding,
                'next_value' => $range->next_value,
                'include_year' => $range->include_year,
                'reset_yearly' => $range->reset_yearly,
            ]
            : [
                'prefix' => 'RE-',
                'padding' => 4,
                'next_value' => 1,
                'include_year' => true,
                'reset_yearly' => true,
            ];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var list<array<string, mixed>> $taxRates */
        $taxRates = $data['tax_rates'] ?? [];
        $this->taxRateState = array_values($taxRates);
        unset($data['tax_rates']);

        /** @var array<string, mixed> $numberRange */
        $numberRange = $data['number_range'] ?? [];
        $this->numberRangeState = $numberRange;
        unset($data['number_range']);

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

    protected function afterSave(): void
    {
        $company = $this->company();

        if ($this->taxRateState !== []) {
            $company->syncTaxRates(array_map(
                fn (array $row): array => [
                    'id' => is_string($row['id'] ?? null) ? $row['id'] : null,
                    'rate' => TaxRate::basisPointsFrom((string) $row['rate']),
                    'name' => (string) $row['name'],
                    'is_default' => (bool) ($row['is_default'] ?? false),
                ],
                $this->taxRateState,
            ));
        }

        if ($this->numberRangeState !== []) {
            $company->configureNumberRange([
                'prefix' => $this->numberRangeState['prefix'] ?: null,
                'padding' => (int) $this->numberRangeState['padding'],
                'next_value' => (int) $this->numberRangeState['next_value'],
                'include_year' => (bool) $this->numberRangeState['include_year'],
                'reset_yearly' => (bool) $this->numberRangeState['reset_yearly'],
            ]);
        }

        // The switcher in the top bar names the company, and the top bar does
        // not re-render with the page. Without this a rename shows in the form
        // and nowhere else until the next navigation.
        $this->dispatch('refresh-topbar');
    }

    private function company(): Company
    {
        $company = $this->tenant;

        throw_unless($company instanceof Company, LogicException::class, 'The settings page is only reachable inside a company.');

        return $company;
    }

    /**
     * The form state holds the enum, not its backing string, so comparing
     * against `VatScheme::SmallBusiness->value` is always false and the card
     * that should vanish stays put. Both visibility closures go through this
     * one coercion so they cannot disagree about which scheme is showing.
     */
    private function isSmallBusiness(Get $get): bool
    {
        return VatScheme::fromFormState($get('vat_scheme'))?->isSmallBusiness() ?? false;
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
}
