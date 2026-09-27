<?php

declare(strict_types=1);

use App\Actions\CheckReadiness;
use App\Actions\IssueDocument;
use App\Enums\CustomerType;
use App\Exceptions\CompanyNotReady;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Rules\TaxNumber;
use App\Rules\VatId;
use App\Zugferd\BuildZugferdXml;
use App\Zugferd\SchematronValidator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The Steuernummer and the USt-IdNr., checked where they are typed.
 *
 * Written after an invoice was issued for real carrying `iuoiuoi` as a
 * USt-IdNr. Nothing objected: the settings form took any string, the
 * Bereitschaftsprüfung asked only whether the field was non-blank, and the
 * runtime gate is XSD, which cannot express BR-CO-09. The customer's validator
 * caught it — after the value had been frozen into a Beleg that can no longer
 * be corrected.
 */
it('refuses a USt-IdNr with no country prefix', function (string $value): void {
    expect(VatId::isValid($value))->toBeFalse();
})->with([
    // The one that got through, exactly as it was typed.
    'the real one' => ['iuoiuoi'],
    'no prefix at all' => ['811907980'],
    'not a country' => ['ZZ811907980'],
    // GR is the ISO code; EN16931 and VIES both want EL for Greece.
    'Greece by its ISO code' => ['GR123456789'],
    'too short' => ['DE8119079'],
    'too long' => ['DE8119079801234'],
    'empty' => [''],
]);

it('refuses a German USt-IdNr whose check digit is wrong', function (): void {
    // The realistic error, and the reason the checksum is worth twenty lines:
    // a transposed pair copied off a letterhead. Both of these look perfectly
    // plausible and neither is a number.
    expect(VatId::isValid('DE811907980'))->toBeTrue()
        ->and(VatId::isValid('DE811097980'))->toBeFalse()
        ->and(VatId::isValid('DE123456789'))->toBeFalse();
});

it('accepts a USt-IdNr however it was typed or pasted', function (string $typed): void {
    expect(VatId::isValid($typed))->toBeTrue()
        ->and(VatId::normalize($typed))->toBe('DE811907980');
})->with([
    'plain' => ['DE811907980'],
    'spaced' => ['DE 811 907 980'],
    'lower case' => ['de811907980'],
    'with punctuation' => ['DE-811.907.980'],
    // The non-breaking space a value copied off a web page carries.
    'non-breaking space' => ["DE\u{00A0}811907980"],
]);

it('accepts another EU member state without checking its checksum', function (): void {
    // Only the German algorithm is encoded; 26 foreign ones would go stale
    // silently. The prefix is what EN16931 actually requires.
    expect(VatId::isValid('ATU13585627'))->toBeTrue()
        ->and(VatId::isValid('EL123456789'))->toBeTrue()
        ->and(VatId::isValid('XI123456789'))->toBeTrue();
});

it('refuses a Steuernummer that is too short to be one', function (): void {
    // `8989898` is the value that was actually in the database — seven digits,
    // typed to get past a required field, then printed on an issued invoice
    // under §14 Abs. 4 Nr. 2 UStG.
    expect(TaxNumber::isValid('8989898'))->toBeFalse()
        ->and(TaxNumber::isValid('abc'))->toBeFalse()
        ->and(TaxNumber::isValid('29/123/45678'))->toBeTrue()
        ->and(TaxNumber::isValid('1234567890'))->toBeTrue();
});

it('refuses to save an invalid USt-IdNr from the settings form', function (): void {
    $company = Company::factory()->create();
    $user = memberOf($company);

    Livewire::actingAs($user);
    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['vat_id' => 'iuoiuoi'])
        ->call('save')
        ->assertHasFormErrors(['vat_id']);

    expect($company->fresh()?->vat_id)->not->toBe('iuoiuoi');
});

it('refuses an invalid USt-IdNr on a customer too', function (): void {
    // BT-48 is subject to the same rule as BT-31.
    $company = Company::factory()->create();
    actInCompany($company);

    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'type' => CustomerType::Business->value,
            'name' => 'Bauer & Kollegen GmbH',
            'street' => 'Kundenweg 7',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'vat_id' => 'iuoiuoi',
        ])
        ->call('create')
        ->assertHasFormErrors(['vat_id']);
});

