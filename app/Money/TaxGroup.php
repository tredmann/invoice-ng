<?php

declare(strict_types=1);

namespace App\Money;

use Brick\Money\Money;

/**
 * One row of the VAT summary: every Position carrying this Steuersatz, summed
 * to a Bemessungsgrundlage, with the Umsatzsteuer rounded once over the group.
 */
final readonly class TaxGroup
{
    public function __construct(
        /** The Steuersatz in basis points: 1900 is 19 %. */
        public int $rate,
        /** The Bemessungsgrundlage: the sum of this group's Nettobeträge. */
        public Money $base,
        public Money $tax,
    ) {}
}
