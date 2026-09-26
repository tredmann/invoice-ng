<?php

declare(strict_types=1);

use App\Enums\VatScheme;
use App\Models\Company;
use App\Models\TaxRate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('gives a new company the three German rates, with 19 % as the default', function (): void {
    // Seeded in booted() rather than in a seeder, because a company created
    // through RegisterCompany must get them too and no seeder runs on that path.
    $company = Company::factory()->create();

    $rates = $company->taxRates()->orderByDesc('rate')->get();

    expect($rates->pluck('rate')->all())->toBe([1900, 700, 0])
        ->and($rates->where('is_default', true)->pluck('rate')->all())->toBe([1900]);
});

it('lets each company keep its own default', function (): void {
    // The partial unique index is on (company_id) and not on the table, so two
    // companies both having a 19 % default has to stay possible. An index
    // written without the company column would pass every single-company test
    // and fail here.
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    expect($a->taxRates()->where('is_default', true)->count())->toBe(1)
        ->and($b->taxRates()->where('is_default', true)->count())->toBe(1);
});

it('refuses a second default for one company', function (): void {
    // Written straight to the table: the model hook clears the other rows, so
    // going through it could never produce the row this index exists to refuse.
    // No happy path notices a missing index.
    $company = Company::factory()->create();

    expect(fn (): bool => DB::table('tax_rates')->insert([
        'id' => (string) Str::uuid7(),
        'company_id' => $company->getKey(),
        'rate' => 1600,
        'name' => 'Zweiter Standard',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses the same Steuersatz twice for one company', function (): void {
    // Two rows reading „19 %" would give the Positions picker two entries a
    // user cannot tell apart.
    $company = Company::factory()->create();

    expect(fn (): bool => DB::table('tax_rates')->insert([
        'id' => (string) Str::uuid7(),
        'company_id' => $company->getKey(),
        'rate' => 1900,
        'name' => 'Noch ein Regelsatz',
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('moves the default rather than keeping two', function (): void {
    $company = Company::factory()->create();
    $reduced = $company->taxRates()->where('rate', 700)->sole();

    $reduced->makeDefault();

    expect($company->taxRates()->where('is_default', true)->pluck('rate')->all())->toBe([700]);
});

it('offers a standard-scheme company its active rates, highest first', function (): void {
    $company = Company::factory()->create();
    $company->taxRates()->where('rate', 0)->sole()->deactivate();

    expect($company->selectableTaxRates()->pluck('rate')->all())->toBe([1900, 700]);
});

it('offers a Kleinunternehmer nothing but 0 %', function (): void {
    // §19 leaves no choice, so the rate is returned even though it was
    // deactivated while the company was still on the standard scheme — the law
    // does not consult that flag. A test using an active 0 % rate could not
    // tell this apart from plain filtering.
    $company = Company::factory()->create(['vat_scheme' => VatScheme::SmallBusiness]);
    $company->taxRates()->where('rate', 0)->sole()->deactivate();

    expect($company->selectableTaxRates()->pluck('rate')->all())->toBe([0]);
});

it('formats a Steuersatz the way a German document prints it', function (int $basisPoints, string $printed): void {
    expect(TaxRate::formatRate($basisPoints))->toBe($printed);
})->with([
    'whole' => [1900, '19 %'],
    'reduced' => [700, '7 %'],
    'zero' => [0, '0 %'],
    'one decimal' => [750, '7,5 %'],
    'two decimals' => [725, '7,25 %'],
]);

it('parses a Steuersatz however the comma was typed', function (string $typed, int $basisPoints): void {
    expect(TaxRate::basisPointsFrom($typed))->toBe($basisPoints);
})->with([
    'whole' => ['19', 1900],
    'german comma' => ['7,5', 750],
    'english point' => ['7.5', 750],
    'padded' => [' 19,00 ', 1900],
    'zero' => ['0', 0],
]);

it('keeps deactivation out of mass assignment', function (): void {
    $rate = Company::factory()->create()->taxRates()->where('rate', 700)->sole();

    $rate->fill(['deactivated_at' => now()])->save();

    expect($rate->refresh()->isDeactivated())->toBeFalse();

    $rate->deactivate();

    expect($rate->refresh()->isDeactivated())->toBeTrue();
});

it('does not lend one company its neighbour rates', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $b->taxRates()->create(['rate' => 1600, 'name' => 'Alter Satz', 'is_default' => false]);

    expect($a->taxRates()->pluck('rate')->all())->not->toContain(1600)
        ->and($b->taxRates()->count())->toBe(4);
});
