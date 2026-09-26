<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Company;
use App\Models\NumberRange;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Takes the next Belegnummer from a company's Nummernkreis, under a lock.
 *
 * This is where the gapless guarantee of system design §5 actually lives. The
 * number is drawn inside the transaction that issues the Beleg: if any later
 * step fails — the PDF, the XML, the upload — the transaction rolls back and
 * the number was never consumed.
 *
 * Written here rather than taken from a package (tech stack §6.6): gaplessness
 * is the property this system most needs to be able to defend, so it should be
 * readable in this project's own code.
 */
final class DrawNextNumber
{
    public function __invoke(Company $company): string
    {
        // Not defensive programming — the guarantee itself. Outside a
        // transaction the FOR UPDATE lock is released the instant the select
        // returns, so two concurrent draws can read the same next_value and
        // hand out one number twice, while every single-threaded test still
        // passes. Cheap to check, impossible to notice missing.
        throw_if(
            DB::transactionLevel() === 0,
            LogicException::class,
            'A Belegnummer must be drawn inside the transaction that issues the Beleg, '
            .'or the lock that keeps the Nummernkreis gapless is released immediately.'
        );

        $range = NumberRange::query()
            ->where('company_id', $company->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $year = (int) now()->year;
        $value = $range->upcomingValue($year);
        $number = $range->format($value, $year);

        $range->next_value = $value + 1;
        $range->drawn_count = (int) $range->drawn_count + 1;

        if ($range->reset_yearly) {
            $range->last_reset_year = $year;
        }

        $range->save();

        return $number;
    }
}
