<?php

declare(strict_types=1);

namespace App\Company;

use Brick\Money\Money;
use Illuminate\Support\Carbon;

/**
 * One month of the **Umsatzverlauf**: a bar of the chart.
 *
 * The Betrag is **netto**. Umsatzsteuer is money collected for the state and
 * passed on, not Umsatz — CONTEXT.md puts the Bruttobetrag as „was der Kunde
 * zahlt", which is a receivable, not revenue.
 */
final readonly class MonthlyRevenue
{
    public function __construct(
        /** The first day of the month, so the view can name it. */
        public Carbon $month,
        public Money $net,
        /** The month the figures were taken in — drawn in the accent colour. */
        public bool $isCurrent,
    ) {}
}
