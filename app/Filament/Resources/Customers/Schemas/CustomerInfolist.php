<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Customer;
use App\Money\Euro;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

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
        // One column, explicitly: ViewRecord::defaultInfolist() imposes
        // columns(2) on any schema that declares none, which would put the
        // tiles beside the master data instead of under it.
        return $schema->columns(1)->components([
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
                    // Always the term the next Rechnung would actually use,
                    // company default included — the mockup shows a value
                    // here, not a blank for „not set".
                    TextEntry::make('payment_term')
                        ->label(__('customer.fields.payment_term'))
                        ->state(fn (Customer $record): string => $record->effectivePaymentTerm()->getLabel()),
                ]),

            // Zero until the invoicing wave lands. Built from Section and Text
            // rather than a StatsOverviewWidget because the design puts these
            // between the master data and the invoice list, and a widget can
            // only render before or after the whole infolist.
            Grid::make(['default' => 1, 'md' => 3])->schema([
                self::tile(
                    __('customer.stats.revenue', ['year' => now()->year]),
                    __('customer.stats.revenue_since', ['year' => now()->year]),
                ),
                self::tile(
                    __('customer.stats.open'),
                    trans_choice('customer.stats.invoice_count', 0),
                ),
                self::tile(
                    __('customer.stats.overdue'),
                    trans_choice('customer.stats.invoice_count', 0),
                ),
            ]),

            // No "Alle Rechnungen" link: it would point nowhere. It arrives
            // with the invoicing wave, together with the table it summarises.
            Section::make(__('customer.invoices.heading'))->schema([
                EmptyState::make(__('customer.invoices.empty'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (Customer $record): bool => $record->documents()->doesntExist()),
                Text::make(fn (Customer $record): Htmlable => self::documents($record))
                    ->visible(fn (Customer $record): bool => $record->documents()->exists())
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * The customer's Belege, newest first. Each row is a link, because the
     * reason to look at this card is to get to one of them.
     */
    private static function documents(Customer $customer): Htmlable
    {
        $rows = '';

        foreach ($customer->documents as $document) {
            $rows .= '<tr>'
                .'<td><a href="'.e(InvoiceResource::getUrl('view', ['record' => $document])).'">'
                .e($document->number === null
                    ? __('invoice.view.draft_heading')
                    : (string) $document->number)
                .'</a></td>'
                .'<td>'.e($document->status->getLabel()).'</td>'
                .'<td>'.e($document->issued_on->format('d.m.Y')).'</td>'
                .'<td class="app-positions-end">'.e(Euro::format($document->totals()->gross)).'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<table class="app-positions">'
            .'<thead><tr>'
            .'<th>'.e(__('customer.invoices.heading')).'</th>'
            .'<th>'.e(__('customer.invoices.status')).'</th>'
            .'<th>'.e(__('customer.invoices.issued_on')).'</th>'
            .'<th class="app-positions-end">'.e(__('customer.invoices.total')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
        );
    }

    /**
     * One overview tile: a label, the figure, a quiet sub-line.
     *
     * The figure is hard-coded at zero because there are no invoices to count
     * yet. The mockup prints the overdue figure in red; at zero that would
     * warn about nothing, so the colour arrives with the data.
     */
    private static function tile(string $label, string $note): Section
    {
        return Section::make($label)->schema([
            Text::make(__('customer.stats.zero'))
                ->size(TextSize::Large)
                ->weight(FontWeight::Bold)
                // Text defaults to gray; without this the figure and its note
                // render in the same colour and only size tells them apart.
                ->color('neutral'),
            Text::make($note)
                ->size(TextSize::Small),
        ]);
    }
}
