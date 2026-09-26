<?php

declare(strict_types=1);

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
