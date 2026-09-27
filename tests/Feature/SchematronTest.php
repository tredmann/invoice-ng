<?php

declare(strict_types=1);

use App\Enums\VatScheme;
use App\Models\Document;
use App\Models\LineItem;
use App\Zugferd\BuildZugferdXml;
use App\Zugferd\SchematronValidator;

/**
 * The golden fixtures, judged by the official EN16931 Schematron rather than by
 * our own assertions (system design §14).
 *
 * Every other XML test in this repo checks that a value we chose reached the
 * place we expected. This one checks the thing that actually matters: whether
 * the document satisfies the published business rules. It is the only test here
 * whose verdict we did not write.
 */
it('produces a document the EN16931 rules accept', function (string $case, callable $build): void {
    $invoice = $build();
    $xml = (new BuildZugferdXml)($invoice)->getContent();

    $failures = (new SchematronValidator)($xml);

    expect($failures)->toBe([], sprintf("%s violates EN16931:\n- %s", $case, implode("\n- ", $failures)));
})->with([
    // Tech stack §11.4's list, minus the two whose Belegarten are not built.
    'mixed 19 % and 7 %' => ['mixed 19 % and 7 %', fn (): Document => issuedFixture()],

    'Kleinunternehmer at 0 %' => ['Kleinunternehmer at 0 %', function (): Document {
        $invoice = issuedFixture(['vat_scheme' => VatScheme::SmallBusiness]);
        $invoice->lineItems->each(fn (LineItem $item) => $item->forceFill(['tax_rate' => 0])->saveQuietly());

        return $invoice->fresh()?->load('lineItems') ?? $invoice;
    }],

    'a fractional quantity' => ['a fractional quantity', function (): Document {
        $invoice = issuedFixture();
        $invoice->lineItems->first()?->forceFill(['quantity' => '7.500'])->saveQuietly();

        return refreeze($invoice);
    }],

    'a Leistungszeitraum' => ['a Leistungszeitraum', fn (): Document => issuedFixture(documentOverrides: [
        'performed_on' => null,
        'performed_from' => '2026-09-01',
        'performed_to' => '2026-09-30',
    ])],

    'no bank details' => ['no bank details', fn (): Document => issuedFixture([
        'bank_name' => null,
        'iban' => null,
        'bic' => null,
    ])],

    'a Privatkunde with no USt-IdNr' => ['a Privatkunde with no USt-IdNr', function (): Document {
        $invoice = issuedFixture();
        customerOf($invoice)->forceFill(['vat_id' => null, 'contact_person' => null])->save();

        return refreeze($invoice);
    }],
]);

it('reports a violation rather than passing everything', function (): void {
    // The assertion that can fail. A SchematronValidator that returned [] no
    // matter what would make every case above green, so one document is broken
    // on purpose: BR-CO-13 says the Bemessungsgrundlage must equal the sum of
    // the line amounts, and this one no longer does.
    $xml = (new BuildZugferdXml)(issuedFixture())->getContent();
    $broken = str_replace(
        '<ram:LineTotalAmount>1430.00</ram:LineTotalAmount>',
        '<ram:LineTotalAmount>1400.00</ram:LineTotalAmount>',
        $xml,
    );

    expect($broken)->not->toBe($xml);

    $failures = (new SchematronValidator)($broken);

    expect($failures)->not->toBeEmpty();
});
