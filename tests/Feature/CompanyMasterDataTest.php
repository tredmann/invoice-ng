<?php

declare(strict_types=1);

use App\Enums\PaymentTerm;
use App\Models\Company;
use App\Models\NumberRange;

it('updates the rates it was sent and deactivates the ones it was not', function (): void {
    // Never deleted: system design §3.4 keeps a rate an issued Beleg was
    // computed with intact, so dropping a row from the form deactivates it.
    $company = Company::factory()->create();
    $standard = $company->taxRates()->where('rate', 1900)->sole();

    $company->syncTaxRates([
        ['id' => $standard->getKey(), 'rate' => 1900, 'name' => 'Regelsatz', 'is_default' => true],
        ['rate' => 1000, 'name' => 'Sondersatz', 'is_default' => false],
    ]);

    expect($company->taxRates()->whereNull('deactivated_at')->pluck('rate')->sort()->values()->all())
        ->toBe([1000, 1900])
        ->and($company->taxRates()->count())->toBe(4)
        ->and($company->taxRates()->where('rate', 700)->sole()->isDeactivated())->toBeTrue();
});

it('reuses a dropped rate rather than colliding with it', function (): void {
    // unique(company_id, rate) would refuse a second 7 % row, and a user who
    // removes a rate and changes their mind must not meet a database error.
    $company = Company::factory()->create();

    $company->syncTaxRates([['rate' => 1900, 'name' => 'Regelsatz', 'is_default' => true]]);

    expect($company->taxRates()->where('rate', 700)->sole()->isDeactivated())->toBeTrue();

    $company->syncTaxRates([
        ['rate' => 1900, 'name' => 'Regelsatz', 'is_default' => true],
        ['rate' => 700, 'name' => 'Ermäßigt, wieder da', 'is_default' => false],
    ]);

    $reduced = $company->taxRates()->where('rate', 700)->sole();

    expect($company->taxRates()->where('rate', 700)->count())->toBe(1)
        ->and($reduced->isDeactivated())->toBeFalse()
        ->and($reduced->name)->toBe('Ermäßigt, wieder da');
});

it('settles on one default rather than letting save order decide', function (): void {
    $company = Company::factory()->create();

    $company->syncTaxRates([
        ['rate' => 1900, 'name' => 'Regelsatz', 'is_default' => true],
        ['rate' => 700, 'name' => 'Ermäßigt', 'is_default' => true],
        ['rate' => 0, 'name' => 'Steuerfrei', 'is_default' => true],
    ]);

    expect($company->taxRates()->where('is_default', true)->pluck('rate')->all())->toBe([1900]);
});

it('falls back to the highest rate when the form flags none', function (): void {
    $company = Company::factory()->create();

    $company->syncTaxRates([
        ['rate' => 700, 'name' => 'Ermäßigt', 'is_default' => false],
        ['rate' => 1900, 'name' => 'Regelsatz', 'is_default' => false],
    ]);

    expect($company->taxRates()->where('is_default', true)->pluck('rate')->all())->toBe([1900]);
});

it('creates the Nummernkreis on the first save and updates it afterwards', function (): void {
    $company = Company::factory()->create();

    expect($company->numberRange()->exists())->toBeFalse();

    $company->configureNumberRange(['prefix' => 'RE-', 'padding' => 4, 'next_value' => 43]);

    expect($company->numberRange()->sole()->next_value)->toBe(43);

    $company->configureNumberRange(['prefix' => 'AR-', 'padding' => 5, 'next_value' => 44]);

    expect(NumberRange::query()->where('company_id', $company->getKey())->count())->toBe(1)
        ->and($company->numberRange()->sole()->prefix)->toBe('AR-')
        ->and($company->numberRange()->sole()->padding)->toBe(5);
});

it('gives a new company a Zahlungsziel without being asked', function (): void {
    // The column has a default so a company created through registration can be
    // invoiced from as soon as its identity block is complete.
    expect(Company::factory()->create()->payment_term)->toBe(PaymentTerm::Net14);
});
