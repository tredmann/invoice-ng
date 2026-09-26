<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Enums\PaymentTerm;
use App\Models\Company;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, explicitly: Filament gives a schema that declares none
        // two of them, which would render the four cards 2x2 from 1024px up
        // and squeeze PLZ into an eighth of the width. The board stacks them.
        return $schema->columns(1)->components([
            // The type comes first and alone: it decides which fields the
            // sections below even render, so asking it last would move the
            // form under the answer.
            Section::make(__('customer.sections.type'))
                ->description(__('customer.help.type'))
                ->schema([
                    ToggleButtons::make('type')
                        // The section heading already says Typ.
                        ->hiddenLabel()
                        ->options(CustomerType::class)
                        ->default(CustomerType::Business)
                        ->inline()
                        ->required()
                        // Contact person and VAT ID appear and disappear with
                        // this, so the form re-renders on change.
                        ->live(),
                ]),

            Section::make(__('customer.sections.master'))->schema([
                TextInput::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Customer::formatNumber($state))
                    // Shown so the owner can see it, never written: the
                    // number is assigned on creation and does not change.
                    // Absent on create, where it does not exist yet.
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('name')
                    // A Geschäftskunde has a Firmenname, a Privatkunde a name.
                    ->label(fn (Get $get): string => self::isBusiness($get)
                        ? __('customer.fields.name_business')
                        : __('customer.fields.name'))
                    ->placeholder(fn (Get $get): string => self::isBusiness($get)
                        ? __('customer.placeholders.business_name')
                        : __('customer.placeholders.private_name'))
                    ->required()
                    ->maxLength(255),
                Grid::make(3)->schema([
                    TextInput::make('contact_person')
                        ->label(__('customer.fields.contact_person'))
                        ->placeholder(__('customer.placeholders.contact_person'))
                        ->maxLength(255)
                        ->columnSpan(2)
                        ->visible(fn (Get $get): bool => self::isBusiness($get)),
                    TextInput::make('vat_id')
                        ->label(__('customer.fields.vat_id'))
                        ->placeholder(__('customer.placeholders.vat_id'))
                        ->helperText(__('customer.help.vat_id'))
                        ->maxLength(50)
                        ->visible(fn (Get $get): bool => self::isBusiness($get)),
                ]),
            ]),

            Section::make(__('customer.sections.address'))
                ->description(__('customer.help.address'))
                ->schema([
                    TextInput::make('street')
                        ->label(__('customer.fields.street'))
                        ->placeholder(__('customer.placeholders.street'))
                        ->required()
                        ->maxLength(255),
                    Grid::make(4)->schema([
                        TextInput::make('postal_code')
                            ->label(__('customer.fields.postal_code'))
                            ->placeholder(__('customer.placeholders.postal_code'))
                            ->required()
                            ->maxLength(10),
                        TextInput::make('city')
                            ->label(__('customer.fields.city'))
                            ->placeholder(__('customer.placeholders.city'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(3),
                    ]),
                ]),

            Section::make(__('customer.sections.billing'))->schema([
                TextInput::make('email')
                    ->label(__('customer.fields.email'))
                    ->placeholder(__('customer.placeholders.email'))
                    ->helperText(__('customer.help.email'))
                    ->email()
                    ->maxLength(255),
                // Empty means „whatever the company says today", which is why
                // the placeholder names the company's current default rather
                // than pre-selecting it: a copied value would stop following
                // the company the moment it was saved.
                Select::make('payment_term')
                    ->label(__('customer.fields.payment_term'))
                    ->options(PaymentTerm::class)
                    ->placeholder(__('customer.placeholders.payment_term', [
                        'term' => self::companyDefaultTerm()->getLabel(),
                    ]))
                    ->helperText(__('customer.help.payment_term')),
            ]),
        ]);
    }

    /**
     * The same predicate the model clears the fields by on save, so the form
     * cannot render a field that the save then throws away, or hide one that
     * the save keeps.
     */
    private static function companyDefaultTerm(): PaymentTerm
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company->payment_term : PaymentTerm::Net14;
    }

    private static function isBusiness(Get $get): bool
    {
        return CustomerType::fromFormState($get('type'))?->isBusiness() ?? false;
    }
}
