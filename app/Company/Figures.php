<?php

declare(strict_types=1);

namespace App\Company;

use App\Models\Document;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The **Kennzahlen** of one company, as of one day (system design §9).
 *
 * Carries `Money` and `Carbon`, never formatted strings: `Euro::format()` and
 * the German month names belong to the view, and a test that asserts cents
 * cannot pass because the locale happened to be right.
 *
 * `$asOf` rides along so the view can name „September", „August" and
 * „01.01.2026" without asking `today()` a second time and disagreeing with the
 * figures across a midnight boundary.
 *
 * **Netto and brutto are mixed here on purpose.** Umsatz is netto — VAT is not
 * revenue — while the offene Forderungen are brutto, because a receivable is
 * what the customer actually owes. The tiles say so in their sublines; without
 * that the two would eventually be added together.
 */
final readonly class Figures
{
    /**
     * @param  list<MonthlyRevenue>  $series  Twelve months, oldest first; the last is $asOf's.
     * @param  Collection<int, Document>  $drafts  Newest first, Positionen loaded.
     * @param  Collection<int, Document>  $overdueDocuments  Longest overdue first.
     */
    public function __construct(
        public bool $hasIssued,
        public Money $monthRevenue,
        public Money $previousMonthRevenue,
        public Money $yearRevenue,
        public array $series,
        public Money $openTotal,
        public int $openCount,
        public Money $overdueTotal,
        public int $overdueCount,
        public Collection $drafts,
        public Collection $overdueDocuments,
        public Carbon $asOf,
    ) {}

    /**
     * A company that has never issued: the Erste-Schritte card, and none of the
     * eight queries that could only answer zero.
     */
    public static function none(Carbon $asOf): self
    {
        $zero = Money::zero('EUR');

        return new self(
            hasIssued: false,
            monthRevenue: $zero,
            previousMonthRevenue: $zero,
            yearRevenue: $zero,
            series: [],
            openTotal: $zero,
            openCount: 0,
            overdueTotal: $zero,
            overdueCount: 0,
            drafts: new Collection,
            overdueDocuments: new Collection,
            asOf: $asOf,
        );
    }

    /**
     * The „11 % ggü. August" line: a signed whole percentage, or null when
     * there is nothing to compare against.
     *
     * Null rather than 0 or 100 when the previous month was empty. A month
     * following a month of nothing has no percentage, and any number printed
     * there would be a claim nobody can check. The guard is `<= 0` and not
     * `=== 0` because a Teilstorno will one day make a month negative, and a
     * percentage of a negative base reads backwards.
     */
    public function monthChange(): ?int
    {
        $previous = $this->previousMonthRevenue->getMinorAmount()->toInt();

        if ($previous <= 0) {
            return null;
        }

        $current = $this->monthRevenue->getMinorAmount()->toInt();

        return BigDecimal::of($current - $previous)
            ->multipliedBy(100)
            ->dividedBy($previous, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    public function month(): Carbon
    {
        return $this->asOf->copy()->startOfMonth();
    }

    public function previousMonth(): Carbon
    {
        return $this->month()->subMonth();
    }

    public function year(): int
    {
        return $this->asOf->year;
    }
}
