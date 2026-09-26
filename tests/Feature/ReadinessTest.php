<?php

declare(strict_types=1);

use App\Actions\CheckReadiness;
use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Company\Severity;
use App\Models\Company;
use App\Models\NumberRange;

function readinessOf(Company $company): Readiness
{
    return (new CheckReadiness)($company);
}

/**
 * @param  list<ReadinessItem>  $items
 * @return list<string>
 */
function keysOf(array $items): array
{
    return array_map(fn (ReadinessItem $item): string => $item->key, $items);
}

it('blocks a company that has no Nummernkreis', function (): void {
    $company = Company::factory()->create();

    $readiness = readinessOf($company);

    expect($readiness->canIssue())->toBeFalse()
        ->and(keysOf($readiness->blockers()))->toBe(['number_range']);
});

it('clears a company once the law is satisfied', function (): void {
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create();

    expect(readinessOf($company->refresh())->canIssue())->toBeTrue();
});

it('blocks a missing Steuernummer and USt-IdNr., but not one of the two', function (): void {
    $company = Company::factory()->create(['tax_number' => null, 'vat_id' => null]);
    NumberRange::factory()->for($company)->create();

    expect(keysOf(readinessOf($company->refresh())->blockers()))->toBe(['tax_identifier']);

    $company->update(['vat_id' => 'DE123456789']);

    expect(readinessOf($company->refresh())->canIssue())->toBeTrue();
});

it('blocks an incomplete address', function (): void {
    $company = Company::factory()->create(['city' => null]);
    NumberRange::factory()->for($company)->create();

    expect(keysOf(readinessOf($company->refresh())->blockers()))->toBe(['address']);
});

it('asks a GmbH for its register entry and an Einzelunternehmen for nothing', function (): void {
    // The pair is the point: with the same three columns empty, one legal form
    // is blocked and the other is ready. A check that always asked, or never
    // asked, would pass one of these and fail the other.
    $gmbh = Company::factory()->create([
        'register_court' => null, 'register_number' => null, 'managing_directors' => null,
    ]);
    NumberRange::factory()->for($gmbh)->create();

    expect(keysOf(readinessOf($gmbh->refresh())->blockers()))->toBe(['register']);

    $sole = Company::factory()->soleProprietorship()->create();
    NumberRange::factory()->for($sole)->create();

    expect(readinessOf($sole->refresh())->canIssue())->toBeTrue()
        ->and(keysOf(readinessOf($sole->refresh())->items))->not->toContain('register');
});

it('warns about the bank details and the logo without blocking on them', function (): void {
    // §14 UStG requires neither. A Rechnung without an IBAN is valid and
    // awkward to pay; one without a logo is valid and plain. Refusing to
    // invoice over either would be the application inventing a rule.
    $company = Company::factory()->create(['iban' => null, 'logo_path' => null]);
    NumberRange::factory()->for($company)->create();

    $readiness = readinessOf($company->refresh());

    expect($readiness->canIssue())->toBeTrue()
        ->and(keysOf($readiness->warnings()))->toBe(['bank', 'logo']);
});

it('treats whitespace as absent', function (): void {
    // A column holding " " is not an address. Without trimming, a company
    // could pass the check on a value that prints as nothing on the document.
    $company = Company::factory()->create(['street' => '   ']);
    NumberRange::factory()->for($company)->create();

    expect(keysOf(readinessOf($company->refresh())->blockers()))->toBe(['address']);
});

it('gives every item a German label and hint, in both states', function (): void {
    // __() returns the key when a translation is missing, so a new item added
    // without wording would otherwise show its key on the dashboard.
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create();

    foreach (readinessOf($company->refresh())->items as $item) {
        expect($item->label())->not->toContain('company.readiness')
            ->and($item->hint())->not->toContain('company.readiness');
    }
});

it('marks the two conveniences as warnings and the rest as blocking', function (): void {
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create();

    $severities = [];

    foreach (readinessOf($company->refresh())->items as $item) {
        $severities[$item->key] = $item->severity;
    }

    expect($severities)->toBe([
        'address' => Severity::Blocking,
        'tax_identifier' => Severity::Blocking,
        'register' => Severity::Blocking,
        'number_range' => Severity::Blocking,
        'bank' => Severity::Warning,
        'logo' => Severity::Warning,
    ]);
});
