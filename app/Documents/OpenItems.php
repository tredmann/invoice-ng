<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\Document;
use App\Money\Euro;
use Illuminate\Database\Eloquent\Builder;

/**
 * The **offener Betrag** of a set of Belege — the Offenen Posten, summed.
 *
 * CONTEXT.md defines the offener Betrag as **Bruttobetrag minus Zahlungen
 * minus Teilstornos**. Neither `Payment` nor `PartialCancellation` exists, so
 * today it is simply the Bruttobetrag of an outstanding Beleg. The figure is
 * shown anyway rather than withheld: nothing in the application can record a
 * payment, so „everything issued is still open" is not an approximation here,
 * it is the truth — and „no figure" would be less useful without being more
 * honest.
 *
 * **This is the one place that changes when Zahlungen land.** The sum becomes
 * `gross_total - coalesce(payments, 0) - coalesce(partial cancellations, 0)`
 * over a left join, and `Document::whereOutstanding()` gains „and the payments
 * do not cover it". The dashboard's two tiles, the customer's tiles and the
 * Offene-Posten screen all move together, because none of them writes this
 * sum itself.
 */
final class OpenItems
{
    /**
     * @param  Builder<Document>  $documents  Already scoped to one company.
     */
    public static function sum(Builder $documents): OpenTotal
    {
        /** @var object{total: int|string|null, count: int|string}|null $row */
        $row = $documents->whereOutstanding()
            ->selectRaw('coalesce(sum(gross_total), 0) as total, count(*) as count')
            ->first();

        return new OpenTotal(
            Euro::fromMinor($row->total ?? null),
            (int) ($row->count ?? 0),
        );
    }
}
