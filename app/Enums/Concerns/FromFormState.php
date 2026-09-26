<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Coerces a form's state — the enum itself, its backing string, or nothing yet
 * — to a case.
 *
 * A Filament Select or ToggleButtons over an enum hands back the enum, while a
 * freshly filled or untouched form holds the backing string or null, and a
 * string cast of an enum is a fatal error. Every closure that asks the state a
 * question therefore has to accept all three.
 *
 * In a trait since the fourth enum needed it. The first three each carried
 * their own copy, and the fourth is where that stops being a coincidence.
 */
trait FromFormState
{
    public static function fromFormState(mixed $state): ?static
    {
        if ($state instanceof static) {
            return $state;
        }

        return is_string($state) ? static::tryFrom($state) : null;
    }
}
