<?php

use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

it('redirects the root url to the panel', function (): void {
    /** @var TestCase $this */
    $this->get('/')->assertRedirect('/admin');
});

it('refuses the dashboard to guests', function (): void {
    /** @var TestCase $this */
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('serves the login page', function (): void {
    /** @var TestCase $this */
    $this->get('/admin/login')->assertOk();
});

it('shows the dashboard of the users company', function (): void {
    /** @var TestCase $this */
    // Tenancy means the dashboard now lives under the company's slug, and a
    // user with no company is redirected to registration instead — so this
    // test needs a company to have anything to show.
    //
    // Asserting on the dashboard's own hint: the account widget that used to
    // print the user's name is gone.
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Noch keine Inhalte.');
});
