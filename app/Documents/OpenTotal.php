<?php

declare(strict_types=1);

namespace App\Documents;

use Brick\Money\Money;

/**
 * An offener Betrag and the number of Belege it is spread over — „11.949,50 €"
 * over „7 Rechnungen", which the tiles always print together.
 */
final readonly class OpenTotal
{
    public function __construct(
        public Money $amount,
        public int $count,
    ) {}
}
