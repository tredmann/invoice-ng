<?php

declare(strict_types=1);

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LineItem;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $attributes
 */
function invoiceFor(Company $company, array $attributes = []): Invoice
{
    return Invoice::factory()->for($company)->create($attributes);
}

it('shows a draft with no number and no Fälligkeitsdatum', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create(['name' => 'Lindner Consulting GmbH']);
    $invoice = invoiceFor($company, ['customer_id' => $customer->getKey(), 'issued_on' => '2026-09-25']);
    LineItem::factory()->for($invoice, 'document')->create([
        'quantity' => '12', 'unit_price' => Money::of('95.00', 'EUR'), 'tax_rate' => 1900,
    ]);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertSee('Entwurf')
        ->assertSee('Lindner Consulting GmbH')
        ->assertSee('25.09.2026')
        // 12 × 95,00 = 1.140,00 net, plus 19 % = 1.356,60 gross.
        ->assertSee('1.356,60');
});

it('keeps one company\'s drafts out of another\'s list', function (): void {
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    // Beta's first, so an unscoped query resolves to it.
    $theirs = invoiceFor($beta);
    $mine = invoiceFor($alpha);
    actInCompany($alpha);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('puts the newest invoice first', function (): void {
    $company = Company::factory()->create();
    $older = invoiceFor($company, ['issued_on' => '2026-08-01']);
    $newer = invoiceFor($company, ['issued_on' => '2026-09-25']);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('finds an invoice by its customer and by what is on it', function (string $search): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create(['name' => 'Weber Haustechnik e.K.']);
    $wanted = invoiceFor($company, ['customer_id' => $customer->getKey()]);
    LineItem::factory()->for($wanted, 'document')->create(['title' => 'Konzeption Netzwerkplanung']);

    $other = invoiceFor($company);
    LineItem::factory()->for($other, 'document')->create(['title' => 'Etwas anderes']);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['Weber', 'Netzwerkplanung']);

it('does not ask the database once per row', function (): void {
    // The Betrag is computed from each invoice's Positionen. Without the eager
    // load the query count grows with the list, which no assertion about the
    // rendered output would notice — the page looks identical either way.
    $company = Company::factory()->create();

    foreach (range(1, 2) as $ignored) {
        LineItem::factory()->for(invoiceFor($company), 'document')->count(2)->create();
    }
    actInCompany($company);

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListInvoices::class)->assertOk();
    $withTwo = count(DB::getQueryLog());

    foreach (range(1, 4) as $ignored) {
        LineItem::factory()->for(invoiceFor($company), 'document')->count(2)->create();
    }

    DB::flushQueryLog();
    Livewire::test(ListInvoices::class)->assertOk();
    $withSix = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($withSix)->toBe($withTwo);
});

it('says what a Rechnung is when there are none', function (): void {
    $company = Company::factory()->create();
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->assertSee('Noch keine Rechnungen.')
        ->assertSee('Rechnungen entstehen als Entwurf und bekommen ihre Nummer erst beim Ausstellen.');
});

it('says something different when a search finds nothing', function (): void {
    // Two states, because an empty list and a fruitless search need different
    // advice and a single message is wrong for one of them.
    $company = Company::factory()->create();
    invoiceFor($company);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->searchTable('gibtesnicht')
        ->assertSee('Keine Rechnung gefunden.')
        ->assertDontSee('Noch keine Rechnungen.');
});

it('shows no table furniture at all until there is an invoice', function (): void {
    // Not just an empty table: no column headers, no search box, no
    // pagination. Both directions, because a page that never showed headers
    // would pass the first assertion on its own.
    $company = Company::factory()->create();
    actInCompany($company);

    // „Betrag" and not „Nummer": the empty state's own sentence says „…
    // bekommen ihre Nummer erst beim Ausstellen", so that word is on the page
    // either way and would have made this assertion meaningless.
    Livewire::test(ListInvoices::class)
        ->assertDontSee('Betrag')
        ->assertDontSee('Suche')
        ->assertSee('Noch keine Rechnungen.');

    invoiceFor($company);

    Livewire::test(ListInvoices::class)
        ->assertSee('Betrag')
        ->assertSee('Fällig');
});

it('keeps the table furniture when a search finds nothing', function (): void {
    // The other case the empty list must not be confused with: here the table
    // is the thing that came back empty, so its headers and search box stay.
    $company = Company::factory()->create();
    invoiceFor($company);
    actInCompany($company);

    Livewire::test(ListInvoices::class)
        ->searchTable('gibtesnicht')
        ->assertSee('Betrag')
        ->assertSee('Keine Rechnung gefunden.');
});
