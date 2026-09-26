<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Actions\CalculateTotals;
use App\Enums\PaymentTerm;
use App\Enums\Unit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LineItem;
use App\Models\TaxRate;
use App\Money\Euro;
use App\Money\LineInput;
use App\Money\Totals;
use Carbon\CarbonImmutable;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column, explicitly: a Schema with none declared is given two,
        // which would set the cards side by side.
        return $schema->columns(1)->components([
            Section::make(__('invoice.sections.header'))->schema([
                // Two columns, two rows each. Left, at two thirds: who the
                // invoice is for, then when the work happened. Right, at one
                // third: the two dates that belong to the paperwork rather
                // than to the job — when it is written and when it is due.
                Grid::make(3)->schema([
                    Group::make()->columnSpan(2)->schema([
                        Select::make('customer_id')
                            ->label(__('invoice.fields.customer'))
                            ->options(fn (?Document $record): array => self::customerOptions($record))
                        // Preselected when the customer page sent us here, so
                        // „Neue Rechnung" on a customer arrives with that
                        // customer already chosen rather than an empty picker.
                            ->default(fn (): ?string => self::customerFromRequest())
                            ->searchable()
                            ->required()
                        // The Zahlungsziel follows the chosen customer, so the
                        // form has to re-render when it changes.
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $customer = is_string($state) ? Customer::query()->find($state) : null;

                                if ($customer instanceof Customer) {
                                    $set('payment_term', $customer->effectivePaymentTerm()->value);
                                }
                            }),
                        // Side by side and equal: the form sends these two,
                        // and the model decides from them whether the Beleg
                        // carries a Leistungsdatum (BT-72) or a
                        // Leistungszeitraum (BG-14), storing exactly one.
                        Grid::make(2)->schema([
                            DatePicker::make('performed_from')
                                ->label(__('invoice.fields.performed_from'))
                                ->native(false)
                                ->displayFormat('d.m.Y')
                                // Inline, so it sits inside the field's border
                                // as the board draws it rather than beside it.
                                // A picker that is not the browser's own has to
                                // say it opens something.
                                ->suffixIcon(Heroicon::OutlinedCalendar, isInline: true)
                                ->default(today())
                                ->required(),
                            DatePicker::make('performed_to')
                                ->label(__('invoice.fields.performed_to'))
                                ->native(false)
                                ->displayFormat('d.m.Y')
                                ->suffixIcon(Heroicon::OutlinedCalendar, isInline: true)
                                ->afterOrEqual('performed_from'),
                        ]),

                        Text::make(__('invoice.fields.performed_help'))
                            ->size(TextSize::Small)
                            ->color('gray'),
                    ]),

                    Group::make()->columnSpan(1)->schema([
                        DatePicker::make('issued_on')
                            ->label(__('invoice.fields.issued_on'))
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->suffixIcon(Heroicon::OutlinedCalendar, isInline: true)
                            ->default(today())
                            ->required()
                            // The Fälligkeitsdatum below follows from this and
                            // the Zahlungsziel, so both re-render on change.
                            ->live(onBlur: true),
                        Select::make('payment_term')
                            ->label(__('invoice.fields.payment_term'))
                            ->options(PaymentTerm::class)
                            ->default(fn (): string => self::companyDefaultTerm()->value)
                            ->required()
                            ->live()
                            // Shown, not stored: the Fälligkeitsdatum is
                            // derived at issue from the Ausstellungsdatum and
                            // the Zahlungsziel. Saying it here turns „14 Tage
                            // netto" from a setting into a date the reader can
                            // check.
                            ->helperText(fn (Get $get): ?string => self::dueHint($get)),
                    ]),
                ]),
            ]),

            Section::make(__('invoice.sections.positions'))
                ->description(__('invoice.sections.positions_help'))
                ->schema([
                    Repeater::make('line_items')
                        ->hiddenLabel()
                        ->addActionLabel(__('invoice.positions.add'))
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live(onBlur: true)
                        // Positionen can be dragged into another order; the
                        // Pos. column is a CSS counter over the rows, so it
                        // renumbers itself as they move rather than fighting
                        // the handles for the same cell.
                        ->reorderable()
                        // Grey, not red: removing an unsaved row of a draft is
                        // not the destructive act the colour promises, and it
                        // sits next to six fields a beginner is still filling.
                        ->deleteAction(fn (Action $action): Action => $action->color('gray'))
                        ->extraAttributes(['class' => 'app-positions-repeater'])
                        // Every cell top-aligned. Bezeichnung and Beschreibung
                        // stack, so the cell is two lines tall, and centring
                        // leaves every other field floating halfway down the
                        // row instead of level with the Bezeichnung it belongs
                        // to.
                        ->table([
                            TableColumn::make(__('invoice.fields.position'))->width('3rem')->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.title'))->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.quantity'))->width('6rem')->alignEnd()->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.unit'))->width('8rem')->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.unit_price'))->width('8rem')->alignEnd()->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.tax_rate'))->width('7rem')->verticallyAlignStart(),
                            TableColumn::make(__('invoice.fields.net'))->width('7rem')->alignEnd()->verticallyAlignStart(),
                        ])
                        ->schema([
                            // Filled by the counter in panel-styles; an empty
                            // cell is all the markup the number needs.
                            Text::make(''),
                            // Bezeichnung and Beschreibung share one cell, as
                            // the mockup stacks them: a seventh column would
                            // squeeze every other one.
                            Group::make([
                                TextInput::make('title')
                                    ->hiddenLabel()
                                    ->placeholder(__('invoice.placeholders.title'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('description')
                                    ->hiddenLabel()
                                    ->placeholder(__('invoice.placeholders.description'))
                                    ->maxLength(255),
                            ]),
                            TextInput::make('quantity')
                                ->hiddenLabel()
                                ->required()
                                ->extraInputAttributes(['class' => 'app-input-end'])
                                ->rule('regex:/^\d{1,8}([.,]\d{1,3})?$/')
                                ->live(onBlur: true),
                            Select::make('unit')
                                ->hiddenLabel()
                                ->options(Unit::class)
                                ->default(Unit::Hour->value)
                                ->required(),
                            TextInput::make('unit_price')
                                ->hiddenLabel()
                                ->suffix('€')
                                ->required()
                                ->extraInputAttributes(['class' => 'app-input-end'])
                                ->rule('regex:/^\d{1,9}([.,]\d{1,2})?$/')
                                ->live(onBlur: true),
                            Select::make('tax_rate')
                                ->hiddenLabel()
                                ->options(fn (): array => self::taxRateOptions())
                                ->default(fn (): ?int => self::defaultTaxRate())
                                ->required()
                                ->live(),
                            Text::make(fn (Get $get): string => self::lineNet($get)),
                        ]),

                    Text::make(fn (Get $get): Htmlable => self::totalsBlock($get))->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Active customers of the company in the path — the first picker in the
     * system, and so the first place the customers wave's rule is applied.
     *
     * A customer the document already names stays selectable even when
     * deactivated: a draft written before they were retired must not lose its
     * recipient the next time it is saved.
     *
     * @return array<string, string>
     */
    private static function customerOptions(?Document $record): array
    {
        $current = $record?->customer_id;

        return Customer::query()
            ->where(function (Builder $query) use ($current): void {
                $query->whereNull('deactivated_at');

                if (is_string($current)) {
                    $query->orWhereKey($current);
                }
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * „Fällig am 09.10.2026" — what the Zahlungsziel means for this invoice.
     *
     * Null while either half is missing or unparseable, which is a state the
     * form passes through while a date is being typed.
     */
    private static function dueHint(Get $get): ?string
    {
        $term = PaymentTerm::fromFormState($get('payment_term'));
        $issued = $get('issued_on');

        if (! $term instanceof PaymentTerm || ! is_string($issued) || $issued === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($issued);
        } catch (Exception) {
            return null;
        }

        return __('invoice.fields.due_hint', ['date' => $term->dueDateFrom($date)->format('d.m.Y')]);
    }

    /**
     * Only a customer of this company: the value is a query parameter, so it
     * is checked against the tenant-scoped picker rather than trusted.
     */
    private static function customerFromRequest(): ?string
    {
        $customer = request()->query('customer');

        if (! is_string($customer)) {
            return null;
        }

        return array_key_exists($customer, self::customerOptions(null)) ? $customer : null;
    }

    /**
     * @return array<int, string>
     */
    private static function taxRateOptions(): array
    {
        return self::company()
            ?->selectableTaxRates()
            ->mapWithKeys(fn (TaxRate $rate): array => [(int) $rate->rate => $rate->formattedRate()])
            ->all() ?? [];
    }

    private static function defaultTaxRate(): ?int
    {
        $rates = self::company()?->selectableTaxRates();

        $default = $rates?->firstWhere('is_default', true) ?? $rates?->first();

        return $default instanceof TaxRate ? (int) $default->rate : null;
    }

    private static function companyDefaultTerm(): PaymentTerm
    {
        $company = self::company();

        return $company instanceof Company ? $company->payment_term : PaymentTerm::Net14;
    }

    private static function company(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }

    private static function lineNet(Get $get): string
    {
        $line = self::lineFrom([
            'quantity' => $get('quantity'),
            'unit_price' => $get('unit_price'),
            'tax_rate' => $get('tax_rate'),
        ]);

        if (! $line instanceof LineInput) {
            return '—';
        }

        return Euro::format((new CalculateTotals)([$line])->net);
    }

    private static function totalsBlock(Get $get): Htmlable
    {
        $totals = self::totalsFrom($get);

        $rows = [sprintf(
            '<div><span>%s</span><span>%s</span></div>',
            e(__('invoice.positions.subtotal')),
            e(Euro::format($totals->net)),
        )];

        foreach ($totals->groups as $group) {
            $rows[] = sprintf(
                '<div><span>%s</span><span>%s</span></div>',
                e(__('invoice.positions.tax', [
                    'rate' => TaxRate::formatRate($group->rate),
                    'base' => Euro::format($group->base),
                ])),
                e(Euro::format($group->tax)),
            );
        }

        $rows[] = sprintf(
            '<div class="app-invoice-total"><span>%s</span><span>%s</span></div>',
            e(__('invoice.positions.total')),
            e(Euro::format($totals->gross)),
        );

        return new HtmlString('<div class="app-invoice-totals">'.implode('', $rows).'</div>');
    }

    private static function totalsFrom(Get $get): Totals
    {
        $rows = $get('line_items');
        $lines = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $line = self::lineFrom(is_array($row) ? $row : []);

            if ($line instanceof LineInput) {
                $lines[] = $line;
            }
        }

        return (new CalculateTotals)($lines);
    }

    /**
     * A half-typed row is skipped rather than fatal: this runs on every
     * keystroke that leaves a field, and „1," is a state a user passes through
     * on the way to „1,5".
     *
     * @param  array<string, mixed>  $row
     */
    private static function lineFrom(array $row): ?LineInput
    {
        $quantity = trim((string) ($row['quantity'] ?? ''));
        $price = trim((string) ($row['unit_price'] ?? ''));
        $rate = $row['tax_rate'] ?? null;

        if ($quantity === '' || $price === '' || $rate === null || $rate === '') {
            return null;
        }

        try {
            return LineInput::of(
                LineItem::quantityFrom($quantity),
                LineItem::priceFrom($price),
                (int) $rate,
            );
        } catch (Exception) {
            return null;
        }
    }
}
