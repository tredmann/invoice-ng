<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The Einheit of a Position, from the fixed global list of system design §3.3.
 *
 * Backed by the UN/ECE Recommendation 20 code itself, so a Position stores the
 * code, the EN16931 XML gets it without a lookup, and there is no second place
 * where „Stunde" and `HUR` could drift apart.
 *
 * An enum rather than a table because §3.3 calls the list a standard that is
 * not per-company data and not user-editable, and §3.6 says a Position
 * references no master data — which a foreign key would contradict. See
 * `docs/adr/0003-stammdatenlisten.md`.
 */
enum Unit: string implements HasLabel
{
    case Piece = 'H87';
    case Hour = 'HUR';
    case Day = 'DAY';
    case LumpSum = 'LS';
    case Kilometre = 'KMT';

    /**
     * Coerces a form's state — the enum itself, its backing code, or nothing
     * yet — to a unit, in the shape of `CustomerType::fromFormState()`. A
     * Select over an enum hands back the enum, and a string cast of one is a
     * fatal error rather than the code.
     */
    public static function fromFormState(mixed $state): ?self
    {
        if ($state instanceof self) {
            return $state;
        }

        return is_string($state) ? self::tryFrom($state) : null;
    }

    public function getLabel(): string
    {
        return __("unit.{$this->value}");
    }
}
