<?php

declare(strict_types=1);

use App\Enums\PaymentTerm;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use Tests\TestCase;

it('resolves a customer number within the company in the url', function (): void {
    /** @var TestCase $this */
    // Both companies have a K-0001. A lookup that ignores the tenant finds
    // whichever row comes first and fails one of the two halves; a test in
    // which only one company had a K-0001 would pass against exactly that bug.
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create(['name' => 'Kunde von Alpha']);
    Customer::factory()->for($beta)->create(['name' => 'Kunde von Beta']);

    $this->actingAs(memberOf($alpha, $beta));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kunde von Alpha')
        ->assertDontSee('Kunde von Beta');

    $this->get('/admin/beta-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kunde von Beta')
        ->assertDontSee('Kunde von Alpha');
});

it('does not find a number that exists only in another company', function (): void {
    /** @var TestCase $this */
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create();
    Customer::factory()->for($beta)->count(2)->create();

    $this->actingAs(memberOf($alpha, $beta))
        ->get('/admin/alpha-gmbh/customers/K-0002')
        ->assertNotFound();
});

it('keeps a user out of the customers of a company they do not belong to', function (): void {
    /** @var TestCase $this */
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);
    Customer::factory()->for($theirs)->create();

    $this->actingAs(memberOf($mine))
        ->get('/admin/theirs-gmbh/customers/K-0001')
        ->assertNotFound();
});

it('generates customer urls from the customer number', function (): void {
    // Fails if the route key is still the UUID — the page would work when
    // typed by hand while every generated link pointed somewhere else.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $customer = Customer::factory()->for($company)->create();

    expect(CustomerResource::getUrl('view', ['record' => $customer], tenant: $company))
        ->toEndWith('/admin/alpha-gmbh/customers/K-0001');
});

it('answers a malformed or foreign key in the url with a 404', function (string $key): void {
    /** @var TestCase $this */
    // A 404, not a 500: an eleven-digit number would overflow the integer
    // column if it reached the query. The UUID must not open the customer.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $customer = Customer::factory()->for($company)->create();

    $key = $key === 'uuid' ? $customer->getKey() : $key;

    $this->actingAs(memberOf($company))
        ->get("/admin/alpha-gmbh/customers/{$key}")
        ->assertNotFound();
})->with(['K-abc', 'K-99999999999', 'k-0001', '0001', 'uuid']);

it('shows a business customer with its contact person and a private person without', function (): void {
    /** @var TestCase $this */
    // Both directions: a view that always or never renders the business-only
    // entries fails one half.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'contact_person' => 'Sofia Kraus',
        'street' => 'Leopoldstraße 12',
    ]);
    Customer::factory()->for($company)->privatePerson()->create(['name' => 'Dr. Annika Vogel']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Bauer & Kollegen GmbH')
        ->assertSee('K-0001')
        ->assertSee('Leopoldstraße 12')
        ->assertSee('Sofia Kraus')
        ->assertSee('Ansprechpartner');

    $this->get('/admin/alpha-gmbh/customers/K-0002')
        ->assertOk()
        ->assertSee('Dr. Annika Vogel')
        ->assertSee('Privatkunde')
        ->assertDontSee('Ansprechpartner');
});

it('lists Kunden in the sidebar of a company', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);

    $this->actingAs(memberOf($company))
        ->get('/admin/alpha-gmbh')->assertOk()->assertSeeHtml('/admin/alpha-gmbh/customers')
        ->assertSee('Kunden');
});

it('carries the number and the type in the detail header', function (): void {
    /** @var TestCase $this */
    // The mockup moves the number out of the body and into the subtitle, and
    // puts the type beside the name. Asserting the two together is what
    // distinguishes a real header from a number that only appears in a card.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kundennr. K-0001')
        ->assertSee('Geschäftskunde');
});

it('shows when the customer was created, in German', function (): void {
    /** @var TestCase $this */
    // "Kunde seit" reads created_at, which nothing has ever rendered. Asserting
    // the German form and not just the year is what distinguishes it from a raw
    // timestamp or an ISO date.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $this->travelTo('2025-03-14 09:00:00');
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);
    $this->travelBack();

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kunde seit')
        ->assertSee('14.03.2025');
});

it('leaves no business-only label behind for a private customer', function (): void {
    /** @var TestCase $this */
    // A two-column card is the shape that leaves an empty labelled slot behind
    // when a field is hidden. Both labels, because each is hidden separately.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->privatePerson()->create(['name' => 'Dr. Annika Vogel']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertDontSee('Ansprechpartner')
        ->assertDontSee('USt-IdNr.');
});

it('prints a long name and a long email in full on the detail page', function (): void {
    /** @var TestCase $this */
    // Guards the markup, not the layout: what would break this is someone
    // reaching for ->limit() or ->lineClamp() on the entries to tidy the
    // two-column card. Whether the columns hold at a narrow width is a
    // question for eyes, not for this test.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create([
        'name' => 'Ingenieurgemeinschaft Hofmann, Weber & Partner mbB Süd',
        'email' => 'rechnungseingang.zentrale@hofmann-weber-partner.de',
    ]);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Ingenieurgemeinschaft Hofmann, Weber & Partner mbB Süd')
        ->assertSee('rechnungseingang.zentrale@hofmann-weber-partner.de');
});

it('shows the overview tiles at zero while there are no invoices', function (): void {
    /** @var TestCase $this */
    // Zero with a count, not a blank and not a dash: a blank tile reads as a
    // loading failure, and the figures only become real with the invoicing
    // wave.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Umsatz '.now()->year)
        ->assertSee('seit 01.01.'.now()->year)
        ->assertSee('Offene Forderungen')
        ->assertSee('Überfällig')
        ->assertSee('0,00 €')
        ->assertSee('0 Rechnungen');
});

it('says a customer has no invoices yet', function (): void {
    /** @var TestCase $this */
    // The card exists before the invoices do, so the page keeps its shape.
    // An absent card and an empty card look the same to a test that only
    // asserts the heading, hence the empty-state line.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Noch keine Rechnungen.');
});

it('lays the customer page out in one full-width column', function (): void {
    /** @var TestCase $this */
    // ViewRecord::defaultInfolist() forces columns(2) onto a schema that sets
    // none of its own, which put the three tiles into a right-hand column from
    // 1024px up — the column this redesign removed. Exactly one two-column
    // grid may survive on the page: the master-data card's own.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create();

    $this->actingAs(memberOf($company));

    $html = (string) $this->get('/admin/alpha-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(substr_count($html, '--cols-lg: repeat(2'))->toBe(1);
});

it('sets the tile figures apart from their sub-lines', function (): void {
    /** @var TestCase $this */
    // A Text defaults to gray-600, and ->color('gray') on it is a no-op
    // because the component already declares gray as its default. Without an
    // explicit emphasis colour the figure and its note render in the same
    // grey and only size separates them. One neutral figure per tile.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create();

    $this->actingAs(memberOf($company));

    $html = (string) $this->get('/admin/alpha-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(substr_count($html, 'fi-color-neutral'))->toBe(3);
});

it('shows the Zahlungsziel that would actually be used', function (): void {
    /** @var TestCase $this */
    // A customer with none of their own still shows a term, because the next
    // Rechnung would use the company's. A blank here would read as "not set"
    // for something that is always set in practice.
    $company = Company::factory()->create(['payment_term' => PaymentTerm::Net30]);
    $customer = Customer::factory()->for($company)->create(['payment_term' => null]);
    $user = actInCompany($company);

    $this->actingAs($user)
        ->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Zahlungsziel')
        ->assertSee('30 Tage netto');
});
