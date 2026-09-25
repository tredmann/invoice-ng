<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('customer.sections.customer'))->schema([
                TextEntry::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (int $state): string => Customer::formatNumber($state)),
                TextEntry::make('type')
                    ->label(__('customer.fields.type'))
                    ->badge(),
                TextEntry::make('name')
                    ->label(__('customer.fields.name')),
                TextEntry::make('contact_person')
                    ->label(__('customer.fields.contact_person'))
                    ->placeholder('—')
                    ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
                TextEntry::make('vat_id')
                    ->label(__('customer.fields.vat_id'))
                    ->placeholder('—')
                    ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
            ]),

            Section::make(__('customer.sections.address'))->schema([
                TextEntry::make('street')->label(__('customer.fields.street')),
                TextEntry::make('postal_code')->label(__('customer.fields.postal_code')),
                TextEntry::make('city')->label(__('customer.fields.city')),
            ]),

            Section::make(__('customer.sections.contact'))->schema([
                TextEntry::make('email')
                    ->label(__('customer.fields.email'))
                    ->placeholder('—'),
            ]),
        ]);
    }
}
