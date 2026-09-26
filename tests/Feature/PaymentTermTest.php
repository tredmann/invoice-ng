<?php

declare(strict_types=1);

use App\Enums\PaymentTerm;
use Carbon\CarbonImmutable;

it('carries the duration each term is named for', function (PaymentTerm $term, int $days): void {
    expect($term->days())->toBe($days);
})->with([
    [PaymentTerm::Immediate, 0],
    [PaymentTerm::Net7, 7],
    [PaymentTerm::Net14, 14],
    [PaymentTerm::Net30, 30],
    [PaymentTerm::Net60, 60],
]);

it('computes the Fälligkeitsdatum the mockup prints', function (): void {
    // The `Rechnung – Neu` board shows a Rechnungsdatum of 25.09.2026 with
    // "14 Tage netto" and "Fällig am 09.10.2026". Tying the test to the drawn
    // design is what stops the arithmetic and the mockup drifting apart, and
    // September's 30 days mean an off-by-one lands on the 8th or the 10th.
    $due = PaymentTerm::Net14->dueDateFrom(CarbonImmutable::parse('2026-09-25'));

    expect($due->toDateString())->toBe('2026-10-09');
});

it('makes an immediate term due on the day it was issued', function (): void {
    $due = PaymentTerm::Immediate->dueDateFrom(CarbonImmutable::parse('2026-09-25'));

    expect($due->toDateString())->toBe('2026-09-25');
});

it('keeps the time of day out of the Fälligkeitsdatum', function (): void {
    // A Fälligkeitsdatum is a day (CONTEXT.md), so an invoice issued at 17:40
    // is not due at 17:40 a fortnight later. Without startOfDay() this passes
    // its date assertion and still carries a time nobody asked for.
    $due = PaymentTerm::Net7->dueDateFrom(CarbonImmutable::parse('2026-09-25 17:40:31'));

    expect($due->toDateTimeString())->toBe('2026-10-02 00:00:00');
});

it('labels every term in German', function (): void {
    // __() returns the key itself when the translation is missing, so asserting
    // the label differs from the key is what catches an absent lang entry.
    foreach (PaymentTerm::cases() as $term) {
        expect($term->getLabel())
            ->not->toContain('company.payment_term')
            ->and($term->getLabel())->not->toBe('');
    }

    expect(PaymentTerm::Net14->getLabel())->toBe('14 Tage netto');
});
