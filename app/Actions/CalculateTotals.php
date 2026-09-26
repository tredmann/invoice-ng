<?php

declare(strict_types=1);

namespace App\Actions;

use App\Money\LineInput;
use App\Money\TaxGroup;
use App\Money\Totals;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * The rounding of system design §6, in the order §6 states it.
 *
 * A unit of its own rather than a method on a future Document, because the rule
 * is the one piece of arithmetic in this system that is both easy to get subtly
 * wrong and impossible to notice being wrong: rounding per Position instead of
 * per Steuersatz group differs by a cent only on some invoices, and agrees on
 * the rest. Kept here it is table-testable against values derived by hand from
 * EN16931.
 *
 * The Kleinunternehmer is deliberately not a branch in here. A §19 company's
 * Positionen simply carry 0 %; `Company::selectableTaxRates()` is the single
 * place that decides which rates a company may use. Were the scheme consulted
 * here, the same input would have two answers depending on who asked.
 */
final class CalculateTotals
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __invoke(array $lines): Totals
    {
        /** @var array<int, Money> $bases Bemessungsgrundlage per Steuersatz. */
        $bases = [];

        foreach ($lines as $line) {
            // §6 step 1: quantity × Einzelpreis, rounded to the cent.
            $lineNet = $line->unitPrice->multipliedBy($line->quantity, RoundingMode::HalfUp);

            // §6 step 2: sum into the group for this Steuersatz.
            $bases[$line->rate] = isset($bases[$line->rate])
                ? $bases[$line->rate]->plus($lineNet)
                : $lineNet;
        }

        // Highest Steuersatz first, the order the PDF and the XML print.
        krsort($bases);

        $net = Money::zero('EUR');
        $tax = Money::zero('EUR');
        $groups = [];

        foreach ($bases as $rate => $base) {
            // §6 step 3: the group's Umsatzsteuer, rounded ONCE over the base.
            $groupTax = $base->multipliedBy($this->factorFor($rate), RoundingMode::HalfUp);

            $groups[] = new TaxGroup($rate, $base, $groupTax);

            // §6 step 4: totals are the sums of the group figures.
            $net = $net->plus($base);
            $tax = $tax->plus($groupTax);
        }

        return new Totals($net, $groups, $tax, $net->plus($tax));
    }

    /**
     * Basis points as an exact decimal: 1900 becomes 0.1900. Scale 4 is exact
     * for every basis-point value, so nothing is rounded on the way in and the
     * only rounding in §6 step 3 is the one §6 asks for.
     */
    private function factorFor(int $rate): BigDecimal
    {
        return BigDecimal::of($rate)->dividedBy(10_000, 4);
    }
}
