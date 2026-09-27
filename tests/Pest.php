<?php

declare(strict_types=1);

use App\Documents\FrozenBlock;
use App\Enums\DocumentStatus;
use App\Enums\LegalForm;
use App\Enums\PaymentTerm;
use App\Enums\Unit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\User;
use Brick\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * The concurrency suite truncates rather than wrapping each test in a
 * transaction. RefreshDatabase would make it untestable: a pcntl_fork()ed child
 * receives a copy of the parent's PDO socket and cannot see rows the parent has
 * not committed, so the range the children draw from would not exist for them.
 * Truncation also means DB::transactionLevel() is genuinely 0 here, which is
 * what lets the "refuses to draw outside a transaction" test mean anything.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * A user linked to every company passed in.
 */
function memberOf(Company ...$companies): User
{
    $user = User::factory()->create();
    $user->companies()->attach(array_map(fn (Company $company): string => $company->getKey(), $companies));

    return $user;
}

/**
 * Signs in a member of $company and makes it the current tenant, for Livewire
 * tests of company-scoped pages.
 *
 * The panel is booted too, because that is when Filament registers the tenant
 * scope on Customer. Without the boot a component test runs unscoped — and
 * passes against exactly the leak it exists to catch.
 */
function actInCompany(Company $company): User
{
    $user = memberOf($company);

    // Livewire::actingAs() authenticates immediately; it has to run before
    // Filament::setTenant(), whose event requires an authenticated user.
    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    Filament::bootCurrentPanel();

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 *
 * A company that CheckReadiness passes: address, Steuernummer, register entry
 * for a registered form, and a Nummernkreis. Anything issuing needs a company
 * like this, and building one field by field in each test is how one of them
 * ends up missing the one field under test.
 */
function issuableCompany(array $overrides = []): Company
{
    $company = Company::factory()->create([
        'name' => 'Musterbetrieb',
        'legal_form' => LegalForm::GmbH,
        'street' => 'Musterstraße 1',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'tax_number' => '29/123/45678',
        'vat_id' => null,
        'register_court' => 'Amtsgericht Charlottenburg',
        'register_number' => 'HRB 123456',
        'managing_directors' => 'Erika Mustermann',
        'bank_name' => 'Musterbank',
        'iban' => 'DE02120300000000202051',
        'bic' => 'BYLADEM1001',
        ...$overrides,
    ]);

    $company->configureNumberRange([
        'prefix' => 'RE-',
        'padding' => 4,
        'next_value' => 1,
        'include_year' => true,
        'reset_yearly' => true,
    ]);

    return $company->fresh() ?? $company;
}

/**
 * A draft Rechnung carrying the mockup's Positionen: 12 × 95,00 at 19 % and
 * 1 × 290,00 at 7 %. The same figures as CalculateTotalsTest, so the page, the
 * XML and the arithmetic cannot drift apart.
 *
 * Returned as a **draft**, deliberately. Issuing it is the caller's business —
 * a fixture that issued would be a second implementation of §8.1.
 */
function draftInvoice(?Company $company = null, int $extraLines = 0): Invoice
{
    $company ??= issuableCompany();

    $customer = Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'contact_person' => 'Herr Bauer',
        'street' => 'Kundenweg 7',
        'postal_code' => '20095',
        'city' => 'Hamburg',
    ]);

    $invoice = Invoice::factory()->for($company)->create([
        'customer_id' => $customer->getKey(),
        'issued_on' => '2026-09-25',
        'performed_on' => '2026-09-20',
        'payment_term' => PaymentTerm::Net14,
    ]);

    LineItem::factory()->for($invoice, 'document')->create([
        'position' => 1,
        'title' => 'Konzeption Netzwerkplanung',
        'description' => 'Aufnahme der Räume und Übergabe der Dokumentation',
        'quantity' => '12',
        'unit' => Unit::Hour,
        'unit_price' => Money::of('95.00', 'EUR'),
        'tax_rate' => 1900,
    ]);

    LineItem::factory()->for($invoice, 'document')->create([
        'position' => 2,
        'title' => 'Schulungsunterlagen',
        'quantity' => '1',
        'unit' => Unit::Piece,
        'unit_price' => Money::of('290.00', 'EUR'),
        'tax_rate' => 700,
    ]);

    for ($i = 3; $i < 3 + $extraLines; $i++) {
        LineItem::factory()->for($invoice, 'document')->create([
            'position' => $i,
            'title' => "Weitere Leistung Nummer {$i}",
            'description' => 'Eine Beschreibung, die über mehrere Wörter geht und Umlaute enthält.',
            'quantity' => '3',
            'unit_price' => Money::of('80.00', 'EUR'),
            'tax_rate' => 1900,
        ]);
    }

    return $invoice->fresh()?->load('lineItems') ?? $invoice;
}

/**
 * @param  array<string, mixed>  $companyOverrides
 * @param  array<string, mixed>  $documentOverrides
 *
 * A Rechnung filled the way IssueDocument fills one, without issuing it: the
 * Belegnummer, the Festschreibung, the Fälligkeitsdatum and the totals, written
 * in the one save the immutability guard permits.
 *
 * Deliberately not a call to IssueDocument. Tests of the page and of the XML are
 * about the page and the XML; going through the whole Ausstellvorgang would make
 * them fail for reasons that have nothing to do with what they assert.
 */
function issuedFixture(array $companyOverrides = [], array $documentOverrides = []): Document
{
    $company = issuableCompany($companyOverrides);
    $invoice = draftInvoice($company);
    $totals = $invoice->totals();

    $invoice->forceFill([
        'status' => DocumentStatus::Issued,
        'number' => 'RE-2026-0042',
        'due_on' => $invoice->payment_term->dueDateFrom($invoice->issued_on),
        'net_total' => $totals->net,
        'tax_total' => $totals->tax,
        'gross_total' => $totals->gross,
        'frozen_block' => FrozenBlock::of($company, customerOf($invoice)),
        ...$documentOverrides,
    ])->save();

    return $invoice->fresh()?->load('lineItems') ?? $invoice;
}

/**
 * Recompute the stored totals and the Festschreibung of a fixture whose
 * Positionen or parties were changed after it was frozen.
 *
 * Only a fixture would ever need this — an issued Beleg is unveränderlich — so
 * it lives here and not on the model, and it saves quietly for the same reason.
 */
function refreeze(Document $document): Document
{
    $document = $document->fresh()?->load('lineItems') ?? $document;
    $totals = $document->totals();

    $document->forceFill([
        'net_total' => $totals->net,
        'tax_total' => $totals->tax,
        'gross_total' => $totals->gross,
        'frozen_block' => FrozenBlock::of(companyOf($document), customerOf($document)),
    ])->saveQuietly();

    return $document->fresh()?->load('lineItems') ?? $document;
}

/**
 * The company of a Beleg, as a Company rather than a Company|null.
 *
 * Both foreign keys are required columns that restrict on delete, so neither
 * relation can actually be null — these exist so a test reads as the assertion
 * it is making rather than as a chain of null checks.
 */
function companyOf(Document $document): Company
{
    $company = $document->company;
    assert($company instanceof Company);

    return $company;
}

function customerOf(Document $document): Customer
{
    $customer = $document->customer;
    assert($customer instanceof Customer);

    return $customer;
}

/**
 * The stored path of an issued Beleg, as a string.
 */
function pdfPathOf(Document $document): string
{
    $path = $document->pdf_path;
    assert(is_string($path));

    return $path;
}
