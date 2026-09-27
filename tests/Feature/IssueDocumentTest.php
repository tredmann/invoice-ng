<?php

declare(strict_types=1);

use App\Actions\IssueDocument;
use App\Company\ReadinessItem;
use App\Enums\AuditEvent;
use App\Enums\DocumentStatus;
use App\Exceptions\CompanyNotReady;
use App\Models\AuditEntry;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\NumberRange;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The Ausstellvorgang (§8.1). The hardest tests in the repo and the most
 * valuable — what they guard is a hole in the bookkeeping record, which is
 * invisible until a Betriebsprüfung finds it.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('draws the number, freezes the identities, stores the figures and writes the file', function (): void {
    $invoice = draftInvoice();
    $company = companyOf($invoice);

    $issued = (new IssueDocument)($invoice);

    expect($issued->status)->toBe(DocumentStatus::Issued)
        ->and($issued->number)->toBe('RE-'.today()->year.'-0001')
        // 25.09. plus the 14-day Zahlungsziel.
        ->and($issued->due_on?->format('Y-m-d'))->toBe('2026-10-09')
        ->and((string) $issued->net_total?->getAmount())->toBe('1430.00')
        ->and((string) $issued->tax_total?->getAmount())->toBe('236.90')
        ->and((string) $issued->gross_total?->getAmount())->toBe('1666.90')
        ->and($issued->frozen_block?->seller->name)->toBe('Musterbetrieb')
        ->and($issued->frozen_block?->buyer->name)->toBe('Bauer & Kollegen GmbH')
        ->and($issued->pdf_path)->toBe(sprintf(
            'companies/%s/documents/%d/RE-%d-0001.pdf',
            $company->getKey(),
            today()->year,
            today()->year,
        ));

    Storage::disk('local')->assertExists(pdfPathOf($issued));

    // The Nummernkreis moved on by exactly one.
    expect((int) $company->numberRange()->first()?->next_value)->toBe(2);
});

it('writes one Verlauf entry naming what it did', function (): void {
    $actor = User::factory()->create();
    $issued = (new IssueDocument)(draftInvoice(), $actor);

    $entry = $issued->auditEntries()->first();

    expect($entry?->event)->toBe(AuditEvent::Issued)
        ->and($entry?->actor_id)->toBe($actor->getKey())
        ->and($entry?->detail('number'))->toBe($issued->number)
        ->and($entry?->detail('pdf_sha256'))->toBe($issued->pdf_sha256)
        ->and($entry?->detail('gross'))->toContain('1.666,90');
});

it('stores a hash of the bytes that are actually on the disk', function (): void {
    // §7.3: the proof that a file served in 2029 is byte-identical to the one
    // the customer received. A hash of anything but the stored bytes would
    // never notice the corruption it exists to detect.
    $issued = (new IssueDocument)(draftInvoice());

    expect($issued->pdf_sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get(pdfPathOf($issued))));
});

it('draws the Belegnummer the Nummernkreis had promised', function (): void {
    $company = issuableCompany();
    $company->configureNumberRange([
        'prefix' => 'ACME-',
        'padding' => 6,
        'next_value' => 815,
        'include_year' => false,
        'reset_yearly' => false,
    ]);

    $promised = $company->fresh()?->numberRange()->first()?->nextNumber();
    $issued = (new IssueDocument)(draftInvoice($company->fresh()));

    expect($issued->number)->toBe('ACME-000815')
        ->and($issued->number)->toBe($promised);
});

it('refuses a company that is not ready, and names what is missing', function (string $missing, array $overrides): void {
    // Before the transaction opens, so nothing is locked and nothing rolls back
    // (§8.1). The blockers are what §14 UStG and §35a GmbHG require.
    $company = issuableCompany($overrides);
    $invoice = draftInvoice($company);

    $blockers = [];

    try {
        (new IssueDocument)($invoice);
    } catch (CompanyNotReady $exception) {
        $blockers = array_map(fn (ReadinessItem $item): string => $item->key, $exception->readiness->blockers());
    }

    // Empty when nothing threw, which fails here rather than passing silently.
    expect($blockers)->toContain($missing);

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Draft)
        ->and($invoice->fresh()?->number)->toBeNull()
        ->and((int) $company->numberRange()->first()?->next_value)->toBe(1);
})->with([
    'no address' => ['address', ['street' => '', 'postal_code' => '', 'city' => '']],
    'no tax identifier' => ['tax_identifier', ['tax_number' => null, 'vat_id' => null]],
    'no register entry' => ['register', ['register_court' => null, 'register_number' => null, 'managing_directors' => null]],
]);

it('refuses a company with no Nummernkreis before it locks anything', function (): void {
    // The range row is absent until the settings tab is saved once, which is
    // what lets the Bereitschaftsprüfung report it missing. Without this check
    // DrawNextNumber would raise ModelNotFoundException from inside the
    // transaction — a stack trace instead of an answer.
    $company = issuableCompany();
    $company->numberRange()->delete();

    expect(fn (): Document => (new IssueDocument)(draftInvoice($company->fresh())))
        ->toThrow(CompanyNotReady::class);
});

