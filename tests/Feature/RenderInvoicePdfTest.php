<?php

declare(strict_types=1);

use App\Documents\FrozenBlock;
use App\Enums\DocumentStatus;
use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Pdf\RenderInvoicePdf;
use Illuminate\Support\Facades\Process;

/**
 * The printed Beleg, read back out of the PDF.
 *
 * `pdftotext` catches input-encoding bugs and missing content; it does NOT catch
 * a missing font, because it reads the ToUnicode CMap, which is populated
 * whether or not the font holds a renderable glyph. `pdffonts` catches a font
 * that was not embedded at all. Neither catches a page that is laid out wrongly
 * — look at it after changing the template, the fonts or the WeasyPrint version
 * (CLAUDE.md).
 */
function renderedText(Document $document): string
{
    $bytes = (new RenderInvoicePdf)($document);

    $path = tempnam(sys_get_temp_dir(), 'beleg').'.pdf';
    file_put_contents($path, $bytes);

    $text = Process::run(['pdftotext', '-layout', $path, '-'])->output();

    unlink($path);

    return $text;
}

/**
 * @param  array<string, mixed>  $companyOverrides
 *
 * A Rechnung filled the way IssueDocument fills one, without issuing it: the
 * number, the Festschreibung, the Fälligkeitsdatum and the totals, in one save
 * on a draft — the only shape the immutability guard permits.
 *
 * Deliberately not a call to IssueDocument. These tests are about the page, and
 * a render test that went through the whole Ausstellvorgang would fail for
 * reasons that have nothing to do with the template.
 */
function issuedInvoice(array $companyOverrides = [], int $extraLines = 0): Document
{
    $company = issuableCompany($companyOverrides);
    $invoice = draftInvoice($company, $extraLines);
    $customer = customerOf($invoice);

    $totals = $invoice->totals();

    $invoice->forceFill([
        'status' => DocumentStatus::Issued,
        'number' => 'RE-2026-0042',
        'due_on' => $invoice->payment_term->dueDateFrom($invoice->issued_on),
        'net_total' => $totals->net,
        'tax_total' => $totals->tax,
        'gross_total' => $totals->gross,
        'frozen_block' => FrozenBlock::of($company, $customer),
    ])->save();

    return $invoice->fresh()?->load('lineItems') ?? $invoice;
}

it('prints the Belegnummer, the parties and the dates', function (): void {
    $text = renderedText(issuedInvoice());

    expect($text)->toContain('RE-2026-0042')
        ->and($text)->toContain('Musterbetrieb GmbH')
        ->and($text)->toContain('Bauer & Kollegen GmbH')
        ->and($text)->toContain('Herr Bauer')
        ->and($text)->toContain('Kundenweg 7')
        ->and($text)->toContain('20095 Hamburg')
        // The Ausstellungsdatum, the Leistungsdatum and the Fälligkeitsdatum —
        // 25.09. plus 14 days. §14 Abs. 4 UStG wants the first two and the
        // customer wants the third.
        ->and($text)->toContain('25.09.2026')
        ->and($text)->toContain('20.09.2026')
        ->and($text)->toContain('09.10.2026');
});

it('prints German characters rather than mojibake', function (): void {
    // The umlauts are the point. WeasyPrint falls back to a locale default when
    // the input declares no charset, and every umlaut on a German invoice then
    // renders wrongly while the PDF stays valid and plausibly sized.
    $text = renderedText(issuedInvoice());

    expect($text)->toContain('Musterstraße 1')
        ->and($text)->toContain('Räume')
        ->and($text)->toContain('Übergabe')
        ->and($text)->toContain('Grüßen')
        ->and($text)->toContain('Fällig');
});

