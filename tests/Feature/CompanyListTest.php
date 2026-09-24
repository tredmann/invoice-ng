<?php

declare(strict_types=1);

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

it('lists companies other than the one being viewed', function (): void {
    // The whole point of marking the resource unscoped. Left scoped, Filament
    // constrains the query to the current tenant and the list shows exactly
    // one row — the company you are already in — which makes switching
    // impossible.
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    // Livewire::actingAs() authenticates immediately as a side effect; it has
    // to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($current);

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$current, $other]);
});

it('archives a company from the row actions', function (): void {
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    Livewire::actingAs($user);

    Filament::setTenant($current);

    Livewire::test(ListCompanies::class)
        ->callTableAction('archive', $other);

    expect($other->fresh()?->isArchived())->toBeTrue();
});

it('hides the archive action on the last active company', function (): void {
    // The guard lives on the model, which throws. Hiding the action is what
    // keeps the user from meeting that exception as a 500.
    $only = Company::factory()->create();

    $user = User::factory()->create();
    $user->companies()->attach($only);

    Livewire::actingAs($user);

    Filament::setTenant($only);

    Livewire::test(ListCompanies::class)
        ->assertTableActionHidden('archive', $only);
});

it('offers unarchive only on an archived company', function (): void {
    $active = Company::factory()->create();
    $archived = Company::factory()->archived()->create();

    $user = User::factory()->create();
    $user->companies()->attach([$active->getKey(), $archived->getKey()]);

    Livewire::actingAs($user);

    Filament::setTenant($active);

    Livewire::test(ListCompanies::class)
        ->assertTableActionVisible('unarchive', $archived)
        ->assertTableActionHidden('unarchive', $active);
});

it('keeps working after archiving the company being viewed', function (): void {
    /** @var TestCase $this */
    // Filament still has this tenant set for the rest of the request, while it
    // has just left getTenants(). The page must render rather than blow up on
    // a tenant that is no longer switchable.
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    Livewire::actingAs($user);

    Filament::setTenant($current);

    Livewire::test(ListCompanies::class)
        ->callTableAction('archive', $current)
        ->assertSuccessful();

    expect($current->fresh()?->isArchived())->toBeTrue();

    // And its URL still resolves, because its documents stay readable.
    $this->actingAs($user)
        ->get('/admin/erste-gmbh/companies')
        ->assertOk();
});

it('serves the list under the company slug', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh/companies')
        ->assertOk()
        ->assertSee('Acme GmbH');
});
