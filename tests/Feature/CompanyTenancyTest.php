<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

it('carries uuids through both company_user foreign keys', function (): void {
    // The half that silently rots: companies.id can be a uuid while
    // company_user.company_id stays bigint, and nothing complains until an
    // attach is attempted.
    $columns = DB::select(
        'select column_name, data_type from information_schema.columns
         where table_name = ? and column_name in (?, ?)',
        ['company_user', 'company_id', 'user_id']
    );

    expect($columns)->toHaveCount(2);

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('uuid');
    }
});

it('offers the user only their own non-archived companies as tenants', function (): void {
    $user = User::factory()->create();
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $archived = Company::factory()->archived()->create(['name' => 'Archived GmbH']);
    $someoneElses = Company::factory()->create(['name' => 'Theirs GmbH']);

    $user->companies()->attach([$mine->getKey(), $archived->getKey()]);

    $tenants = $user->getTenants(Filament::getPanel('admin'));

    // Three assertions because each rules out a different wrong
    // implementation: returning everything, returning archived companies, and
    // returning nothing at all.
    expect($tenants->pluck('name')->all())->toBe(['Mine GmbH'])
        ->and($tenants->contains($archived))->toBeFalse()
        ->and($tenants->contains($someoneElses))->toBeFalse();
});

it('still lets the user into an archived company they are linked to', function (): void {
    // An archived company leaves the switcher but keeps its URL, because its
    // historical documents have to stay readable.
    $user = User::factory()->create();
    $archived = Company::factory()->archived()->create();

    $user->companies()->attach($archived);

    expect($user->canAccessTenant($archived))->toBeTrue();
});

it('keeps the user out of a company they are not linked to', function (): void {
    // This is the tenancy boundary. Filament calls exactly this method before
    // setting the tenant for the request.
    $user = User::factory()->create();
    $theirs = Company::factory()->create();

    expect($user->canAccessTenant($theirs))->toBeFalse();
});

it('orders tenants by name, so the default company is deterministic', function (): void {
    // Filament picks the user's default tenant off the front of this list and
    // redirects /admin to it. Unordered, which company you land on depends on
    // insertion order.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));

    expect($user->getTenants(Filament::getPanel('admin'))->pluck('name')->all())
        ->toBe(['Alpha GmbH', 'Zeta GmbH']);
});
