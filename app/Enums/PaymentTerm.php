<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/**
 * The Zahlungsziel: the period within which a Rechnung is to be paid.
 *
 * An enum rather than a table because nothing asks the user to invent one —
 * the set of usual German terms is small and closed, and each case is a
 * duration plus a wording that prints verbatim on the Beleg. See
 * `docs/adr/0003-stammdatenlisten.md`.
 */
enum PaymentTerm: string implements HasLabel
{
    case Immediate = 'immediate';
    case Net7 = 'net_7';
    case Net14 = 'net_14';
    case Net30 = 'net_30';
    case Net60 = 'net_60';

    /**
     * The duration itself, in days.
     */
    public function days(): int
    {
        return match ($this) {
            self::Immediate => 0,
            self::Net7 => 7,
            self::Net14 => 14,
            self::Net30 => 30,
            self::Net60 => 60,
        };
    }

    /**
     * The Fälligkeitsdatum that follows from this Zahlungsziel.
     *
     * The Zahlungsziel is a duration and the Fälligkeitsdatum is a day
     * (`CONTEXT.md`), and this is the only place the second is derived from the
     * first. The day is taken whole: an invoice issued at 17:40 is due on a
     * date, not at a time.
     */
    public function dueDateFrom(CarbonInterface $issuedOn): CarbonImmutable
    {
        return CarbonImmutable::instance($issuedOn)
            ->startOfDay()
            ->addDays($this->days());
    }

    public function getLabel(): string
    {
        return __("company.payment_term.{$this->value}");
    }
}
