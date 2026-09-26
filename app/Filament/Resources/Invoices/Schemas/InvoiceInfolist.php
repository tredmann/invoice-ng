<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Customer;
use App\Models\Document;
use App\Models\LineItem;
use App\Models\TaxRate;
use App\Money\Euro;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Two columns — unlike the customer page, which deliberately dropped its right
 * column. This is what the board draws for a Beleg: the document on the left,
 * what is true of it on the right.
 */
class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        // Two independent columns, not a three-column grid of cards. A grid
        // lays out in *rows*, so Positionen would wait for the taller of
        // Beleg and Status and leave a gap beneath the shorter one — which is
        // exactly what it did.
        return $schema->columns(3)->components([
            Group::make()->columnSpan(2)->schema([
                Section::make(__('invoice.view.beleg'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('recipient')
                                ->label(__('invoice.view.recipient'))
                                // An array with line breaks rather than built-up
                                // HTML, so Filament escapes each line once.
                                ->state(fn (Document $record): array => self::recipient($record))
                                ->listWithLineBreaks(),
                            Text::make(fn (Document $record): Htmlable => self::belegRows($record)),
                        ]),
                        Text::make(fn (Document $record): string => $record->status->isDraft()
                            ? __('invoice.view.draft_note')
                            : '')
                            ->color('gray')
                            ->size(TextSize::Small),
                    ]),

                Section::make(__('invoice.sections.positions'))
                    ->schema([
                        Text::make(fn (Document $record): Htmlable => self::positions($record))->columnSpanFull(),
                    ]),
            ]),

            Group::make()->columnSpan(1)->schema([
                // No card heading: „Status" is a label in the same small grey
                // as „Betrag" and „Fällig", not a title over them. One block
                // rather than five components, because the gap between the
                // label and its value is a quarter of the gap between pairs —
                // a schema puts the same gap everywhere.
                Section::make()
                    ->schema([
                        Text::make(fn (Document $record): Htmlable => self::statusBlock($record)),
                    ]),

                Section::make(__('invoice.view.history'))
                    ->schema([
                        // One derived entry, the way the customer page shows
                        // „Kunde seit". A real Verlauf needs AuditEntry, and the
                        // events worth recording — issued, PDF written, sent,
                        // paid — are all in the wave that can perform them.
                        Text::make(fn (Document $record): Htmlable => self::historyBlock($record)),
                    ]),
            ]),
        ]);
    }

    private static function statusBlock(Document $record): Htmlable
    {
        $status = $record->status;

        $badge = Blade::render(
            '<x-filament::badge color="{{ $color }}" size="sm">{{ $label }}</x-filament::badge>',
            ['color' => $status->getColor(), 'label' => $status->getLabel()],
        );

        return new HtmlString(
            '<dl class="app-status">'
            .'<div><dt>'.e(__('invoice.view.status')).'</dt><dd>'.$badge.'</dd></div>'
            .'<div><dt>'.e(__('invoice.view.total')).'</dt>'
            .'<dd class="app-status-amount">'.e(Euro::format($record->totals()->gross)).'</dd></div>'
            // No Fälligkeitsdatum yet: it is the Ausstellungsdatum plus the
            // Zahlungsziel, and the first half does not exist until the
            // document is issued.
            .'<div><dt>'.e(__('invoice.view.due')).'</dt>'
            .'<dd>'.e($record->payment_term->dueHint()).'</dd></div>'
            .'</dl>'
        );
    }

    private static function historyBlock(Document $record): Htmlable
    {
        return new HtmlString(
            '<ul class="app-history"><li>'
            .'<span class="app-history-dot"></span>'
            .'<div><span class="app-history-event">'.e(__('invoice.view.created')).'</span>'
            .'<span class="app-history-when">'
            .e($record->created_at?->translatedFormat('d.m.Y, H:i') ?? '')
            .'</span></div>'
            .'</li></ul>'
        );
    }

    /**
     * @return list<string>
     */
    private static function recipient(Document $record): array
    {
        $customer = $record->customer;

        if (! $customer instanceof Customer) {
            return [];
        }

        return [
            $customer->name,
            $customer->street,
            $customer->postal_code.' '.$customer->city,
            __('invoice.view.customer_number', ['number' => $customer->formattedNumber()]),
        ];
    }

    private static function belegRows(Document $record): Htmlable
    {
        $rows = [
            __('invoice.view.number') => $record->number === null
                ? __('invoice.not_yet')
                : (string) $record->number,
            __('invoice.view.issued_on') => $record->issued_on->format('d.m.Y'),
            self::performedLabel($record) => self::performedValue($record),
            __('invoice.view.payment_term') => $record->payment_term->getLabel(),
        ];

        $html = '';

        foreach ($rows as $label => $value) {
            $html .= '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
        }

        return new HtmlString('<dl class="app-beleg-rows">'.$html.'</dl>');
    }

    private static function performedLabel(Document $record): string
    {
        return $record->performed_on !== null
            ? __('invoice.view.performed_single')
            : __('invoice.view.performed');
    }

    private static function performedValue(Document $record): string
    {
        if ($record->performed_on !== null) {
            return $record->performed_on->format('d.m.Y');
        }

        if ($record->performed_from !== null && $record->performed_to !== null) {
            return $record->performed_from->format('d.m.Y').' – '.$record->performed_to->format('d.m.Y');
        }

        return __('invoice.not_yet');
    }

    private static function positions(Document $record): Htmlable
    {
        $head = '<thead><tr>'
            .'<th>'.e(__('invoice.fields.position')).'</th>'
            .'<th>'.e(__('invoice.fields.title')).'</th>'
            .'<th class="app-positions-end">'.e(__('invoice.fields.quantity')).'</th>'
            .'<th class="app-positions-end">'.e(__('invoice.fields.unit_price')).'</th>'
            .'<th class="app-positions-end">'.e(__('invoice.fields.tax_rate')).'</th>'
            .'<th class="app-positions-end">'.e(__('invoice.fields.net')).'</th>'
            .'</tr></thead>';

        $body = '';

        foreach ($record->lineItems as $item) {
            $body .= '<tr>'
                .'<td class="app-positions-index">'.e((string) $item->position).'</td>'
                .'<td>'.e($item->title)
                .($item->description === null ? '' : '<span class="app-positions-note">'.e($item->description).'</span>')
                .'</td>'
                .'<td class="app-positions-end">'.e(self::quantity($item)).'</td>'
                .'<td class="app-positions-end">'.e(Euro::format($item->unit_price)).'</td>'
                .'<td class="app-positions-end">'.e(TaxRate::formatRate($item->tax_rate)).'</td>'
                .'<td class="app-positions-end">'.e(Euro::format(self::lineNet($item))).'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<table class="app-positions app-positions-ruled">'.$head.'<tbody>'.$body.'</tbody></table>'
            .self::totals($record)
        );
    }

    private static function quantity(LineItem $item): string
    {
        return LineItem::formatQuantity((string) $item->quantity).' '.$item->unit->getLabel();
    }

    private static function lineNet(LineItem $item): Money
    {
        return $item->unit_price->multipliedBy(
            (string) $item->quantity,
            RoundingMode::HalfUp,
        );
    }

    private static function totals(Document $record): string
    {
        $totals = $record->totals();

        $rows = '<div><span>'.e(__('invoice.positions.subtotal')).'</span>'
            .'<span>'.e(Euro::format($totals->net)).'</span></div>';

        foreach ($totals->groups as $group) {
            $rows .= '<div><span>'.e(__('invoice.positions.tax', [
                'rate' => TaxRate::formatRate($group->rate),
                'base' => Euro::format($group->base),
            ])).'</span><span>'.e(Euro::format($group->tax)).'</span></div>';
        }

        $rows .= '<div class="app-invoice-total"><span>'.e(__('invoice.positions.total')).'</span>'
            .'<span>'.e(Euro::format($totals->gross)).'</span></div>';

        return '<div class="app-invoice-totals">'.$rows.'</div>';
    }
}
