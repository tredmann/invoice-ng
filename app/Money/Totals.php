<?php

declare(strict_types=1);

namespace App\Money;

use Brick\Money\Money;

/**
 * What a Beleg's foot prints: the Nettobetrag, the VAT summary grouped by
 * Steuersatz, and the Bruttobetrag.
 */
final readonly class Totals
{
    /**
     * @param  list<TaxGroup>  $groups  Highest Steuersatz first, as the document prints them.
     */
    public function __construct(
        public Money $net,
        public array $groups,
        public Money $tax,
        public Money $gross,
    ) {}
}
