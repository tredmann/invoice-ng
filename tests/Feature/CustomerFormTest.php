<?php

declare(strict_types=1);

use App\Enums\CustomerType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * @return array<string, string>
 */
function validCustomer(): array
{
    return [
        'type' => CustomerType::Business->value,
        'name' => 'Bauer & Kollegen GmbH',
        'street' => 'Leopoldstraße 12',
        'postal_code' => '80802',
        'city' => 'München',
        'email' => 'rechnung@bauer-kollegen.de',
    ];
}

it('creates the customer in the current company with its next number', function (): void {
    // Beta already has three customers. A customer saved without the tenant,
    // or numbered across companies, would come out as Beta's or as number 4.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    Customer::factory()->for($beta)->count(3)->create();

    actInCompany($alpha);

    Livewire::test(CreateCustomer::class)
        ->fillForm(validCustomer())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => 'K-0001']));

    // Unscoped, so the assertion is about the row and not about what the
    // tenant scope lets this query see.
    $customer = Customer::query()->withoutGlobalScopes()
        ->where('name', 'Bauer & Kollegen GmbH')
        ->sole();

    expect($customer->company_id)->toBe($alpha->getKey())
        ->and($customer->number)->toBe(1);
});

it('ignores a customer number smuggled into the create form', function (): void {
    // Guards the form path end to end: a number submitted through the create
    // form must never land. The model test "assigns the number even when one
    // is supplied" pins the creating hook itself.
    $company = Company::factory()->create();
    actInCompany($company);

    Livewire::test(CreateCustomer::class)
        ->fillForm(validCustomer())
        ->set('data.number', 99)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::query()->withoutGlobalScopes()->sole()->number)->toBe(1);
});

it('requires the name and the address, and nothing else', function (): void {
    // Both directions in one submission: the required fields error and the
    // optional ones do not. Required-everything and required-nothing each
    // fail one half.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'type' => CustomerType::Business->value,
            'name' => '',
            'contact_person' => '',
            'vat_id' => '',
            'street' => '',
            'postal_code' => '',
            'city' => '',
            'email' => '',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'street' => 'required',
            'postal_code' => 'required',
            'city' => 'required',
        ])
        ->assertHasNoFormErrors(['contact_person', 'vat_id', 'email']);
});

it('rejects an email that is not an address', function (): void {
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm([...validCustomer(), 'email' => 'keine-adresse'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

it('shows contact person and vat id for a Geschäftskunde only', function (): void {
    // Both directions: always-visible and never-visible each fail one half.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm(['type' => CustomerType::PrivatePerson->value])
        ->assertFormFieldHidden('contact_person')
        ->assertFormFieldHidden('vat_id')
        ->fillForm(['type' => CustomerType::Business->value])
        ->assertFormFieldVisible('contact_person')
        ->assertFormFieldVisible('vat_id');
});

it('round-trips umlauts and ampersands through the form', function (): void {
    /** @var TestCase $this */
    // German names and streets are the normal case. Checked in the database
    // and on the rendered view page, where the ampersand must arrive escaped
    // once.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $user = actInCompany($company);

    Livewire::test(CreateCustomer::class)
        ->fillForm([...validCustomer(), 'name' => 'Ölmühle Müller & Söhne', 'street' => 'Grünstraße 7'])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::query()->withoutGlobalScopes()->sole();

    expect($customer->name)->toBe('Ölmühle Müller & Söhne')
        ->and($customer->street)->toBe('Grünstraße 7');

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()->assertSee('Ölmühle Müller & Söhne')->assertDontSeeHtml('Ã');
});

it('shows the number read-only on edit and not at all on create', function (): void {
    $company = Company::factory()->create();
    Customer::factory()->for($company)->count(2)->create();
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0002'])
        ->assertFormSet(['number' => 'K-0002'])
        ->assertFormFieldIsDisabled('number');

    Livewire::test(CreateCustomer::class)
        ->assertFormFieldHidden('number');
});

it('keeps number and deactivation when a deactivated customer is edited', function (): void {
    // The save path must not renumber the customer or clear deactivated_at — the
    // form knows neither field as writable.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->deactivated()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0001'])
        ->fillForm(['city' => 'Regensburg'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => 'K-0001']));

    $customer->refresh();

    expect($customer->city)->toBe('Regensburg')
        ->and($customer->number)->toBe(1)
        ->and($customer->isDeactivated())->toBeTrue();
});

it('edits only the current company\'s customer when numbers collide', function (): void {
    // Fails if the edit page's record lookup ignores the tenant: K-0001 exists
    // in both companies, so an unscoped lookup could load or save Beta's row
    // while acting in Alpha.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    // Beta's customer is created first, so an unscoped "number = 1" lookup
    // would resolve to it rather than to Alpha's — an accident of insertion
    // order that would otherwise mask a missing tenant filter.
    $theirs = Customer::factory()->for($beta)->create(['name' => 'Weber Haustechnik e.K.', 'city' => 'Hamburg']);
    $mine = Customer::factory()->for($alpha)->create(['name' => 'Bauer & Kollegen GmbH', 'city' => 'München']);
    actInCompany($alpha);

    Livewire::test(EditCustomer::class, ['record' => 'K-0001'])
        ->assertFormSet(['name' => 'Bauer & Kollegen GmbH'])
        ->fillForm(['city' => 'Regensburg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mine->fresh()?->city)->toBe('Regensburg')
        ->and($theirs->fresh()?->city)->toBe('Hamburg');
});

it('clears contact person and vat id when the form switches a Geschäftskunde to a Privatkunde', function (): void {
    // End to end through the form, because a hidden field is exactly what the
    // form does not send.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create([
        'contact_person' => 'Sofia Kraus',
        'vat_id' => 'DE123456789',
    ]);
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0001'])
        ->fillForm(['type' => CustomerType::PrivatePerson->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $customer->refresh();

    expect($customer->contact_person)->toBeNull()
        ->and($customer->vat_id)->toBeNull();
});

it('labels the name field for the chosen customer type', function (): void {
    // A Geschäftskunde has a Firmenname, a Privatkunde has a name. One static
    // label is wrong for one of them, and the mockup labels it per type.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm(['type' => CustomerType::Business->value])
        ->assertSee('Firmenname')
        ->fillForm(['type' => CustomerType::PrivatePerson->value])
        ->assertDontSee('Firmenname');
});

it('tells the user on the create page that the number comes on save', function (): void {
    // The create form has no number field, and the mockup explains the gap
    // rather than leaving the owner to wonder where the number went.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->assertSee('Die Kundennummer wird beim Speichern vergeben.')
        ->assertSee('Kunde speichern');
});
