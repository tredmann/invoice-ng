<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * The concurrency suite truncates rather than wrapping each test in a
 * transaction. RefreshDatabase would make it untestable: a pcntl_fork()ed child
 * receives a copy of the parent's PDO socket and cannot see rows the parent has
 * not committed, so the range the children draw from would not exist for them.
 * Truncation also means DB::transactionLevel() is genuinely 0 here, which is
 * what lets the "refuses to draw outside a transaction" test mean anything.
 */
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * A user linked to every company passed in.
 */
function memberOf(Company ...$companies): User
{
    $user = User::factory()->create();
    $user->companies()->attach(array_map(fn (Company $company): string => $company->getKey(), $companies));

    return $user;
}

/**
 * Signs in a member of $company and makes it the current tenant, for Livewire
 * tests of company-scoped pages.
 *
 * The panel is booted too, because that is when Filament registers the tenant
 * scope on Customer. Without the boot a component test runs unscoped — and
 * passes against exactly the leak it exists to catch.
 */
function actInCompany(Company $company): User
{
    $user = memberOf($company);

    // Livewire::actingAs() authenticates immediately; it has to run before
    // Filament::setTenant(), whose event requires an authenticated user.
    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    Filament::bootCurrentPanel();

    return $user;
}