it('issues despite a missing logo or missing bank details', function (): void {
    // Warnings, not blockers. A Rechnung without an IBAN is valid and awkward
    // to pay; one without a logo is valid and plain. If either ever refuses,
    // this fails.
    $company = issuableCompany(['bank_name' => null, 'iban' => null, 'bic' => null, 'logo_path' => null]);

    $issued = (new IssueDocument)(draftInvoice($company));

    expect($issued->status)->toBe(DocumentStatus::Issued);
});

it('consumes no number and writes no file when the render fails', function (): void {
    // The most valuable test here. A real failure of the real renderer — the
    // binary is pointed at something that does not exist — rather than a test
    // double, so it exercises the path a timeout or a crash would take.
    //
    // Delete the DB::transaction wrapper in IssueDocument and this goes red
    // while everything else stays green.
    config()->set('laravel-pdf.weasyprint.binary', '/nonexistent/weasyprint');

    $company = issuableCompany();
    $invoice = draftInvoice($company);

    // Which exception WeasyPrint's absence produces is the driver's business.
    // What this test is about is the state left behind.
    expect(fn (): Document => (new IssueDocument)($invoice))->toThrow(Exception::class);

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(DocumentStatus::Draft)
        ->and($fresh?->number)->toBeNull()
        ->and($fresh?->frozen_block)->toBeNull()
        ->and($fresh?->gross_total)->toBeNull()
        ->and($fresh?->pdf_path)->toBeNull()
        // The number was drawn inside the transaction, so the rollback gives
        // it back rather than leaving a hole.
        ->and((int) $company->numberRange()->first()?->next_value)->toBe(1)
        ->and((int) $company->numberRange()->first()?->drawn_count)->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

/*
 * There is deliberately no separate test that a failing XSD validation rolls
 * the Belegnummer back.
 *
 * The rollback is one transaction, and the render test above already breaks it
 * at a point *after* the number is drawn — validation sits between the two, so
 * a failure there takes exactly the same path. Producing schema-invalid XML
 * through the real pipeline would mean feeding the Nummernkreis a configuration
 * it cannot hold, and the test would then be asserting something about the test
 * rather than about issuing. That the validator refuses bad XML at all is
 * ZugferdXmlTest's job, where it is fed input this code path cannot produce.
 */

it('refuses to issue the same Beleg twice', function (): void {
    $invoice = draftInvoice();
    $issued = (new IssueDocument)($invoice);

    expect(fn (): Document => (new IssueDocument)($issued))
        ->toThrow(DomainException::class);

    expect((int) companyOf($issued)->numberRange()->first()?->next_value)->toBe(2);
});

it('refuses a Beleg with no Positionen', function (): void {
    $invoice = Invoice::factory()->for(issuableCompany())->create();

    expect(fn (): Document => (new IssueDocument)($invoice))
        ->toThrow(DomainException::class, 'nothing to bill');
});

it('keeps the Festschreibung when the company and the customer later change', function (): void {
    // The test that makes freezing mean anything. Without it the block could be
    // a relation in disguise and every other assertion here would still pass.
    $invoice = draftInvoice();
    $issued = (new IssueDocument)($invoice);

    $originalBytes = Storage::disk('local')->get(pdfPathOf($issued));

    companyOf($issued)->forceFill([
        'name' => 'Ganz Anders',
        'street' => 'Woanders 9',
        'city' => 'München',
        'iban' => 'DE89370400440532013000',
    ])->save();

    customerOf($issued)->forceFill([
        'name' => 'Umbenannt GmbH',
        'street' => 'Neuer Weg 1',
        'city' => 'Köln',
    ])->save();

    $reread = $issued->fresh();

    expect($reread?->frozen_block?->seller->name)->toBe('Musterbetrieb')
        ->and($reread?->frozen_block?->seller->city)->toBe('Berlin')
        ->and($reread?->frozen_block?->seller->iban)->toBe('DE02120300000000202051')
        ->and($reread?->frozen_block?->buyer->name)->toBe('Bauer & Kollegen GmbH')
        ->and($reread?->frozen_block?->buyer->city)->toBe('Hamburg');

    // And the file is untouched: it is served from storage forever after and
    // never regenerated (§4).
    expect(Storage::disk('local')->get(pdfPathOf($reread ?? $issued)))->toBe($originalBytes);
});

it('leaves an issued Beleg unveränderlich but for its status', function (): void {
    $issued = (new IssueDocument)(draftInvoice());

    expect(fn (): bool => $issued->forceFill(['number' => 'RE-2026-9999'])->save())
        ->toThrow(DomainException::class, 'unveränderlich');

    expect(fn (): bool => (bool) $issued->fresh()?->forceFill(['status' => DocumentStatus::Sent])->save())
        ->not->toThrow(DomainException::class);
});

it('gives consecutive numbers to two Belege of one company', function (): void {
    $company = issuableCompany();

    $first = (new IssueDocument)(draftInvoice($company));
    $second = (new IssueDocument)(draftInvoice($company));

    expect($first->number)->toBe('RE-'.today()->year.'-0001')
        ->and($second->number)->toBe('RE-'.today()->year.'-0002');
});

it('keeps each company on its own Nummernkreis', function (): void {
    $one = issuableCompany();
    $other = issuableCompany(['name' => 'Andere Firma']);

    $first = (new IssueDocument)(draftInvoice($one));
    $second = (new IssueDocument)(draftInvoice($other));

    // Not 0002: nothing is shared between companies.
    expect($first->number)->toBe($second->number)
        ->and(NumberRange::query()->count())->toBe(2);
});
