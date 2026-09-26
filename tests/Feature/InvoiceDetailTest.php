<?php

declare(strict_types=1);

use App\Enums\PaymentTerm;
use App\Enums\Unit;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LineItem;
use Brick\Money\Money;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The invoice the `Rechnung – Entwurf (Detail)` board draws.
 */
function mockupInvoice(Company $company): Invoice
{
    $customer = Customer::factory()->for($company)->create([
        'name' => 'Lindner Consulting GmbH',
        'street' => 'Vordere Sterngasse 2',
        'postal_code' => '90402',
        'city' => 'Nürnberg',
    ]);

    $invoice = Invoice::factory()->for($company)->create([
        'customer_id' => $customer->getKey(),
        'issued_on' => '2026-09-25',
        'performed_on' => null,
        'performed_from' => '2026-09-01',
        'performed_to' => '2026-09-25',
        'payment_term' => PaymentTerm::Net14,
    ]);

    foreach ([
        ['Konzeption Netzwerkplanung', 'Bestand, Zieldefinition, Grobkonzept', '12', Unit::Hour, '95.00', 1900],
        ['Projektleitung', null, '6', Unit::Hour, '95.00', 1900],
        ['Fachliteratur (Weitergabe)', null, '1', Unit::LumpSum, '290.00', 700],
    ] as $position => [$title, $description, $quantity, $unit, $price, $rate]) {
        LineItem::factory()->for($invoice, 'document')->create([
            'position' => $position + 1,
            'title' => $title,
            'description' => $description,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => Money::of($price, 'EUR'),
            'tax_rate' => $rate,
        ]);
    }

    return $invoice;
}

it('shows the draft the way the board draws it', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $invoice = mockupInvoice($company);
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertOk()
        // Header: what it is, and for whom.
        ->assertSee('Entwurf')
        ->assertSee('Lindner Consulting GmbH')
        // Beleg: the recipient block, no number, the Leistungszeitraum.
        ->assertSee('Vordere Sterngasse 2')
        ->assertSee('90402 Nürnberg')
        ->assertSee('Kundennr. K-0001')
        ->assertSee('01.09.2026 – 25.09.2026')
        ->assertSee('14 Tage netto')
        // Positionen, with their own units and rates.
        ->assertSee('Konzeption Netzwerkplanung')
        ->assertSee('Bestand, Zieldefinition, Grobkonzept')
        ->assertSee('12 Stunde')
        ->assertSee('1 Pauschal')
        // The figures, for the fourth time in this suite.
        ->assertSee('2.000,00')
        ->assertSee('324,90')
        ->assertSee('20,30')
        ->assertSee('2.345,20');
});

it('says a draft has no number and no Fälligkeitsdatum yet', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $invoice = mockupInvoice($company);
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertOk()
        ->assertSee('Entwurf – frei änderbar. Nummer, Festschreibung und PDF entstehen erst beim Ausstellen.')
        // „14 Tage netto ab Ausstellung" carried „netto" for no reason, and
        // the same sentence built from the label would read „Sofort fällig ab
        // Ausstellung" for an immediate term. PaymentTerm::dueHint() settles
        // one phrase per case.
        ->assertSee('14 Tage ab Ausstellung')
        ->assertDontSee('14 Tage netto ab Ausstellung');
});

it('labels a single Leistungsdatum as one', function (): void {
    /** @var TestCase $this */
    // The other branch: a document with a date, not a period, must not be
    // headed „Leistungszeitraum" — the two are different EN16931 structures
    // and the label is the only thing on screen that tells them apart.
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->create(['performed_on' => '2026-09-25']);
    LineItem::factory()->for($invoice, 'document')->create();
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertOk()
        ->assertSee('Leistungsdatum')
        ->assertDontSee('Leistungszeitraum');
});

it('offers Ausstellen as something that is not ready', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $invoice = mockupInvoice($company);
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(InvoiceResource::getUrl('view', ['record' => $invoice]))
        ->assertOk()
        ->assertSee('Rechnung ausstellen')
        ->assertSee('Das Ausstellen ist noch nicht gebaut.');
});

it('deletes a draft and its Positionen from the detail page', function (): void {
    $company = Company::factory()->create();
    $invoice = mockupInvoice($company);
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->callAction('delete');

    expect(Invoice::query()->count())->toBe(0)
        ->and(LineItem::query()->count())->toBe(0);
});

it('does not offer to delete an issued document', function (): void {
    // Hidden rather than refused: the model would throw, and an action that
    // throws when pressed is a worse answer than one that is not there.
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->issued()->create();
    actInCompany($company);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertActionHidden('delete');

    expect(Invoice::query()->count())->toBe(1);
});

it('deletes a draft from the row menu, and only this company\'s', function (): void {
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    $theirs = Invoice::factory()->for($beta)->create();
    $mine = Invoice::factory()->for($alpha)->create();
    actInCompany($alpha);

    $page = Livewire::test(ListInvoices::class);

    expect(fn () => $page->callAction(TestAction::make('delete')->table($theirs)))
        ->toThrow(ActionNotResolvableException::class);

    $page->callAction(TestAction::make('delete')->table($mine));

    expect(Invoice::query()->withoutGlobalScopes()->pluck('id')->all())->toBe([$theirs->getKey()]);
});

it('refuses a document of another company at the url', function (): void {
    /** @var TestCase $this */
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    $theirs = Invoice::factory()->for($beta)->create();

    $this->actingAs(memberOf($alpha, $beta))
        ->get('/admin/alpha-gmbh/invoices/'.$theirs->getKey())
        ->assertNotFound();
});

it('answers a url that is not a uuid with a 404', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);

    $this->actingAs(memberOf($company))
        ->get('/admin/alpha-gmbh/invoices/RE-2026-0001')
        ->assertNotFound();
});
