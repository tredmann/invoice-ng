<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Tests\TestCase;

it('shows the companies of the user as tiles at /admin', function (): void {
    /** @var TestCase $this */
    // A 200 is the half that matters: Filament registers its own /admin route,
    // which redirects to the default company, so if the picker does not take
    // that route over this is a 302 and fails here.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));
    $user->companies()->attach(Company::factory()->soleProprietorship()->create(['name' => 'Beta Handel']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Alpha GmbH', 'Beta Handel'])
        // The legal form under the name, as its German label, so a tile shows
        // the label rather than the enum value.
        ->assertSee('Einzelunternehmen')
        ->assertDontSee('sole_proprietorship');
});

it('links each tile to its company by slug', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin')->assertOk()->assertSeeHtml('href="'.url('/admin/acme-gmbh').'"');
});

it('leaves archived companies and other users companies off the picker', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Mine GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Archived GmbH']));
    Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Mine GmbH')
        ->assertDontSee('Archived GmbH')
        ->assertDontSee('Theirs GmbH');
});

it('offers to create a new company from the picker', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $this->actingAs($user)
        ->get('/admin')->assertOk()->assertSeeHtml('href="'.url('/admin/new').'"');
});

it('lands on the picker after login, not inside a company', function (): void {
    // Filament's own login response resolves to the default company. With it
    // in place this redirects to /admin/acme-gmbh.
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secret-password']);
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    Livewire::test(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/admin'));
});

it('offers the way back to the picker from inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Alle Firmen');
});
