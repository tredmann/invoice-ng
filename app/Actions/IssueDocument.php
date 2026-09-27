<?php

declare(strict_types=1);

namespace App\Actions;

use App\Documents\FrozenBlock;
use App\Enums\AuditEvent;
use App\Enums\DocumentStatus;
use App\Exceptions\CompanyNotReady;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Money\Euro;
use App\Pdf\AttachZugferdXml;
use App\Pdf\RenderInvoicePdf;
use App\Zugferd\BuildZugferdXml;
use App\Zugferd\ValidateZugferdXml;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * **ausstellen**: turns an Entwurf into a gültiger Beleg (system design §8.1).
 *
 * One transaction, all or nothing. It draws the Belegnummer, freezes the
 * identities, stores the totals, renders the ZUGFeRD PDF and makes every one of
 * those unveränderlich. Any failure rolls the whole thing back: no number
 * consumed, the document still a draft, and nothing on the disk that a later
 * Beleg could collide with.
 *
 * It is a heavy operation and it serializes, because the Nummernkreis is locked
 * for the whole of it — the PDF render included. That is accepted deliberately
 * (§12 risk 1, tech stack §7.4): gapless numbering is what German bookkeeping
 * requires, and a number drawn outside the transaction that produced the
 * document is a hole in the books the first time anything fails.
 *
 * It is also synchronous, and has to be: a transaction cannot span a queued job.
 */
final class IssueDocument
{
    public function __invoke(Document $document, ?User $actor = null): Document
    {
        $document->loadMissing(['company', 'customer', 'lineItems']);

        $this->assertIssuable($document);

        $company = $document->company;
        $customer = $document->customer;

        // Both are required columns with restricting foreign keys, so neither
        // can be null in the database. The assertions are for the type system,
        // and they are cheap.
        assert($company instanceof Company);
        assert($customer instanceof Customer);

        // Before the transaction opens, so the missing prerequisites can be
        // reported as a list rather than raised halfway through issuing. Only
        // blockers refuse — a missing logo never stops an invoice.
        $readiness = (new CheckReadiness)($company);

        throw_unless($readiness->canIssue(), CompanyNotReady::class, $readiness);

        return DB::transaction(function () use ($document, $company, $customer, $actor): Document {
            // 1. The number, under the lock that keeps the Nummernkreis gapless.
            //    First, so that everything after it is inside the lock and a
            //    failure gives the number back.
            $number = (new DrawNextNumber)($company);

            // 2. The Festschreibung, from the live models, for the last time.
            $block = FrozenBlock::of($company, $customer);

            // 3. and 4. The figures and the Fälligkeitsdatum.
            $totals = $document->totals();
            $dueOn = $document->payment_term->dueDateFrom($document->issued_on);

            // 5. Everything but the status, in memory. The renderer and the XML
            //    builder both read the document, so it has to be complete
            //    before either runs — and it must not be saved yet, because the
            //    save is what has to happen last.
            $document->forceFill([
                'number' => $number,
                'due_on' => $dueOn,
                'net_total' => $totals->net,
                'tax_total' => $totals->tax,
                'gross_total' => $totals->gross,
                'frozen_block' => $block,
            ]);

            // 6. The file. Render, describe, validate, merge — the XML is
            //    validated before the merge, so a document that would not pass
            //    the recipient's software never reaches the disk (§7).
            $builder = (new BuildZugferdXml)($document);
            (new ValidateZugferdXml)($builder->getContent());

            $pdf = (new AttachZugferdXml)((new RenderInvoicePdf)($document), $builder);

            $path = $this->pathFor($document, $number);
            $hash = hash('sha256', $pdf);

            // 7. Written **before** the commit (tech stack §7.2). An orphaned
            //    object after a rollback is litter, collectable by comparing
            //    keys against the table; a commit whose write then failed is a
            //    Beleg in the books with no document behind it.
            Storage::disk(config()->string('invoice.documents_disk'))->put($path, $pdf);

            // 8. One save, flipping the status together with everything else.
            //    This is permitted because Document's guard reads the *stored*
            //    status, which is still `draft` — after this save nothing about
            //    the Beleg may change but its status.
            $document->forceFill([
                'pdf_path' => $path,
                'pdf_sha256' => $hash,
                'status' => DocumentStatus::Issued,
            ])->save();

            // 9. The Verlauf entry, written by the transaction that did the
            //    work, so it cannot record something that rolled back.
            $document->auditEntries()->create([
                'event' => AuditEvent::Issued,
                'occurred_at' => now(),
                'actor_id' => $actor?->getKey(),
                'details' => [
                    'number' => $number,
                    'gross' => Euro::format($totals->gross),
                    'pdf_path' => $path,
                    'pdf_sha256' => $hash,
                ],
            ]);

            return $document;
        });
    }

    private function assertIssuable(Document $document): void
    {
        throw_unless(
            $document->status->isDraft(),
            DomainException::class,
            'Only an Entwurf can be issued; this Beleg already carries a Belegnummer.',
        );

        // §14 UStG wants the Umfang der Leistung on the invoice, and a Rechnung
        // with no Position has nothing to bill. The form refuses this too; the
        // check is here because the form is one writer and a later import or
        // job is another.
        throw_if(
            $document->lineItems->isEmpty(),
            DomainException::class,
            'A Beleg without Positionen has nothing to bill.',
        );
    }

    /**
     * `companies/{company}/documents/{year}/{number}.pdf` (tech stack §7.1).
     *
     * The year comes from the draw rather than from `issued_on`, so the folder
     * agrees with the number: the Nummernkreis counts in real time, so a draft
     * dated last December and issued in January carries this year's number.
     */
    private function pathFor(Document $document, string $number): string
    {
        return sprintf(
            'companies/%s/documents/%d/%s.pdf',
            $document->company_id,
            now()->year,
            $number,
        );
    }
}
