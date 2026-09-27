<?php

declare(strict_types=1);

namespace App\Money;

use Brick\Money\Money;

/**
 * The one place a Money becomes a string for a German screen.
 *
 * Here rather than at each call site so the separator, the comma and the symbol
 * are one decision. `lang/de/customer.php` still carries a hardcoded „0,00 €"
 * from before this existed; it should come through here when those tiles gain
 * real figures.
 */
final class Euro
{
    public static function format(Money $money): string
    {
        return $money->formatToLocale('de_DE');
    }

    /**
     * A raw SQL sum of cents, as Money.
     *
     * `SUM()` over a bigint comes back from pdo_pgsql as a numeric *string*,
     * and over no rows at all as NULL — neither of which `MoneyCast` will
     * accept, since it refuses anything but a Money. So every aggregate in the
     * Kennzahlen comes through here, and „no Belege" is 0,00 € rather than an
     * error.
     */
    public static function fromMinor(int|string|null $cents): Money
    {
        return Money::ofMinor($cents ?? 0, 'EUR');
    }
}