it('embeds every font it uses', function (): void {
    // Not a substitute for looking at the page: pdftotext reads the ToUnicode
    // table, which is populated whether or not the font holds the glyph, so a
    // font dropped from the image yields a valid PDF full of empty boxes and a
    // green suite. This catches only the case where nothing was embedded.
    $bytes = (new RenderInvoicePdf)(issuedInvoice());
    $path = tempnam(sys_get_temp_dir(), 'beleg').'.pdf';
    file_put_contents($path, $bytes);

    $lines = array_filter(
        array_slice(preg_split('/\r?\n/', Process::run(['pdffonts', $path])->output()) ?: [], 2),
        fn (string $line): bool => trim($line) !== ''
    );

    unlink($path);

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        // Anchored on the trailing "emb sub uni objectID gen" shape rather than
        // a byte offset: pdffonts pads its columns to fit their content, so a
        // long subset name would shift a fixed offset without failing.
        expect(preg_match('/(yes|no)\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+$/', trim($line), $matches))->toBe(1);
        expect($matches[1] ?? null)->toBe('yes');
    }
});

it('prints the VAT summary grouped by Steuersatz, highest first', function (): void {
    // The figures of §6, which CalculateTotalsTest verifies by hand. Asserting
    // them again here is what stops the page and the arithmetic drifting apart:
    // 12 × 95,00 at 19 % and 1 × 290,00 at 7 %.
    $text = renderedText(issuedInvoice());

    expect($text)->toContain('1.430,00')          // Nettobetrag
        ->and($text)->toContain('216,60')          // 19 % of 1.140,00
        ->and($text)->toContain('20,30')           // 7 % of 290,00
        ->and($text)->toContain('1.666,90');       // Gesamtbetrag

    // Highest rate first, the order the XML also prints them in.
    expect((int) mb_strpos($text, '19 %'))->toBeLessThan((int) mb_strpos($text, '7 %'));
});

it('prints the identity block the Handelsregister entry belongs to', function (): void {
    // §35a GmbHG: a GmbH states its court, its HRB number and its
    // Geschäftsführer on every business letter. This is the footer whose absence
    // CheckReadiness blocks on.
    $text = renderedText(issuedInvoice());

    expect($text)->toContain('Amtsgericht Charlottenburg')
        ->and($text)->toContain('HRB 123456')
        ->and($text)->toContain('Erika Mustermann')
        ->and($text)->toContain('29/123/45678')
        ->and($text)->toContain('DE02120300000000202051');
});

it('omits the register entry for an Einzelunternehmen and its designation from the name', function (): void {
    $text = renderedText(issuedInvoice([
        'name' => 'Erika Mustermann',
        'legal_form' => LegalForm::SoleProprietorship,
        'register_court' => null,
        'register_number' => null,
        'managing_directors' => null,
    ]));

    expect($text)->toContain('Erika Mustermann')
        ->and($text)->not->toContain('Einzelunternehmen')
        ->and($text)->not->toContain('Amtsgericht');
});

it('prints the §19 note and no USt column for a Kleinunternehmer', function (): void {
    $invoice = issuedInvoice(['vat_scheme' => VatScheme::SmallBusiness]);

    // Its Positionen carry 0 %, which is what selectableTaxRates() offers such
    // a company; the arithmetic has no branch for the scheme and the page does.
    $invoice->lineItems->each(function (LineItem $item): void {
        $item->forceFill(['tax_rate' => 0])->saveQuietly();
    });

    $block = $invoice->frozen_block?->toArray() ?? [];
    $block['seller']['vat_scheme'] = 'small_business';
    $invoice->forceFill(['frozen_block' => FrozenBlock::fromArray($block)])->saveQuietly();

    $text = renderedText($invoice->fresh()?->load('lineItems') ?? $invoice);

    expect($text)->toContain('§ 19 Abs. 1 UStG')
        ->and($text)->not->toContain('zzgl.');
});

it('refuses to render a draft', function (): void {
    // A draft has no number and no Festschreibung, so the page would carry no
    // seller and no number — a valid PDF that is not a Rechnung.
    expect(fn (): string => (new RenderInvoicePdf)(Invoice::factory()->create()))
        ->toThrow(LogicException::class);
});
