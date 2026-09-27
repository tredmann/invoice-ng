<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The Belegarten, and whether each one's Betrag is Umsatz (§9).
 *
 * The values are Parental's aliases — what the `type` column stores — so this
 * enum and `Document::$childTypes` describe the same set from two sides, and
 * `DocumentTest` holds them equal.
 *
 * Only `Invoice` exists, so „excludes Gutschriften" is trivially true today.
 * The enum exists so it stays trivially *true* rather than quietly false: a
 * `Gutschrift` registered in `$childTypes` without a decision here turns that
 * parity test red instead of silently joining every revenue sum. It draws from
 * the same Nummernkreis and looks exactly like an invoice to a query that sums
 * over it, which is precisely why ADR 0002 exists.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';

    /**
     * Whether this Belegart's Betrag belongs in a revenue sum.
     *
     * A `match` without a default, so a new case is a compile-time question
     * rather than a silent `true`.
     */
    public function countsAsRevenue(): bool
    {
        return match ($this) {
            self::Invoice => true,
        };
    }

    /**
     * The stored values of the Belegarten that count as Umsatz, for a
     * `whereIn`.
     *
     * @return list<string>
     */
    public static function revenueValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(
                self::cases(),
                fn (self $type): bool => $type->countsAsRevenue(),
            ),
        ));
    }
}
