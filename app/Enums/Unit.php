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

    public function getLabel(): string
    {
        return __("unit.{$this->value}");
    }
}
