<?php

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

it('shows the dashboard to an authenticated user', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();

    // Asserting on the user's name rather than on the word "Dashboard":
    // the app runs with locale=de and Filament ships German translations,
    // so any chrome string is a translation change away from breaking.
    // The name is data, and its presence proves the panel chrome rendered.
    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee($user->name);
});
