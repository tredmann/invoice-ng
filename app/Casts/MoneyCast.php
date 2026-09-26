<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A `bigint` of cents on the way down, a `Money` in EUR on the way up.
 *
 * Nothing uses it yet — no Beleg exists — and it is written now so the column
 * type is settled before anything depends on it, and so the invoicing wave
 * inherits it rather than writing it in a hurry.
 *
 * `set()` takes a `Money` and nothing else. A float is the mistake the cast
 * exists to prevent; an int is refused too, because `1999` reads equally well as
 * nineteen euros and as nineteen ninety-nine, and a cast that guesses between
 * them is worse than one that insists.
 *
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_string($value)) {
            return Money::ofMinor($value, 'EUR');
        }

        throw new InvalidArgumentException(
            sprintf('The column [%s] should hold a bigint of cents, got [%s].', $key, get_debug_type($value)),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('The attribute [%s] takes a Money, got [%s].', $key, get_debug_type($value)),
            );
        }

        return $value->getMinorAmount()->toInt();
    }
}
