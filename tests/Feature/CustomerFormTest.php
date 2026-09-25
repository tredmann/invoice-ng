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
    // Fails if the number field is dehydrated on create or `number` becomes
    // fillable and the hook stops overwriting it.
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

it('shows contact person and vat id for a Firma only', function (): void {
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
    // The save path must not renumber the customer or clear archived_at — the
    // form knows neither field as writable.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->archived()->create();
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
        ->and($customer->isArchived())->toBeTrue();
});

it('clears contact person and vat id when the form switches a Firma to a Privatperson', function (): void {
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
