<?php

declare(strict_types=1);

namespace App\Casts;

use App\Documents\FrozenBlock;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * jsonb on the way down, a readonly FrozenBlock on the way up.
 *
 * Symmetrical with MoneyCast, and insistent for the same reason: `set()` takes
 * a FrozenBlock and nothing else. An array would let a caller write half a
 * Festschreibung, and the column it lands in is the one nothing may ever
 * correct afterwards — the immutability guard on Document refuses the update
 * that would fix it.
 *
 * @implements CastsAttributes<FrozenBlock, FrozenBlock>
 */
final class FrozenBlockCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FrozenBlock
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof FrozenBlock) {
            return $value;
        }

        throw_unless(
            is_string($value),
            InvalidArgumentException::class,
            sprintf('The column [%s] should hold jsonb, got [%s].', $key, get_debug_type($value)),
        );

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('The column [%s] does not hold valid JSON.', $key), $exception->getCode(), previous: $exception);
        }

        throw_unless(
            is_array($decoded),
            InvalidArgumentException::class,
            sprintf('The column [%s] should hold a JSON object.', $key),
        );

        return FrozenBlock::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        throw_unless(
            $value instanceof FrozenBlock,
            InvalidArgumentException::class,
            sprintf('The attribute [%s] takes a FrozenBlock, got [%s].', $key, get_debug_type($value)),
        );

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
