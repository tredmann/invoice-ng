<?php

declare(strict_types=1);

use App\Actions\CalculateTotals;
use App\Money\LineInput;
use App\Money\Totals;
use Brick\Money\Money;

/**
 * One Position, as the calculator sees it. Named for the domain rather than
 * "line" so it cannot collide with another test file's global helper.
 */
function positionOf(string $quantity, string $unitPrice, int $rate): LineInput
{
    return LineInput::of($quantity, Money::of($unitPrice, 'EUR'), $rate);
}

/**
 * @param  list<LineInput>  $lines
 */
function totalsOf(array $lines): Totals
{
    return (new CalculateTotals)($lines);
}

it('agrees to the cent with the invoice drawn in the mockup', function (): void {
    // The `Rechnung – Neu (Entwurf)` board: 12 h and 6 h at 95,00 € with 19 %,
    // one lump sum of 290,00 € at 7 %. Its printed VAT summary is the expected
    // value here, so the arithmetic and the drawn design cannot drift apart
    // silently — a change to either shows up as a failure in this test.
    $totals = totalsOf([
        positionOf('12', '95.00', 1900),
        positionOf('6', '95.00', 1900),
        positionOf('1', '290.00', 700),
    ]);

    expect((string) $totals->net->getAmount())->toBe('2000.00')
        ->and((string) $totals->tax->getAmount())->toBe('345.20')
        ->and((string) $totals->gross->getAmount())->toBe('2345.20');

    // Highest rate first, as the mockup prints it: USt 19 % above USt 7 %.
    expect($totals->groups)->toHaveCount(2)
        ->and($totals->groups[0]->rate)->toBe(1900)
        ->and((string) $totals->groups[0]->base->getAmount())->toBe('1710.00')
        ->and((string) $totals->groups[0]->tax->getAmount())->toBe('324.90')
        ->and($totals->groups[1]->rate)->toBe(700)
        ->and((string) $totals->groups[1]->base->getAmount())->toBe('290.00')
        ->and((string) $totals->groups[1]->tax->getAmount())->toBe('20.30');
});

it('rounds the Umsatzsteuer once per Steuersatz, not once per Position', function (): void {
    // Three Positionen of 0,83 € at 19 %.
    //   per Position: 0,83 × 0,19 = 0,1577 → 0,16 each → 0,48
    //   per group:    2,49 × 0,19 = 0,4731 → 0,47
    // §6 requires the second. This is the only case in the table that can tell
    // the two apart: every other invoice gives the same answer either way, so a
    // suite without it stays green against the rule applied in the wrong place.
    $totals = totalsOf([
        positionOf('1', '0.83', 1900),
        positionOf('1', '0.83', 1900),
        positionOf('1', '0.83', 1900),
    ]);

    expect((string) $totals->net->getAmount())->toBe('2.49')
        ->and((string) $totals->tax->getAmount())->toBe('0.47')
        ->and((string) $totals->gross->getAmount())->toBe('2.96');
});

it('rounds a fractional quantity to the cent before it groups', function (): void {
    // 2,5 × 19,99 = 49,975 → 49,98 (half up), then 49,98 × 0,19 = 9,4962 → 9,50.
    // Step 1 of §6 is the rounding this asserts; without it the base would be
    // 49,975 and the tax 9,4953 → 9,50, which happens to agree, so the net is
    // the figure that gives it away.
    $totals = totalsOf([positionOf('2.5', '19.99', 1900)]);

    expect((string) $totals->net->getAmount())->toBe('49.98')
        ->and((string) $totals->tax->getAmount())->toBe('9.50')
        ->and((string) $totals->gross->getAmount())->toBe('59.48');
});

it('leaves a Kleinunternehmer invoice at zero tax with the group still present', function (): void {
    // The group survives at 0 %: the PDF and the XML show a VAT summary, and a
    // summary with no row would be a different document from one reading 0,00 €.
    $totals = totalsOf([positionOf('10', '50.00', 0)]);

    expect((string) $totals->net->getAmount())->toBe('500.00')
        ->and((string) $totals->tax->getAmount())->toBe('0.00')
        ->and((string) $totals->gross->getAmount())->toBe('500.00')
        ->and($totals->groups)->toHaveCount(1)
        ->and($totals->groups[0]->rate)->toBe(0)
        ->and((string) $totals->groups[0]->base->getAmount())->toBe('500.00');
});

it('totals nothing to zero euro rather than to no answer', function (): void {
    $totals = totalsOf([]);

    expect((string) $totals->net->getAmount())->toBe('0.00')
        ->and((string) $totals->tax->getAmount())->toBe('0.00')
        ->and((string) $totals->gross->getAmount())->toBe('0.00')
        ->and($totals->groups)->toBe([]);
});

it('refuses a floating point quantity', function (): void {
    // Not decoration: brick/math takes BigNumber|int|string and never float, so
    // the guarantee is the library's. This test is what notices if a later
    // convenience overload hands it one anyway.
    expect(fn (): LineInput => LineInput::of(2.5, Money::of('19.99', 'EUR'), 1900)) // @phpstan-ignore argument.type
        ->toThrow(TypeError::class);
});
