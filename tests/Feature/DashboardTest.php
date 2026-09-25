<?php

declare(strict_types=1);

use App\Models\Company;
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
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Noch keine Inhalte.')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh/settings').'"');
});
