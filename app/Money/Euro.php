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
}
