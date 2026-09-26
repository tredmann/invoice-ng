<?php

declare(strict_types=1);

use App\Enums\PaymentTerm;
use App\Enums\Unit;
use App\Enums\VatScheme;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LineItem;
use Brick\Money\Money;
use Livewire\Livewire;

/**
 * The three Positionen of the `Rechnung – Neu (Entwurf)` board.
 *
 * @return list<array<string, mixed>>
 */
function mockupPositions(): array
{
    return [
        ['title' => 'Konzeption Netzwerkplanung', 'description' => 'Bestand, Zieldefinition, Grobkonzept',
            'quantity' => '12', 'unit' => Unit::Hour->value, 'unit_price' => '95,00', 'tax_rate' => 1900],
        ['title' => 'Projektleitung', 'description' => null,
            'quantity' => '6', 'unit' => Unit::Hour->value, 'unit_price' => '95,00', 'tax_rate' => 1900],
        ['title' => 'Fachliteratur (Weitergabe)', 'description' => null,
            'quantity' => '1', 'unit' => Unit::LumpSum->value, 'unit_price' => '290,00', 'tax_rate' => 700],
    ];
}

it('creates a draft in the current company, for a customer of that company', function (): void {
    // Beta's customers exist first, so an unscoped picker resolves to one of
    // theirs and an unassociated tenant files the draft under the wrong company.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    Customer::factory()->for($beta)->count(3)->create();
    $mine = Customer::factory()->for($alpha)->create(['name' => 'Lindner Consulting GmbH']);

    actInCompany($alpha);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $mine->getKey(),
            'issued_on' => '2026-09-25',
            'performed_from' => '2026-09-01',
            'performed_to' => '2026-09-25',
            'payment_term' => PaymentTerm::Net14->value,
            'line_items' => mockupPositions(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->withoutGlobalScopes()->sole();

    expect($invoice->company_id)->toBe($alpha->getKey())
        ->and($invoice->customer_id)->toBe($mine->getKey())
        ->and($invoice->status->isDraft())->toBeTrue()
        ->and($invoice->number)->toBeNull()
        ->and($invoice->lineItems()->count())->toBe(3);
});

it('writes the Positionen in the order they were typed, with their own values', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issued_on' => '2026-09-25',
            'performed_from' => '2026-09-25',
            'payment_term' => PaymentTerm::Net14->value,
            'line_items' => mockupPositions(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lines = Invoice::query()->withoutGlobalScopes()->sole()->lineItems;

    expect($lines->pluck('position')->all())->toBe([1, 2, 3])
        ->and($lines[0]?->title)->toBe('Konzeption Netzwerkplanung')
        ->and($lines[0]?->description)->toBe('Bestand, Zieldefinition, Grobkonzept')
        ->and((string) $lines[0]?->quantity)->toBe('12.000')
        ->and($lines[0]?->unit)->toBe(Unit::Hour)
        ->and((string) $lines[0]?->unit_price->getAmount())->toBe('95.00')
        ->and($lines[0]?->tax_rate)->toBe(1900)
        ->and($lines[2]?->unit)->toBe(Unit::LumpSum)
        ->and($lines[1]?->description)->toBeNull();
});

it('totals the form to the figures the mockup prints', function (): void {
    // The same invoice as CalculateTotalsTest and DocumentTest, now through
    // the screen. Three levels, one set of numbers, no room to drift.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issued_on' => '2026-09-25',
            'performed_from' => '2026-09-25',
            'payment_term' => PaymentTerm::Net14->value,
            'line_items' => mockupPositions(),
        ])
        ->assertSee('2.000,00')
        ->assertSee('324,90')
        ->assertSee('20,30')
        ->assertSee('2.345,20');
});

it('stores a Leistungszeitraum or a Leistungsdatum, from the same two fields', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issued_on' => '2026-09-25',
            'performed_from' => '2026-09-25',
            'performed_to' => null,
            'payment_term' => PaymentTerm::Net14->value,
            'line_items' => mockupPositions(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->withoutGlobalScopes()->sole();

    expect($invoice->performed_on?->toDateString())->toBe('2026-09-25')
        ->and($invoice->performed_from)->toBeNull();
});

it('offers only the current company\'s active customers', function (): void {
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    $theirs = Customer::factory()->for($beta)->create(['name' => 'Fremd GmbH']);
    $gone = Customer::factory()->for($alpha)->deactivated()->create(['name' => 'Stillgelegt GmbH']);
    $mine = Customer::factory()->for($alpha)->create(['name' => 'Aktiv GmbH']);

    actInCompany($alpha);

    Livewire::test(CreateInvoice::class)
        ->assertSee('Aktiv GmbH')
        ->assertDontSee('Fremd GmbH')
        ->assertDontSee('Stillgelegt GmbH');

    expect([$theirs->getKey(), $gone->getKey(), $mine->getKey()])->toHaveCount(3);
});

