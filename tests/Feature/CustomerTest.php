<?php

declare(strict_types=1);

use App\Enums\CustomerType;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

it('numbers customers per company, starting at one', function (): void {
    // Fails against a single global sequence, which would give B's first
    // customer the number 3.
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    $first = Customer::factory()->for($a)->create();
    $second = Customer::factory()->for($a)->create();
    $otherCompanysFirst = Customer::factory()->for($b)->create();

    expect([$first->number, $second->number, $otherCompanysFirst->number])
        ->toBe([1, 2, 1]);
});

it('assigns the number even when one is supplied', function (): void {
    // Factories run unguarded, so this passes a number straight past the
    // fillable list. Only the creating hook overwriting it keeps it from
    // landing — which is the property: a number is assigned, never chosen.
    $company = Company::factory()->create();

    $customer = Customer::factory()->for($company)->create(['number' => 99]);

    expect($customer->number)->toBe(1);
});

it('keeps its number when saved again', function (): void {
    // Fails if numbering runs on every save rather than on creation only.
    $company = Company::factory()->create();
    $first = Customer::factory()->for($company)->create();
    Customer::factory()->for($company)->create();

    $first->update(['name' => 'Umbenannt GmbH']);

    expect($first->fresh()?->number)->toBe(1);
});

it('refuses a customer without a company', function (): void {
    // A customer is numbered within its company. Without one there is no
    // sequence to take a number from, and a clear refusal beats the NOT NULL
    // violation that would follow.
    expect(fn () => Customer::factory()->make(['company_id' => null])->save())
        ->toThrow(LogicException::class);
});

it('backs the numbering with a unique index', function (): void {
    // The lock is what keeps numbers unique in normal operation; this index is
    // what keeps a bypassed lock from producing two customers with one number.
    // No happy-path test notices the index is missing. Written straight to the
    // table, because the model would renumber the row.
    $company = Company::factory()->create();
    $existing = Customer::factory()->for($company)->create();

    expect(fn () => DB::table('customers')->insert([
        'id' => (string) Str::uuid7(),
        'company_id' => $company->getKey(),
        'number' => $existing->number,
        'type' => CustomerType::Business->value,
        'name' => 'Doppelt GmbH',
        'street' => 'Hauptstraße 1',
        'postal_code' => '84028',
        'city' => 'Landshut',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('clears the business-only fields when a customer becomes a private person', function (): void {
    // Both directions: clearing on a switch, and keeping on an ordinary save.
    // Either half alone passes against "always clears" or "never clears".
    $company = Company::factory()->create();
    $switched = Customer::factory()->for($company)->create([
        'contact_person' => 'Sofia Kraus',
        'vat_id' => 'DE123456789',
    ]);
    $kept = Customer::factory()->for($company)->create([
        'contact_person' => 'Markus Brenner',
        'vat_id' => 'DE987654321',
    ]);

    $switched->update(['type' => CustomerType::PrivatePerson]);
    $kept->update(['name' => 'Neuer Name GmbH']);

    expect($switched->fresh()?->contact_person)->toBeNull()
        ->and($switched->fresh()?->vat_id)->toBeNull()
        ->and($kept->fresh()?->contact_person)->toBe('Markus Brenner')
        ->and($kept->fresh()?->vat_id)->toBe('DE987654321');
});

it('formats numbers with at least four digits', function (int $number, string $formatted): void {
    // 10000 is the case a fixed-width format would truncate or reject.
    expect(Customer::formatNumber($number))->toBe($formatted);
})->with([
    [1, 'K-0001'],
    [4, 'K-0004'],
    [9999, 'K-9999'],
    [10000, 'K-10000'],
]);

it('uses the formatted number as its route key', function (): void {
    $customer = Customer::factory()->for(Company::factory())->create();

    expect($customer->getRouteKey())->toBe('K-0001');
});

it('reads a route key strictly', function (string $key, ?int $number): void {
    // Eleven digits would overflow Postgres' integer column and turn a 404
    // into a 500; the UUID must not open a customer at all.
    expect(Customer::numberFromRouteKey($key))->toBe($number);
})->with([
    ['K-0004', 4],
    ['K-4', 4],
    ['K-10000', 10000],
    ['k-0004', null],
    ['0004', null],
    ['K-', null],
    ['K-abc', null],
    ['K-0004 ', null],
    ['K-99999999999', null],
    ['01999b2c-7d8e-7a3f-9c1d-2e3f4a5b6c7d', null],
]);

it('reads a search term leniently', function (string $search, ?int $number): void {
    // The search box takes the number however it is typed. "Müller" and "K-"
    // are not numbers and must not become 0.
    expect(Customer::numberFromSearch($search))->toBe($number);
})->with([
    ['K-0004', 4],
    ['k-0004', 4],
    ['0004', 4],
    ['4', 4],
    [' 4 ', 4],
    ['K-', null],
    ['Müller', null],
    ['99999999999', null],
]);

it('archives and restores a customer, keeping the first archive date', function (): void {
    /** @var TestCase $this */
    $customer = Customer::factory()->for(Company::factory())->create();

    $this->travelTo(now()->subDay());
    $customer->archive();
    $archivedAt = $customer->fresh()?->archived_at;
    $this->travelBack();

    $customer->archive();

    $freshArchivedAt = $customer->fresh()?->archived_at;
    expect($archivedAt)->not->toBeNull();
    expect($freshArchivedAt)->not->toBeNull();
    /** @var Carbon $archivedAt */
    /** @var Carbon $freshArchivedAt */
    expect($freshArchivedAt->equalTo($archivedAt))->toBeTrue();

    $customer->unarchive();

    expect($customer->fresh()?->isArchived())->toBeFalse();
});