it('stores a USt-IdNr normalised, so one number is not two', function (): void {
    $company = Company::factory()->create(['vat_id' => 'de 811 907 980']);
    $customer = Customer::factory()->for($company)->create(['vat_id' => 'DE-811.907.980']);

    expect($company->fresh()?->vat_id)->toBe('DE811907980')
        ->and($customer->fresh()?->vat_id)->toBe('DE811907980');
});

it('calls a company with an invalid identifier not ready, and says why', function (): void {
    // „Fill this in" is bad advice for a field that is already filled in, so
    // the hint differs from the missing one.
    $company = issuableCompany(['tax_number' => null, 'vat_id' => 'iuoiuoi']);

    $readiness = (new CheckReadiness)($company);

    expect($readiness->canIssue())->toBeFalse();

    $blocker = $readiness->blockers()[0];

    expect($blocker->key)->toBe('tax_identifier')
        ->and($blocker->hint())->toContain('nicht gültig');
});

it('is not rescued by a valid Steuernummer beside an invalid USt-IdNr', function (): void {
    // Both print, and both go into the XML. One being right does not stop the
    // other being wrong.
    $company = issuableCompany(['tax_number' => '29/123/45678', 'vat_id' => 'iuoiuoi']);

    expect((new CheckReadiness)($company)->canIssue())->toBeFalse();
});

it('refuses to issue from a company whose USt-IdNr is invalid', function (): void {
    Storage::fake('local');

    $company = issuableCompany(['vat_id' => 'iuoiuoi']);
    $invoice = draftInvoice($company);

    expect(fn (): Document => (new IssueDocument)($invoice))
        ->toThrow(CompanyNotReady::class);

    // No number consumed, nothing written: the check runs before the
    // transaction opens.
    expect($invoice->fresh()?->number)->toBeNull()
        ->and((int) $company->numberRange()->first()?->next_value)->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

/**
 * The test that carries the whole approach.
 *
 * The official EN16931 rules deliberately do **not** run at issue time — they
 * cost ~145 ms under the Nummernkreis lock and the decision was to keep the
 * runtime path free of them. That is only safe if what the forms accept is a
 * subset of what EN16931 accepts. This asserts exactly that, for every shape of
 * identifier the rules let through.
 *
 * If someone loosens `VatId` and the standard disagrees, this goes red here
 * rather than in a customer's accounting software.
 */
it('produces conforming XML for every identifier the rules accept', function (array $overrides): void {
    expect(VatId::isValid($overrides['vat_id'] ?? null) || $overrides['vat_id'] === null)->toBeTrue();

    $invoice = issuedFixture($overrides);
    $failures = (new SchematronValidator)((new BuildZugferdXml)($invoice)->getContent());

    expect($failures)->toBe([], implode("\n- ", $failures));
})->with([
    'Steuernummer only' => [['tax_number' => '29/123/45678', 'vat_id' => null]],
    'USt-IdNr only' => [['tax_number' => null, 'vat_id' => 'DE811907980']],
    'both' => [['tax_number' => '29/123/45678', 'vat_id' => 'DE811907980']],
    'an Austrian USt-IdNr' => [['tax_number' => null, 'vat_id' => 'ATU13585627']],
    'a Greek one, prefixed EL' => [['tax_number' => null, 'vat_id' => 'EL123456789']],
]);

it('produces conforming XML for a customer USt-IdNr the rule accepts', function (): void {
    $invoice = issuedFixture();
    customerOf($invoice)->forceFill(['vat_id' => 'ATU13585627'])->save();

    $failures = (new SchematronValidator)((new BuildZugferdXml)(refreeze($invoice))->getContent());

    expect($failures)->toBe([], implode("\n- ", $failures));
});

it('would have caught the invoice that failed in the wild', function (): void {
    // The regression, end to end: the exact values that were in the database.
    // Build the XML as it was built then, and watch the official rules refuse
    // it — which is what nothing in this repository did at the time.
    $invoice = issuedFixture(['tax_number' => '8989898', 'vat_id' => 'iuoiuoi']);

    $failures = (new SchematronValidator)((new BuildZugferdXml)($invoice)->getContent());

    expect($failures)->not->toBeEmpty()
        ->and(implode(' ', $failures))->toContain('BR-CO-09');

    // And the readiness check now refuses it before any of that happens.
    expect((new CheckReadiness)(companyOf($invoice))->canIssue())->toBeFalse();
});
