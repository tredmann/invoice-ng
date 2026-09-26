<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    /**
     * One card, two columns, every pair label over value.
     *
     * The number and the type are not here: they belong to the header, where
     * they identify the customer rather than describe them.
     *
     * The grid fills row by row, so the order below is what puts the address
     * opposite the VAT ID and the contact person opposite the creation date.
     * When a Privatkunde hides the two business entries, the rest reflows and
     * no empty labelled slot is left behind — which is what
     * CustomerRoutingTest asserts by refusing to see "Ansprechpartner".
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('customer.sections.master'))
                ->columns(2)
                ->schema([
                    TextEntry::make('billing_address')
                        ->label(__('customer.fields.billing_address'))
                        // An array with line breaks rather than built-up HTML:
                        // each line is escaped by Filament, once.
                        ->state(fn (Customer $record): array => [
                            $record->name,
                            $record->street,
                            $record->postal_code.' '.$record->city,
                        ])
                        ->listWithLineBreaks(),
                    TextEntry::make('vat_id')
                        ->label(__('customer.fields.vat_id'))
                        ->placeholder('—')
                        ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
                    TextEntry::make('contact_person')
                        ->label(__('customer.fields.contact_person'))
                        ->placeholder('—')
                        ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
                    TextEntry::make('created_at')
                        ->label(__('customer.fields.created_at'))
                        ->date('d.m.Y'),
                    TextEntry::make('email')
                        ->label(__('customer.fields.email'))
                        ->placeholder('—'),
                ]),
        ]);
    }
}
