<?php

declare(strict_types=1);

use App\Enums\LegalForm;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A user linked to every company passed in.
 *
 * @param  array<int, Company>  $companies
 */
function userOf(array $companies): User
{
    $user = User::factory()->create();
    $user->companies()->attach(collect($companies)->map->getKey()->all());

    return $user;
}

it('serves a company settings page under the company slug', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $this->actingAs(userOf([$company]))
        ->get('/admin/acme-gmbh/settings')
        ->assertOk()
        ->assertSee('Acme GmbH');
});

it('shows the company named in the url, not the one in the session', function (): void {
    /** @var TestCase $this */
    // The load-bearing test for §3.2. A session-only implementation passes any
    // single-request version of this: it only fails once a second company is
    // requested in the same session and the first one's data comes back.
    $first = Company::factory()->create(['name' => 'Erste GmbH', 'city' => 'Hamburg']);
    $second = Company::factory()->create(['name' => 'Zweite GmbH', 'city' => 'Rosenheim']);
    $user = userOf([$first, $second]);

    $this->actingAs($user);

    $this->get('/admin/erste-gmbh/settings')
        ->assertOk()
        ->assertSee('Hamburg')
        ->assertDontSee('Rosenheim');

    $this->get('/admin/zweite-gmbh/settings')
        ->assertOk()
        ->assertSee('Rosenheim')
        ->assertDontSee('Hamburg');
});

it('refuses the settings of a company the user is not linked to', function (): void {
    /** @var TestCase $this */
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs(userOf([$mine]))
        ->get('/admin/theirs-gmbh/settings')
        ->assertNotFound();
});

it('round-trips umlauts through the settings form', function (): void {
    /** @var TestCase $this */
    // This repository has already shipped a PDF in which every German
    // character was mojibake while the suite stayed green. German company
    // names and streets are the normal case here, not an edge one.
    $company = Company::factory()->create(['name' => 'Ölmühle Müller GmbH']);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm([
            'street' => 'Grünstraße 7',
            'city' => 'Rosenheim',
            'postal_code' => '83022',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->street)->toBe('Grünstraße 7');

    $this->actingAs($user)
        ->get('/admin/olmuhle-muller-gmbh/settings')
        ->assertOk();

    // Filament's TextInput never puts its value in a server-rendered `value`
    // attribute — the browser fills it in from the Livewire snapshot payload
    // instead, which PHP's json_encode() escapes to \uXXXX for any non-ASCII
    // character. assertSee() on the raw response therefore cannot tell a
    // correct umlaut from a mojibake'd one; it cannot see either. Mounting
    // the same page as a component and reading its actual state is what
    // proves the character survived the round trip intact.
    Livewire::test(CompanySettings::class)
        ->assertSet('data.street', 'Grünstraße 7');
});

it('requires the register fields of a company that is in the handelsregister', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm([
            'legal_form' => LegalForm::GmbH->value,
            'register_court' => '',
            'register_number' => '',
            'managing_directors' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['register_court', 'register_number', 'managing_directors']);
});

it('saves a sole proprietorship with no register fields at all', function (): void {
    // The other direction. Without it, the test above cannot tell "required
    // for a GmbH" from "required always".
    $company = Company::factory()->soleProprietorship()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['legal_form' => LegalForm::SoleProprietorship->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->register_number)->toBeNull();
});

it('clears register data when a company stops being registered', function (): void {
    // The fields are hidden for a sole proprietorship, and a hidden Filament
    // field is not dehydrated — so without this the old HRB number would sit
    // in the database and print on the next document.
    $company = Company::factory()->create([
        'register_court' => 'Amtsgericht München',
        'register_number' => 'HRB 12345',
        'managing_directors' => 'Tobias Redmann',
    ]);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['legal_form' => LegalForm::SoleProprietorship->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $company->fresh();

    expect($fresh?->register_court)->toBeNull()
        ->and($fresh?->register_number)->toBeNull()
        ->and($fresh?->managing_directors)->toBeNull();
});

it('insists on at least one tax identifier', function (): void {
    // Which of the two a company has depends on its VAT scheme, so neither can
    // be required on its own — but a company with neither cannot issue a legal
    // invoice.
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['tax_number' => '', 'vat_id' => ''])
        ->call('save')
        ->assertHasFormErrors(['tax_number', 'vat_id']);
});

it('accepts a company with only a steuernummer', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['tax_number' => '143/815/08151', 'vat_id' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->vat_id)->toBeNull();
});

it('rejects an invalid iban on the settings form', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['iban' => 'DE98370400440532013000'])
        ->call('save')
        ->assertHasFormErrors(['iban']);
});

it('never lets the slug be edited', function (): void {
    // The slug is a stable public identifier. If the form could write it, a
    // rename would break every bookmarked URL.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['slug' => 'something-else'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->slug)->toBe('acme-gmbh');
});
