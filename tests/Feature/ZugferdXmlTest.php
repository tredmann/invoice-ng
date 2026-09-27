<?php

declare(strict_types=1);

use App\Documents\FrozenBlock;
use App\Enums\DocumentStatus;
use App\Enums\VatScheme;
use App\Exceptions\ZugferdValidationFailed;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Zugferd\BuildZugferdXml;
use App\Zugferd\ValidateZugferdXml;
use horstoeko\zugferd\ZugferdDocumentBuilder;

/**
 * A Rechnung filled the way IssueDocument fills one, without issuing it.
 *
 * @param  array<string, mixed>  $companyOverrides
 * @param  array<string, mixed>  $documentOverrides
 */
function xmlInvoice(array $companyOverrides = [], array $documentOverrides = []): Document
{
    $company = issuableCompany($companyOverrides);
    $invoice = draftInvoice($company);
    $totals = $invoice->totals();

    $invoice->forceFill([
        'status' => DocumentStatus::Issued,
        'number' => 'RE-2026-0042',
        'due_on' => $invoice->payment_term->dueDateFrom($invoice->issued_on),
        'net_total' => $totals->net,
        'tax_total' => $totals->tax,
        'gross_total' => $totals->gross,
        'frozen_block' => FrozenBlock::of($company, customerOf($invoice)),
        ...$documentOverrides,
    ])->save();

    return $invoice->fresh()?->load('lineItems') ?? $invoice;
}

function xmlFor(Document $document): string
{
    return (new BuildZugferdXml)($document)->getContent();
}

it('passes XSD validation', function (): void {
    // The runtime gate of §7. If this fails, no Beleg can be issued at all.
    expect(fn () => (new ValidateZugferdXml)(xmlFor(xmlInvoice())))
        ->not->toThrow(ZugferdValidationFailed::class);
});

it('carries the Belegnummer, the type code and the currency', function (): void {
    $xml = xmlFor(xmlInvoice());

    expect($xml)->toContain('RE-2026-0042')
        // UNTDID 1001 380, a commercial invoice. A Gutschrift will be 389.
        ->toContain('<ram:TypeCode>380</ram:TypeCode>')
        ->toContain('EUR')
        // BT-2, the Ausstellungsdatum in EN16931's format 102.
        ->toContain('20260925');
});

it('names both parties from the Festschreibung, with their country codes', function (): void {
    // BR-09 and BR-11 make both country codes mandatory, and neither table has
    // a country column — this is the assertion that the hardcoded DE reaches
    // the XML rather than only the DTO.
    $xml = xmlFor(xmlInvoice());

    expect($xml)->toContain('Musterbetrieb GmbH')
        ->toContain('Bauer &amp; Kollegen GmbH')
        ->toContain('Musterstraße 1')
        ->toContain('Kundenweg 7')
        ->toContain('<ram:CountryID>DE</ram:CountryID>');

    expect(substr_count($xml, '<ram:CountryID>DE</ram:CountryID>'))->toBe(2);
});

it('states the Steuernummer as FC and a USt-IdNr as VA', function (): void {
    // BT-32 and BT-31. A German company states one or the other, which is what
    // CheckReadiness blocks on; sending the Steuernummer under the VAT scheme
    // would be a document the recipient reads as having an invalid VAT ID.
    $withTaxNumber = xmlFor(xmlInvoice(['tax_number' => '29/123/45678', 'vat_id' => null]));
    $withVatId = xmlFor(xmlInvoice(['tax_number' => null, 'vat_id' => 'DE811907980']));

    expect($withTaxNumber)->toContain('schemeID="FC"')
        ->and($withTaxNumber)->toContain('29/123/45678')
        ->and($withVatId)->toContain('schemeID="VA"')
        ->and($withVatId)->toContain('DE811907980');
});

it('maps a Leistungsdatum to BT-72 and a Leistungszeitraum to BG-14', function (): void {
    // The two are different structures, which is the whole reason the model
    // refuses to hold both (§7, corrected 2026-09-26).
    $onADay = xmlFor(xmlInvoice());

    expect($onADay)->toContain('ActualDeliverySupplyChainEvent')
        ->and($onADay)->not->toContain('BillingSpecifiedPeriod');

    $overAPeriod = xmlFor(xmlInvoice(documentOverrides: [
        'performed_on' => null,
        'performed_from' => '2026-09-01',
        'performed_to' => '2026-09-30',
    ]));

    expect($overAPeriod)->toContain('BillingSpecifiedPeriod')
        ->and($overAPeriod)->toContain('20260901')
        ->and($overAPeriod)->toContain('20260930')
        ->and($overAPeriod)->not->toContain('ActualDeliverySupplyChainEvent');
});

