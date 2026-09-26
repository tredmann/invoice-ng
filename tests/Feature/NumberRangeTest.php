<?php

declare(strict_types=1);

use App\Actions\DrawNextNumber;
use App\Models\Company;
use App\Models\NumberRange;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @param  array<string, mixed>  $attributes
 */
function rangeFor(Company $company, array $attributes = []): NumberRange
{
    return NumberRange::factory()->for($company)->create($attributes);
}

function drawFor(Company $company): string
{
    // Wrapped even though RefreshDatabase already holds a transaction, because
    // this is how the issue operation will call it and the test should read
    // like the caller it stands in for.
    return DB::transaction(fn (): string => (new DrawNextNumber)($company));
}

it('formats the number the settings page previews', function (): void {
    /** @var TestCase $this */
    $this->travelTo('2026-05-04');
    $company = Company::factory()->create();
    rangeFor($company, ['prefix' => 'RE-', 'padding' => 4, 'next_value' => 43, 'include_year' => true]);

    expect(drawFor($company))->toBe('RE-2026-0043');
});

it('leaves the year out when the company does not want it', function (): void {
    /** @var TestCase $this */
    $this->travelTo('2026-05-04');
    $company = Company::factory()->create();
    rangeFor($company, ['prefix' => 'RE-', 'padding' => 4, 'next_value' => 43, 'include_year' => false]);

    expect(drawFor($company))->toBe('RE-0043');
});

it('pads to the width the company chose, and past it', function (): void {
    /** @var TestCase $this */
    $this->travelTo('2026-05-04');
    $company = Company::factory()->create();
    // A value wider than the padding must not be truncated: 12345 in four
    // places is five digits, not 2345, or two invoices could share a number.
    rangeFor($company, ['prefix' => null, 'padding' => 4, 'next_value' => 12345, 'include_year' => false]);

    expect(drawFor($company))->toBe('12345');
});

it('hands out consecutive numbers and counts what it has issued', function (): void {
    $company = Company::factory()->create();
    $range = rangeFor($company, ['prefix' => 'RE-', 'include_year' => false, 'next_value' => 1]);

    expect(drawFor($company))->toBe('RE-0001')
        ->and(drawFor($company))->toBe('RE-0002')
        ->and(drawFor($company))->toBe('RE-0003');

    expect($range->refresh()->drawn_count)->toBe(3)
        ->and($range->next_value)->toBe(4);
});

it('consumes no number when the transaction rolls back', function (): void {
    // §5's actual promise. A test asserting only that two draws differ would
    // stay green against an implementation that increments outside the
    // transaction — this is the one that does not.
    $company = Company::factory()->create();
    $range = rangeFor($company, ['prefix' => 'RE-', 'include_year' => false, 'next_value' => 1]);

    expect(drawFor($company))->toBe('RE-0001');

    try {
        DB::transaction(function () use ($company): void {
            (new DrawNextNumber)($company);
            throw new RuntimeException('the PDF failed');
        });
    } catch (RuntimeException) {
        // The failure the rollback exists for.
    }

    expect(drawFor($company))->toBe('RE-0002')
        ->and($range->refresh()->drawn_count)->toBe(2);
});

it('restarts at one in a new year when the company asked it to', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    rangeFor($company, [
        'prefix' => 'RE-', 'include_year' => true, 'next_value' => 57,
        'reset_yearly' => true, 'last_reset_year' => 2025,
    ]);

    $this->travelTo('2026-01-02');

    expect(drawFor($company))->toBe('RE-2026-0001')
        ->and(drawFor($company))->toBe('RE-2026-0002');
});

it('keeps counting across the year when the company did not', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    rangeFor($company, [
        'prefix' => 'RE-', 'include_year' => true, 'next_value' => 57,
        'reset_yearly' => false, 'last_reset_year' => 2025,
    ]);

    $this->travelTo('2026-01-02');

    expect(drawFor($company))->toBe('RE-2026-0057');
});

it('does not mistake a first draw for a new year', function (): void {
    /** @var TestCase $this */
    $this->travelTo('2026-05-04');
    $company = Company::factory()->create();
    // A company migrating in sets its Startwert to 43 and has never drawn, so
    // last_reset_year is null. Treating null as "an older year" would reset the
    // start value to 1 and reissue numbers its previous system already used.
    rangeFor($company, [
        'prefix' => 'RE-', 'include_year' => true, 'next_value' => 43,
        'reset_yearly' => true, 'last_reset_year' => null,
    ]);

    expect(drawFor($company))->toBe('RE-2026-0043');
});

it('previews the next number without consuming it', function (): void {
    /** @var TestCase $this */
    $this->travelTo('2026-05-04');
    $company = Company::factory()->create();
    $range = rangeFor($company, ['prefix' => 'RE-', 'padding' => 4, 'next_value' => 43, 'include_year' => true]);

    expect($range->nextNumber())->toBe('RE-2026-0043')
        ->and($range->nextNumber())->toBe('RE-2026-0043')
        ->and($range->refresh()->drawn_count)->toBe(0)
        ->and($range->next_value)->toBe(43);
});

it('previews the restart a new year will bring', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create();
    $range = rangeFor($company, [
        'prefix' => 'RE-', 'include_year' => true, 'next_value' => 57,
        'reset_yearly' => true, 'last_reset_year' => 2025,
    ]);

    $this->travelTo('2026-01-02');

    // The preview and the draw share one method, so a preview promising 0057
    // while the draw produces 0001 is not reachable.
    expect($range->nextNumber())->toBe('RE-2026-0001');
});

it('lets the Startwert move freely until something has been drawn', function (): void {
    $company = Company::factory()->create();
    $range = rangeFor($company, ['next_value' => 500]);

    $range->fill(['next_value' => 1])->save();

    expect($range->refresh()->next_value)->toBe(1);
});

it('refuses to lower the Startwert once a number has been issued', function (): void {
    // Lowering it after a draw reissues numbers that are already on a
    // customer's invoice — §14 Abs. 4 Nr. 2 UStG wants each one once.
    $company = Company::factory()->create();
    $range = rangeFor($company, ['prefix' => 'RE-', 'include_year' => false, 'next_value' => 100]);

    drawFor($company);

    expect(fn (): bool => $range->refresh()->fill(['next_value' => 50])->save())
        ->toThrow(DomainException::class);

    expect($range->refresh()->next_value)->toBe(101);
});

it('still lets the Startwert be raised after a draw', function (): void {
    // One direction alone cannot tell "guarded" from "always refused", and a
    // company migrating in has to be able to jump forward.
    $company = Company::factory()->create();
    $range = rangeFor($company, ['prefix' => 'RE-', 'include_year' => false, 'next_value' => 100]);

    drawFor($company);
    $range->refresh()->fill(['next_value' => 5000])->save();

    expect($range->refresh()->next_value)->toBe(5000);
});

it('draws each company from its own range', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    rangeFor($a, ['prefix' => 'A-', 'include_year' => false, 'next_value' => 1]);
    $rangeB = rangeFor($b, ['prefix' => 'B-', 'include_year' => false, 'next_value' => 1]);

    drawFor($b);
    drawFor($b);

    expect(drawFor($a))->toBe('A-0001')
        ->and($rangeB->refresh()->next_value)->toBe(3);
});

it('allows a company only one range', function (): void {
    $company = Company::factory()->create();
    rangeFor($company);

    expect(fn (): NumberRange => rangeFor($company))
        ->toThrow(UniqueConstraintViolationException::class);
});
