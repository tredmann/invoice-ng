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
use Exception;
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
                Select::make('customer_id')
                    ->label(__('invoice.fields.customer'))
                    ->options(fn (?Document $record): array => self::customerOptions($record))
                    ->searchable()
                    ->required()
                    // The Zahlungsziel follows the chosen customer, so the form
                    // has to re-render when it changes.
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $customer = is_string($state) ? Customer::query()->find($state) : null;

                        if ($customer instanceof Customer) {
                            $set('payment_term', $customer->effectivePaymentTerm()->value);
                        }
                    }),

                Grid::make(3)->schema([
                    DatePicker::make('issued_on')
                        ->label(__('invoice.fields.issued_on'))
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->default(today())
                        ->required(),
                    Select::make('payment_term')
                        ->label(__('invoice.fields.payment_term'))
                        ->options(PaymentTerm::class)
                        ->default(fn (): string => self::companyDefaultTerm()->value)
                        ->required(),
                ]),

                Grid::make(3)->schema([
                    // The form sends these two; the model decides from them
                    // whether the Beleg carries a Leistungsdatum (BT-72) or a
                    // Leistungszeitraum (BG-14), and stores exactly one.
                    DatePicker::make('performed_from')
                        ->label(__('invoice.fields.performed_from'))
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->default(today())
                        ->required(),
                    DatePicker::make('performed_to')
                        ->label(__('invoice.fields.performed_to'))
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->afterOrEqual('performed_from')
                        ->helperText(__('invoice.fields.performed_help')),
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
                        ->table([
                            TableColumn::make(__('invoice.fields.title')),
                            TableColumn::make(__('invoice.fields.quantity'))->width('7rem'),
                            TableColumn::make(__('invoice.fields.unit'))->width('9rem'),
                            TableColumn::make(__('invoice.fields.unit_price'))->width('9rem'),
                            TableColumn::make(__('invoice.fields.tax_rate'))->width('8rem'),
                            TableColumn::make(__('invoice.fields.net'))->width('8rem')->alignEnd(),
                        ])
                        ->schema([
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