it('breaks the Umsatzsteuer down by Steuersatz, with the figures the page prints', function (): void {
    // §6's per-group rounding reaching the XML. These are the same numbers as
    // CalculateTotalsTest and RenderInvoicePdfTest: 12 × 95,00 at 19 % and
    // 1 × 290,00 at 7 %.
    $xml = xmlFor(xmlInvoice());

    expect($xml)->toContain('<ram:CalculatedAmount>216.60</ram:CalculatedAmount>')
        ->toContain('<ram:BasisAmount>1140.00</ram:BasisAmount>')
        ->toContain('<ram:CalculatedAmount>20.30</ram:CalculatedAmount>')
        ->toContain('<ram:BasisAmount>290.00</ram:BasisAmount>')
        ->toContain('<ram:LineTotalAmount>1430.00</ram:LineTotalAmount>')
        ->toContain('<ram:TaxTotalAmount currencyID="EUR">236.90</ram:TaxTotalAmount>')
        ->toContain('<ram:GrandTotalAmount>1666.90</ram:GrandTotalAmount>')
        ->toContain('<ram:DuePayableAmount>1666.90</ram:DuePayableAmount>');
});

it('carries the UN/ECE unit code straight off the Position', function (): void {
    // ADR 0003: the Unit enum is backed by the code itself, so the XML gets it
    // without a lookup table that could fall out of step with the labels.
    $xml = xmlFor(xmlInvoice());

    expect($xml)->toContain('unitCode="HUR"')
        ->toContain('unitCode="H87"');
});

it('marks a Kleinunternehmer exempt with a reason, not zero-rated', function (): void {
    // Category E with BT-120 rather than Z: §19 UStG is a genuine exemption and
    // the recipient has to be told why no VAT is charged. Z would say the
    // supply is taxable at zero, which is a different statement.
    $invoice = xmlInvoice(['vat_scheme' => VatScheme::SmallBusiness]);

    $invoice->lineItems->each(fn (LineItem $item) => $item->forceFill(['tax_rate' => 0])->saveQuietly());
    $invoice = $invoice->fresh()?->load('lineItems') ?? $invoice;

    $xml = xmlFor($invoice);

    expect($xml)->toContain('<ram:CategoryCode>E</ram:CategoryCode>')
        ->toContain('§ 19 Abs. 1 UStG');

    expect($xml)->not->toContain('<ram:CategoryCode>Z</ram:CategoryCode>');

    expect(fn () => (new ValidateZugferdXml)(xmlFor($invoice)))
        ->not->toThrow(ZugferdValidationFailed::class);
});

it('refuses XML that does not match the schema', function (string $xml): void {
    // The assertion that can fail. Without it, ValidateZugferdXml could return
    // unconditionally and every other test in this file would still be green —
    // which is precisely why it takes the XML rather than the builder that
    // cannot produce anything invalid.
    expect(fn () => (new ValidateZugferdXml)($xml))
        ->toThrow(ZugferdValidationFailed::class);
})->with([
    'not xml at all' => ['this is not xml'],
    'well-formed but foreign' => ['<?xml version="1.0"?><invoice><number>RE-1</number></invoice>'],
    'the right root, empty' => ['<?xml version="1.0"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>'],
]);

it('refuses a ZUGFeRD document with a mandatory element removed', function (): void {
    // The realistic shape of the failure: a document that is almost right. The
    // ExchangedDocumentContext carries the profile identifier every reader keys
    // off, and EN16931 makes it mandatory.
    $xml = xmlFor(xmlInvoice());
    $document = new DOMDocument;
    $document->loadXML($xml);

    $context = $document->getElementsByTagName('ExchangedDocumentContext')->item(0);

    // The fixture is built by the real builder, so the element is always there.
    assert($context instanceof DOMNode);

    $context->parentNode?->removeChild($context);

    expect(fn () => (new ValidateZugferdXml)((string) $document->saveXML()))
        ->toThrow(ZugferdValidationFailed::class);
});

it('refuses to build XML for a draft', function (): void {
    expect(fn (): ZugferdDocumentBuilder => (new BuildZugferdXml)(Invoice::factory()->create()))
        ->toThrow(LogicException::class);
});
