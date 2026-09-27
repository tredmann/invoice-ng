<?php

declare(strict_types=1);

namespace App\Actions;

use App\Company\Figures;
use App\Company\MonthlyRevenue;
use App\Documents\OpenItems;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Money\Euro;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The Kennzahlen of system design §9, for one company: Umsatz this month and
 * year to date, the offenen Forderungen, what is überfällig, the twelve-month
 * Umsatzverlauf, and the two worklists.
 *
 * An object with a stable answer rather than methods on the page, for the same
 * reason as `CheckReadiness`: the customer detail page needs most of the same
 * figures, and its three tiles are still hardcoded to „0,00 €" waiting for
 * them.
 *
 * **Every query goes through `$company->documents()`.** Filament registers the
 * tenant scope on `Invoice` — the Resource's model — and not on `Document`, so
 * `Document::query()` here would silently sum across companies, while
 * `Invoice::query()` would only be scoped inside a booted panel and would make
 * this object's answer depend on where it runs. `FiguresTest` creates the
 * *other* company's Beleg first, so an unscoped query resolves to it.
 *
 * Nine queries when a company has figures, one when it has not — constant in
 * the number of Belege, and asserted.
 */
final class CollectFigures
{
    /**
     * The number of rows each worklist shows. The board draws three of each.
     */
    private const int WORKLIST = 3;

    public function __invoke(Company $company, ?Carbon $asOf = null): Figures
    {
        $asOf ??= today();

        // Asked first, and the only question asked of a company that has never
        // issued: everything below could then answer nothing but zero, and the
        // screen shows Erste Schritte instead.
        if (! $this->hasIssued($company)) {
            return Figures::none($asOf);
        }

        $series = $this->series($company, $asOf);

        $open = OpenItems::sum($this->documents($company));
        $overdue = OpenItems::sum($this->documents($company)->whereOverdue($asOf));

        return new Figures(
            hasIssued: true,
            monthRevenue: $series[count($series) - 1]->net,
            previousMonthRevenue: $series[count($series) - 2]->net,
            yearRevenue: $this->yearToDate($series, $asOf),
            series: $series,
            openTotal: $open->amount,
            openCount: $open->count,
            overdueTotal: $overdue->amount,
            overdueCount: $overdue->count,
            drafts: $this->latestDrafts($company),
            overdueDocuments: $this->longestOverdue($company, $asOf),
            asOf: $asOf,
        );
    }

    /**
     * Whether this company has ever issued a Beleg.
     *
     * „Has ever issued", deliberately — not „has revenue" and not „has
     * documents". A company whose every Rechnung was later storniert has
     * invoiced, and putting the onboarding card back in front of it would read
     * as data loss; a company with nothing but Entwürfe has not, and its next
     * step is the one that card names.
     */
    private function hasIssued(Company $company): bool
    {
        return $company->documents()->whereNot('status', DocumentStatus::Draft)->exists();
    }

    /**
     * The twelve months ending with $asOf's, oldest first.
     *
     * Built from the **calendar** and not from the rows that came back: a month
     * with no Umsatz has to be a zero bar, and a series assembled from the
     * result set would silently render nine bars instead of twelve.
     *
     * Bounded at both ends. Without the upper bound a Beleg dated next month
     * grows a thirteenth bar and joins „Umsatz 2026", and the tile and the
     * chart would then disagree about the same month.
     *
     * @return list<MonthlyRevenue>
     */
    private function series(Company $company, Carbon $asOf): array
    {
        $first = $asOf->copy()->startOfMonth()->subMonths(11);

        /** @var \Illuminate\Support\Collection<string, int|string> $rows */
        $rows = $this->documents($company)
            ->whereCountsAsRevenue()
            ->whereBetween('issued_on', [
                $first->toDateString(),
                $asOf->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw("date_trunc('month', issued_on)::date as month, coalesce(sum(net_total), 0) as net")
            ->groupByRaw("date_trunc('month', issued_on)")
            ->pluck('net', 'month');

        $series = [];

        for ($back = 11; $back >= 0; $back--) {
            $month = $asOf->copy()->startOfMonth()->subMonths($back);

            $series[] = new MonthlyRevenue(
                $month,
                Euro::fromMinor($rows[$month->toDateString()] ?? null),
                isCurrent: $back === 0,
            );
        }

        return $series;
    }

    /**
     * Umsatz since 1 January, summed over the months already fetched.
     *
     * A twelve-month window ending on the current month always reaches back to
     * January of that year — exactly, in December — so this costs no query of
     * its own.
     *
     * @param  list<MonthlyRevenue>  $series
     */
    private function yearToDate(array $series, Carbon $asOf): Money
    {
        $total = Money::zero('EUR');

        foreach ($series as $month) {
            if ($month->month->year === $asOf->year) {
                $total = $total->plus($month->net);
            }
        }

        return $total;
    }

    /**
     * The newest Entwürfe, with their Positionen loaded.
     *
     * The eager load is not an optimisation: a draft's `gross_total` is NULL
     * until it is issued, so its Betrag exists only through `totals()` over the
     * loaded relation. Without it the card asks the database once per row.
     *
     * `created_at` breaks the tie because three drafts dated the same day are
     * the common case, and an unstable order makes the card reshuffle itself
     * between renders.
     *
     * @return Collection<int, Document>
     */
    private function latestDrafts(Company $company): Collection
    {
        return $this->documents($company)
            ->where('status', DocumentStatus::Draft)
            ->whereIn('type', DocumentType::revenueValues())
            ->with(['customer', 'lineItems'])
            ->orderByDesc('issued_on')
            ->orderByDesc('created_at')
            ->limit(self::WORKLIST)
            ->get();
    }

    /**
     * The längst überfälligen Belege, the longest-waiting first — the order the
     * card is read in.
     *
     * @return Collection<int, Document>
     */
    private function longestOverdue(Company $company, Carbon $asOf): Collection
    {
        return $this->documents($company)
            ->whereOverdue($asOf)
            ->with('customer')
            ->orderBy('due_on')
            ->limit(self::WORKLIST)
            ->get();
    }

    /**
     * @return Builder<Document>
     */
    private function documents(Company $company): Builder
    {
        return $company->documents()->getQuery();
    }
}
