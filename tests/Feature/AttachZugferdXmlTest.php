<?php

declare(strict_types=1);

use App\Pdf\AttachZugferdXml;
use App\Pdf\RenderInvoicePdf;
use App\Zugferd\BuildZugferdXml;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Illuminate\Support\Facades\Process;

/**
 * The merge that makes one file both readable and machine-readable (§7).
 *
 * What these guard against is the silent version of a broken merge: a PDF that
 * opens, prints and looks perfect, with no XML inside it. Nothing about the page
 * would reveal that, and the customer's accounting software would reject the
 * invoice as a plain PDF months later.
 */
it('produces a PDF with the EN16931 XML inside it', function (): void {
    $invoice = issuedFixture();

    $merged = (new AttachZugferdXml)(
        (new RenderInvoicePdf)($invoice),
        (new BuildZugferdXml)($invoice),
    );

    // Read the file the way a recipient's software would, rather than asserting
    // on our own bytes.
    $xml = ZugferdDocumentPdfReader::getXmlFromContent($merged);

    expect($xml)->toContain('RE-2026-0042')
        ->and($xml)->toContain('CrossIndustryInvoice');
});

it('gives the recipient a document that parses to the same figures as the row', function (): void {
    $invoice = issuedFixture();

    $merged = (new AttachZugferdXml)(
        (new RenderInvoicePdf)($invoice),
        (new BuildZugferdXml)($invoice),
    );

    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($merged);
    $reader->getDocumentInformation(
        $number, $typeCode, $documentDate, $currency, $taxCurrency,
        $name, $language, $effective,
    );

    expect($number)->toBe($invoice->number)
        ->and($typeCode)->toBe('380')
        ->and($currency)->toBe('EUR')
        ->and($documentDate?->format('Y-m-d'))->toBe('2026-09-25');
});

it('stays a readable PDF after the merge', function (): void {
    // FPDI rebuilds the document to attach the XML and write the PDF/A-3
    // metadata. A rebuild that dropped the page content would still produce a
    // file with the XML in it — and an invoice the customer cannot read.
    $invoice = issuedFixture();

    $merged = (new AttachZugferdXml)(
        (new RenderInvoicePdf)($invoice),
        (new BuildZugferdXml)($invoice),
    );

    $path = tempnam(sys_get_temp_dir(), 'merged').'.pdf';
    file_put_contents($path, $merged);
    $text = Process::run(['pdftotext', '-layout', $path, '-'])->output();
    unlink($path);

    expect(substr($merged, 0, 5))->toBe('%PDF-')
        ->and($text)->toContain('RE-2026-0042')
        ->and($text)->toContain('Bauer & Kollegen GmbH')
        // The umlauts have to survive the rebuild too, not only the render.
        ->and($text)->toContain('Musterstraße 1')
        ->and($text)->toContain('Gesamtbetrag');
});

it('declares itself a ZUGFeRD PDF/A-3 rather than a plain one', function (): void {
    $invoice = issuedFixture();

    $merged = (new AttachZugferdXml)(
        (new RenderInvoicePdf)($invoice),
        (new BuildZugferdXml)($invoice),
    );

    // The Factur-X XMP extension schema and the attachment relationship are what
    // make a reader look for the XML at all. Without them the file is a PDF that
    // happens to carry an attachment, and conforming software ignores it.
    //
    // This is not a conformance check: whether the container is *valid* PDF/A-3
    // needs veraPDF or the KoSIT validator, which is a recorded gap.
    expect($merged)->toContain('factur-x.xml')
        ->and($merged)->toContain('AFRelationship')
        ->and($merged)->toContain('urn:factur-x');
});
