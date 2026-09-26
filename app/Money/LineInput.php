<?php

declare(strict_types=1);

namespace App\Money;

use Brick\Math\BigDecimal;
use Brick\Money\Money;

/**
 * One Position as the rounding of §6 sees it: a quantity, an Einzelpreis and a
 * Steuersatz in basis points.
 *
 * It is not a Position and does not become one. The calculator takes these so
 * that it can be tested — and reasoned about — without a Beleg, and so that the
 * recurring-invoice run of v2 can feed it the same way the edit screen does.
 */
final readonly class LineInput
{
    private function __construct(
        public BigDecimal $quantity,
        public Money $unitPrice,
        /** The Steuersatz in basis points: 1900 is 19 %. */
        public int $rate,
    ) {}

    /**
     * The quantity is deliberately typed without `float`. `brick/math` accepts
     * none either, so under `strict_types` a float quantity is a TypeError at
     * the boundary rather than a rounding error three steps later.
     */
    public static function of(BigDecimal|int|string $quantity, Money $unitPrice, int $rate): self
    {
        return new self(BigDecimal::of($quantity), $unitPrice, $rate);
    }
}
