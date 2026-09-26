<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Models\Customer;
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
        return $schema->components([
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

            // No payment term: it is company master data that does not exist
            // yet, and a per-customer default arrives with it. See the
            // customers spec, correction of 2026-09-25.
            Section::make(__('customer.sections.billing'))->schema([
                TextInput::make('email')
                    ->label(__('customer.fields.email'))
                    ->placeholder(__('customer.placeholders.email'))
                    ->helperText(__('customer.help.email'))
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
