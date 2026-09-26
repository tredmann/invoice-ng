<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LineItem;

it('stores a Leistungszeitraum when the two dates differ', function (): void {
    $invoice = Invoice::factory()->create([
        'performed_on' => '2026-09-25',
        'performed_from' => '2026-09-01',
        'performed_to' => '2026-09-25',
    ]);

    $fresh = $invoice->fresh();

    expect($fresh?->performed_on)->toBeNull()
        ->and($fresh?->performed_from?->toDateString())->toBe('2026-09-01')
        ->and($fresh?->performed_to?->toDateString())->toBe('2026-09-25');
});

it('stores a Leistungsdatum when only one date is given', function (): void {
    $invoice = Invoice::factory()->create([
        'performed_on' => null,
        'performed_from' => '2026-09-25',
        'performed_to' => null,
    ]);

    $fresh = $invoice->fresh();

    expect($fresh?->performed_on?->toDateString())->toBe('2026-09-25')
        ->and($fresh?->performed_from)->toBeNull()
        ->and($fresh?->performed_to)->toBeNull();
});

it('reads a one-day period as a Leistungsdatum', function (): void {
    // ZUGFeRD has no one-day BG-14; a period whose ends coincide is a BT-72.
    $invoice = Invoice::factory()->create([
        'performed_on' => null,
        'performed_from' => '2026-09-25',
        'performed_to' => '2026-09-25',
    ]);

    $fresh = $invoice->fresh();

    expect($fresh?->performed_on?->toDateString())->toBe('2026-09-25')
        ->and($fresh?->performed_from)->toBeNull();
});

it('never leaves a document carrying both forms, whichever way round', function (): void {
    // Both directions, because a normalisation that only ever ran one way
    // would pass either test above on its own.
    $invoice = Invoice::factory()->create(['performed_on' => '2026-09-25']);

    $invoice->update(['performed_from' => '2026-09-01', 'performed_to' => '2026-09-30']);
    expect($invoice->fresh()?->performed_on)->toBeNull();

    $invoice->update(['performed_on' => '2026-10-02', 'performed_from' => null, 'performed_to' => null]);
    expect($invoice->fresh()?->performed_from)->toBeNull()
        ->and($invoice->fresh()?->performed_on?->toDateString())->toBe('2026-10-02');
});

it('refuses to change anything but the status of an issued document', function (): void {
    // §4: after issue the document and its Positionen are immutable, and only
    // the status, payments and audit entries may still change. Nothing can
    // issue yet — the factory state is how the guard can be watched refusing.
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->issued()->create();
    $other = Customer::factory()->for($company)->create();

    expect(fn (): bool => $invoice->update(['customer_id' => $other->getKey()]))
        ->toThrow(DomainException::class);

    expect($invoice->fresh()?->customer_id)->not->toBe($other->getKey());
});

it('still lets an issued document change status', function (): void {
    // The other half. A guard that refused every update would also forbid
    // issuing, sending and being paid — §4 permits all three.
    $invoice = Invoice::factory()->issued()->create();

    // forceFill, because status is state rather than input: it is not
    // fillable, and the transitions will be performed by IssueDocument and
    // SendDocument, not by a form.
    $invoice->forceFill(['status' => DocumentStatus::Sent])->save();

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Sent);
});

it('lets a draft be changed freely', function (): void {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->create();
    $other = Customer::factory()->for($company)->create();

    $invoice->update(['customer_id' => $other->getKey()]);

    expect($invoice->fresh()?->customer_id)->toBe($other->getKey());
});

it('lets a draft be issued, which is a status change from draft', function (): void {
    // The guard reads the *stored* status, not the one being written. Reading
    // the new value would make issuing impossible.
    $invoice = Invoice::factory()->create();

    $invoice->forceFill(['status' => DocumentStatus::Issued, 'number' => 1])->save();

    expect($invoice->fresh()?->status)->toBe(DocumentStatus::Issued);
});

it('refuses to add, change or remove a Position of an issued document', function (): void {
    $invoice = Invoice::factory()->issued()->create();

    expect(fn (): LineItem => LineItem::factory()->for($invoice, 'document')->create())
        ->toThrow(DomainException::class);

    $draft = Invoice::factory()->create();
    $line = LineItem::factory()->for($draft, 'document')->create();
    $draft->forceFill(['status' => DocumentStatus::Issued, 'number' => 2])->save();

    expect(fn (): bool => $line->update(['title' => 'Umbenannt']))->toThrow(DomainException::class)
        ->and(fn (): ?bool => $line->delete())->toThrow(DomainException::class);
});

it('refuses to delete an issued document', function (): void {
    $invoice = Invoice::factory()->issued()->create();

    expect(fn (): ?bool => $invoice->delete())->toThrow(DomainException::class)
        ->and(Invoice::query()->count())->toBe(1);
});

it('lets an edit move a Leistungsdatum that was already stored', function (): void {
    // The form sends performed_from and the stored date is filled back into
    // it. If performed_on won, changing the date would keep the old one — and
    // every test above would still pass, because none of them edits a date
    // that was already set.
    $invoice = Invoice::factory()->create(['performed_on' => '2026-09-25']);

    $invoice->update(['performed_from' => '2026-10-01', 'performed_to' => null]);

    expect($invoice->fresh()?->performed_on?->toDateString())->toBe('2026-10-01');
});
