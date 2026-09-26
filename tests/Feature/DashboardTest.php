<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\NumberRange;
use App\Models\User;
use Tests\TestCase;

it('shows neither default widget on the company dashboard', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')->assertOk()->assertDontSeeHtml('fi-account-widget')->assertDontSeeHtml('fi-filament-info-widget');
});

it('points an empty dashboard at the company settings', function (): void {
    /** @var TestCase $this */
    // The empty state became the mockup's „Erste Schritte" card when the
    // Bereitschaftsprüfung landed. What it must still do is say what to do next
    // and link there.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Erste Schritte')
        ->assertSee('Firmendaten vervollständigen')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh/settings').'"');
});

it('names on the dashboard what stops a company issuing', function (): void {
    /** @var TestCase $this */
    // A company from the factory has its identity block but no Nummernkreis,
    // so exactly one blocker should be named — and the two warnings must not
    // be presented as blockers.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Ohne diese Angaben lässt sich nichts ausstellen: Nummernkreis')
        ->assertSee('Empfohlen, aber kein Hindernis: Logo');
});

it('does not call a company blocked over a missing logo alone', function (): void {
    /** @var TestCase $this */
    // The load-bearing half of the severity split: a company with everything
    // the law asks for, an IBAN and no logo, is ready. A check that treated
    // §8.1's four items as equals would fail here.
    $company = Company::factory()->create(['name' => 'Acme GmbH', 'logo_path' => null]);
    NumberRange::factory()->for($company)->create();
    $user = User::factory()->create();
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Vollständig – aus dieser Firma lässt sich ausstellen.')
        ->assertDontSee('Ohne diese Angaben');
});
