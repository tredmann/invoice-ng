<?php

declare(strict_types=1);

namespace App\Pdf;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use RuntimeException;

/**
 * The second half of tech stack §7.1: an ordinary PDF and the EN16931 XML go
 * in, a PDF/A-3 with the XML embedded comes out.
 *
 * Nothing about the compliance is written here. The library converts to PDF/A-3
 * with its own sRGB output intent, writes the Factur-X XMP extension schema and
 * attaches the XML with `AFRelationship=Data` under the filename the standard
 * fixes. Re-implementing any of that would be writing a worse version of
 * something already audited against the specification.
 *
 * Kept apart from RenderInvoicePdf so each step is testable alone (§12): a
 * failure here means the merge, a failure there means WeasyPrint, and a single
 * class doing both would make the two indistinguishable in a stack trace.
 */
final class AttachZugferdXml
{
    public function __invoke(string $pdf, ZugferdDocumentBuilder $builder): string
    {
        $merged = new ZugferdDocumentPdfBuilder($builder, $pdf)
            ->generateDocument()
            ->downloadString();

        // The builder returns a string either way, so an empty one is the only
        // shape a silent failure could take — and an empty file written to the
        // documents disk would be a Beleg nobody can read, discovered years
        // later.
        throw_if($merged === '', RuntimeException::class, 'The ZUGFeRD merge produced an empty PDF.');

        return $merged;
    }
}
