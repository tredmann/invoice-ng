<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Models\Customer;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('customer.sections.customer'))->schema([
                TextInput::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Customer::formatNumber($state))
                    // Shown so the owner can see it, never written: the
                    // number is assigned on creation and does not change.
                    // Absent on create, where it does not exist yet.
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                ToggleButtons::make('type')
                    ->label(__('customer.fields.type'))
                    ->options(CustomerType::class)
                    ->default(CustomerType::Business)
                    ->inline()
                    ->required()
                    // Contact person and VAT ID appear and disappear with
                    // this, so the form re-renders on change.
                    ->live(),
                TextInput::make('name')
                    ->label(__('customer.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('contact_person')
                    ->label(__('customer.fields.contact_person'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::isBusiness($get)),
                TextInput::make('vat_id')
                    ->label(__('customer.fields.vat_id'))
                    ->maxLength(50)
                    ->visible(fn (Get $get): bool => self::isBusiness($get)),
            ]),

            Section::make(__('customer.sections.address'))->schema([
                TextInput::make('street')
                    ->label(__('customer.fields.street'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('customer.fields.postal_code'))
                    ->required()
                    ->maxLength(10),
                TextInput::make('city')
                    ->label(__('customer.fields.city'))
                    ->required()
                    ->maxLength(255),
            ]),

            Section::make(__('customer.sections.contact'))->schema([
                TextInput::make('email')
                    ->label(__('customer.fields.email'))
                    ->email()
                    ->maxLength(255),
            ]),
        ]);
    }

    /**
     * The same predicate the model clears the fields by on save, so the form
     * cannot render a field that the save then throws away, or hide one that
     * the save keeps.
     */
    private static function isBusiness(Get $get): bool
    {
        return CustomerType::fromFormState($get('type'))?->isBusiness() ?? false;
    }
}