it('keeps a deactivated customer selectable on a draft that already names them', function (): void {
    // The other direction. A draft written before a customer was retired must
    // not lose its recipient the next time it is saved — and a picker that
    // only ever filtered would do exactly that.
    $company = Company::factory()->create();
    $retired = Customer::factory()->for($company)->create(['name' => 'Stillgelegt GmbH']);
    $invoice = Invoice::factory()->for($company)->create(['customer_id' => $retired->getKey()]);
    LineItem::factory()->for($invoice, 'document')->create();
    $retired->deactivate();

    actInCompany($company);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
        ->assertFormSet(['customer_id' => $retired->getKey()])
        ->assertSee('Stillgelegt GmbH');
});

it('offers a Kleinunternehmer 0 % and nothing else', function (): void {
    $company = Company::factory()->create(['vat_scheme' => VatScheme::SmallBusiness]);
    Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->assertSee('0 %')
        ->assertDontSee('19 %')
        ->assertDontSee('7 %');
});

it('reads a draft back into the form and saves it changed', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    $invoice = Invoice::factory()->for($company)->create([
        'customer_id' => $customer->getKey(),
        'performed_on' => '2026-09-25',
    ]);
    LineItem::factory()->for($invoice, 'document')->create([
        'position' => 1, 'title' => 'Erste Fassung', 'quantity' => '2',
        'unit_price' => Money::of('50.00', 'EUR'), 'tax_rate' => 1900,
    ]);

    actInCompany($company);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
        // The stored Leistungsdatum comes back in the field the form sends.
        ->assertFormSet(['performed_from' => '2026-09-25'])
        ->fillForm([
            'performed_from' => '2026-10-01',
            'line_items' => [
                ['title' => 'Zweite Fassung', 'description' => null, 'quantity' => '3',
                    'unit' => Unit::Day->value, 'unit_price' => '120,00', 'tax_rate' => 700],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $invoice->fresh();

    expect($fresh?->performed_on?->toDateString())->toBe('2026-10-01')
        ->and($fresh?->lineItems()->count())->toBe(1)
        ->and($fresh?->lineItems->first()?->title)->toBe('Zweite Fassung')
        ->and($fresh?->lineItems->first()?->position)->toBe(1);
});

it('insists on a customer and at least one Position', function (): void {
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['customer_id' => null, 'line_items' => []])
        ->call('create')
        ->assertHasFormErrors(['customer_id', 'line_items']);
});

it('preselects a customer named in the url, and only a real one', function (): void {
    // Both halves: the create page arriving from a customer starts with that
    // customer, and a key from another company is ignored rather than trusted
    // — it is a query parameter, so it is checked against the tenant-scoped
    // picker.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    $mine = Customer::factory()->for($alpha)->create();
    $theirs = Customer::factory()->for($beta)->create();

    actInCompany($alpha);

    Livewire::withQueryParams(['customer' => $mine->getKey()])
        ->test(CreateInvoice::class)
        ->assertFormSet(['customer_id' => $mine->getKey()]);

    Livewire::withQueryParams(['customer' => $theirs->getKey()])
        ->test(CreateInvoice::class)
        ->assertFormSet(['customer_id' => null]);
});

it('says which day the Zahlungsziel falls due on', function (): void {
    // The board computes it live. „14 Tage netto" is a setting; „Fällig am
    // 09.10.2026" is the thing a reader can check — and September having 30
    // days is what an off-by-one would show up as.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['issued_on' => '2026-09-25', 'payment_term' => PaymentTerm::Net14->value])
        ->assertSee('Fällig am 09.10.2026');
});

it('keeps the Positionen row at the number of cells its layout assumes', function (): void {
    // panel-styles lays each row out as a grid and finds two cells by
    // counting from the end: the Pos. number at nth-last-child(9) and
    // Beschreibung at nth-last-child(2). Adding or removing a field in the
    // repeater silently moves both — the number lands in the wrong column and
    // Beschreibung stops spanning, with nothing failing anywhere. This is
    // what fails instead.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    $html = (string) Livewire::test(CreateInvoice::class)->html();

    $row = (string) str($html)->after('<tbody')->between('<tr', '</tr>');

    // Pos., Bezeichnung, Menge, Einheit, Einzelpreis, Steuer, Netto,
    // Beschreibung, and the actions cell. The drag handle adds a tenth, but
    // only once a second row exists to reorder.
    expect(substr_count($row, '<td'))->toBe(9);
});

it('numbers the Positionen and offers to reorder them', function (): void {
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    $html = (string) Livewire::test(CreateInvoice::class)->html();

    // The header the counter sits under, and the class the counter is keyed
    // to — without the class the numbers silently disappear, since there is
    // no CSS build to fail loudly.
    expect($html)->toContain('Pos.')
        ->and($html)->toContain('app-positions-repeater');
});
