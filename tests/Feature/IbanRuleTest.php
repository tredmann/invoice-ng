<?php

declare(strict_types=1);

use App\Models\Company;
use App\Rules\Iban;
use Illuminate\Support\Facades\Validator;

function ibanPasses(mixed $value): bool
{
    return Validator::make(['iban' => $value], ['iban' => new Iban])->passes();
}

it('accepts a valid german iban', function (): void {
    expect(ibanPasses('DE89370400440532013000'))->toBeTrue();
});

it('accepts an iban as it is printed on a statement', function (): void {
    // Grouped in fours and sometimes lower case. Rejecting this would be a bug
    // in the validator, not in the input.
    expect(ibanPasses('DE89 3704 0044 0532 0130 00'))->toBeTrue()
        ->and(ibanPasses('de89370400440532013000'))->toBeTrue();
});

it('accepts an iban separated by non-breaking spaces', function (): void {
    // An IBAN copied off an online-banking page carries U+00A0, not the plain
    // space U+0020 the "printed on a statement" case above covers. `\s`
    // without the `/u` modifier does not match it, so this fails without
    // that modifier even though the equivalent plain-space input passes.
    $nonBreakingSpace = "\u{00A0}";
    $iban = implode($nonBreakingSpace, ['DE89', '3704', '0044', '0532', '0130', '00']);

    expect(ibanPasses($iban))->toBeTrue();
});

it('rejects an iban with two digits transposed', function (): void {
    // DE98… is DE89… with the check digits swapped. mod-97 detects every
    // transposition of adjacent digits, which is the realistic typing error,
    // and is the whole reason the checksum is here. A rule that only checked
    // the length and the country prefix would pass this.
    expect(ibanPasses('DE98370400440532013000'))->toBeFalse()
        ->and(ibanPasses('DE89370400440532010300'))->toBeFalse();
});

it('rejects things that are not ibans', function (): void {
    expect(ibanPasses('not an iban'))->toBeFalse()
        ->and(ibanPasses('DE89'))->toBeFalse()
        ->and(ibanPasses(12345))->toBeFalse();
});

it('accepts a valid iban from another country', function (): void {
    // The rule is mod-97 plus a length range, not a table of German lengths.
    expect(ibanPasses('AT611904300234573201'))->toBeTrue();
});

it('reports a german message rather than a bare translation key', function (): void {
    $validator = Validator::make(['iban' => 'nope'], ['iban' => new Iban]);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('iban'))->not->toContain('company.errors');
});

it('stores the iban normalised, whatever was typed', function (): void {
    // Otherwise the same account is stored three ways and no two companies
    // compare equal.
    $company = Company::factory()->create(['iban' => 'de89 3704 0044 0532 0130 00']);

    expect($company->fresh()?->iban)->toBe('DE89370400440532013000');
});

it('leaves an absent iban alone, because bank details are optional', function (): void {
    // Spec §7: a valid IBAN is required whenever one is given, and bank details
    // as a whole stay optional until a document needs them. Laravel skips a
    // non-implicit rule for an empty value, which is exactly what the settings
    // form needs — it carries this rule without required(). This test fails if
    // the rule is ever made implicit.
    expect(ibanPasses(''))->toBeTrue()
        ->and(ibanPasses(null))->toBeTrue();
});
