<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Blade → HTML → WeasyPrint → PDF bytes. The first half of tech stack §7.1,
 * kept separate from the horstoeko assembly step so each is testable alone.
 *
 * It renders from the **in-memory** document the issue transaction has filled
 * but not yet saved, which is why it returns bytes rather than writing a file:
 * the file is written once, at the end, after the XML has been validated and
 * attached. A render that produced a file here would leave one behind on every
 * rollback.
 */
final class RenderInvoicePdf
{
    public function __invoke(Document $document): string
    {
        // Not defensive: the template reads the Festschreibung for both parties
        // and the number for its heading. Called on a draft it would render an
        // invoice with no seller and no number on it — a valid PDF that is not
        // a Rechnung, and exactly the kind of output a smoke test on file size
        // waves through.
        throw_if(
            $document->frozen_block === null || $document->number === null || $document->due_on === null,
            LogicException::class,
            'A Beleg can only be rendered once it carries its Belegnummer, its Festschreibung and its Fälligkeitsdatum.',
        );

        $pdf = Pdf::view('documents.invoice', [
            'document' => $document,
            'totals' => $document->totals(),
            'logo' => $this->logo($document),
        ])->format('a4');

        // base64() rather than save(): the bytes never need to touch the
        // container disk, and a temp file would be one more thing to clean up
        // when the transaction rolls back.
        $bytes = base64_decode($pdf->base64(), true);

        throw_if($bytes === false || $bytes === '', RuntimeException::class, 'WeasyPrint returned no PDF.');

        return $bytes;
    }

    /**
     * The company logo as a data: URI, or null.
     *
     * Embedded rather than linked because the renderer runs with no HTTP
     * context and the logos disk may be object storage — a file:// path would
     * work only for the local disk, and only by accident. Reading it through
     * the Storage facade keeps `config('invoice.logos_disk')` the single seam
     * that names a disk.
     *
     * A missing file is not an error. CheckReadiness only *warns* about a logo
     * — a Rechnung without one is valid and plain — so a logo deleted from the
     * disk behind the path must not stop an invoice.
     */
    private function logo(Document $document): ?string
    {
        $path = $document->company?->logo_path;

        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(config()->string('invoice.logos_disk'));

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        if ($contents === null || $contents === '') {
            return null;
        }

        return 'data:'.($disk->mimeType($path) ?: 'image/png').';base64,'.base64_encode($contents);
    }
}
