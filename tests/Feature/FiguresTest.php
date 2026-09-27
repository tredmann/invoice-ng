<?php

declare(strict_types=1);

use App\Actions\CollectFigures;
use App\Company\Figures;
use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Invoice;
use Brick\Money\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Kennzahlen, asked of the action directly — outside the panel, the way
 * ReadinessTest asks CheckReadiness.
 *
 * Outside on purpose: inside a booted panel Filament's tenant scope sits on
 * Invoice and would hide a leak rather than expose it, and the query counts
 * below would include the panel's own.
 */
function figuresOf(Company $company, ?string $asOf = null): Figures
{
    return (new CollectFigures)($company, $asOf === null ? null : Date::parse($asOf));
}

/**
 * The Betrag as a plain decimal string.
 *
 * Not Euro::format(): that puts a non-breaking space before the €, so a test
 * written with an ordinary one fails on a difference nobody can see in the
 * diff. The German formatting is asserted once, end to end, in DashboardTest.
 */
function amountOf(Money $money): string
{
    return (string) $money->getAmount();
}

/**
 * An issued Rechnung with the Beträge stated, so a test can say what it expects
 * the sums to be. Issued by force-fill rather than by IssueDocument: these
 * tests are about the arithmetic over a set, not about the Ausstellvorgang.
 */
function issuedOn(Company $company, string $date, string $net, string $tax, string $gross, ?string $dueOn = null, DocumentStatus $status = DocumentStatus::Issued): Invoice
{
    return Invoice::factory()->for($company)->issued()->create([
        'status' => $status,
        'issued_on' => $date,
        'due_on' => $dueOn ?? Date::parse($date)->addDays(14)->toDateString(),
        'net_total' => Money::of($net, 'EUR'),
        'tax_total' => Money::of($tax, 'EUR'),
        'gross_total' => Money::of($gross, 'EUR'),
    ]);
}

it('sums the Umsatz of the current month from the Nettobeträge', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-04', '1000.00', '190.00', '1190.00');
    issuedOn($company, '2026-09-28', '140.00', '26.60', '166.60');
    // August: near enough to be caught by an off-by-one month boundary.
    issuedOn($company, '2026-08-31', '9999.00', '1899.81', '11898.81');

    $figures = figuresOf($company, '2026-09-27');

    expect(amountOf($figures->monthRevenue))->toBe('1140.00')
        ->and(amountOf($figures->previousMonthRevenue))->toBe('9999.00');
});

it('leaves Entwürfe and stornierte Belege out of the Umsatz', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');

    // A cancelled Beleg keeps its Beträge — „kein geminderter Umsatz" means it
    // leaves the sum, not that it subtracts from it. Non-zero on purpose: a
    // draft's net_total is NULL, so a missing status filter would still look
    // right without this row.
    issuedOn($company, '2026-09-05', '500.00', '95.00', '595.00', status: DocumentStatus::Cancelled);

    Invoice::factory()->for($company)->create(['issued_on' => '2026-09-06']);

    expect(amountOf(figuresOf($company, '2026-09-27')->monthRevenue))->toBe('100.00');
});

it('leaves a Gutschrift out of the Umsatz', function (): void {
    $company = Company::factory()->create();

    $invoice = issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');

    // Written past Eloquent: Parental cannot hydrate an alias it does not know,
    // but an aggregate never hydrates a row, so this is what a Gutschrift will
    // look like to the sum the day it exists (ADR 0002).
    DB::table('documents')->insert([
        ...(array) DB::table('documents')->where('id', $invoice->getKey())->first(),
        'id' => (string) Str::uuid7(),
        'type' => 'self_billed_invoice',
        'number' => 'GS-2026-0001',
        'net_total' => 500000,
        'tax_total' => 95000,
        'gross_total' => 595000,
    ]);

    // The real invoice is what stops this passing against no type filter at
    // all: without it the sum would be 0,00 € either way.
    expect(amountOf(figuresOf($company, '2026-09-27')->monthRevenue))->toBe('100.00');
});

it('counts only the company in the path', function (): void {
    $theirs = Company::factory()->create();
    $mine = Company::factory()->create();

    // Theirs first, so an unscoped query resolves to it.
    issuedOn($theirs, '2026-09-04', '7777.00', '1477.63', '9254.63');
    issuedOn($mine, '2026-09-04', '100.00', '19.00', '119.00');

    $figures = figuresOf($mine, '2026-09-27');

    expect(amountOf($figures->monthRevenue))->toBe('100.00')
        ->and(amountOf($figures->openTotal))->toBe('119.00')
        ->and($figures->openCount)->toBe(1);
});

it('reports twelve months ending in the current one', function (): void {
    $company = Company::factory()->create();
    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');

    $series = figuresOf($company, '2026-09-27')->series;

    expect($series)->toHaveCount(12)
        ->and($series[0]->month->toDateString())->toBe('2025-10-01')
        ->and($series[11]->month->toDateString())->toBe('2026-09-01')
        ->and($series[11]->isCurrent)->toBeTrue()
        ->and($series[10]->isCurrent)->toBeFalse();
});

it('gives a month with no Umsatz a zero rather than leaving it out', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');
    issuedOn($company, '2026-07-04', '200.00', '38.00', '238.00');

    $series = figuresOf($company, '2026-09-27')->series;

    // August is empty and must still be a bar; a series built from the rows
    // that came back would silently render eleven.
    expect($series)->toHaveCount(12)
        ->and($series[10]->month->toDateString())->toBe('2026-08-01')
        ->and(amountOf($series[10]->net))->toBe('0.00')
        ->and(amountOf($series[9]->net))->toBe('200.00');
});

it('derives the Jahresumsatz from the months of the current year', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-01-09', '300.00', '57.00', '357.00');
    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');
    // Inside the twelve-month window, outside the year.
    issuedOn($company, '2025-12-09', '900.00', '171.00', '1071.00');

    expect(amountOf(figuresOf($company, '2026-09-27')->yearRevenue))->toBe('400.00');
});

it('reaches the whole year in December too', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-01-09', '300.00', '57.00', '357.00');
    issuedOn($company, '2026-12-09', '100.00', '19.00', '119.00');

    // An eleven-month window would lose January here.
    expect(amountOf(figuresOf($company, '2026-12-27')->yearRevenue))->toBe('400.00');
});

it('leaves a Beleg dated next month out of this year', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');
    issuedOn($company, '2026-10-04', '5000.00', '950.00', '5950.00');

    $figures = figuresOf($company, '2026-09-27');

    // Without the window's upper bound the tile and the chart would disagree
    // about the same twelve months.
    expect(amountOf($figures->yearRevenue))->toBe('100.00')
        ->and($figures->series)->toHaveCount(12);
});

it('leaves the Monatsvergleich open when the previous month had nothing', function (): void {
    $company = Company::factory()->create();
    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');

    expect(figuresOf($company, '2026-09-27')->monthChange())->toBeNull();
});

it('reports the Monatsvergleich as a signed whole percentage', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-08-04', '1000.00', '190.00', '1190.00');
    issuedOn($company, '2026-09-04', '1110.00', '210.90', '1320.90');

    expect(figuresOf($company, '2026-09-27')->monthChange())->toBe(11);

    $falling = Company::factory()->create();
    issuedOn($falling, '2026-08-04', '1000.00', '190.00', '1190.00');
    issuedOn($falling, '2026-09-04', '900.00', '171.00', '1071.00');

    expect(figuresOf($falling, '2026-09-27')->monthChange())->toBe(-10);
});

it('sums the offenen Forderungen from the Bruttobeträge of what is still open', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00');
    issuedOn($company, '2026-09-05', '200.00', '38.00', '238.00', status: DocumentStatus::Sent);
    // Neither of these is still open.
    issuedOn($company, '2026-09-06', '900.00', '171.00', '1071.00', status: DocumentStatus::Paid);
    issuedOn($company, '2026-09-07', '800.00', '152.00', '952.00', status: DocumentStatus::Cancelled);

    $figures = figuresOf($company, '2026-09-27');

    expect(amountOf($figures->openTotal))->toBe('357.00')
        ->and($figures->openCount)->toBe(2);
});

it('counts a Beleg due today as offen but not überfällig', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-09-01', '100.00', '19.00', '119.00', dueOn: '2026-09-27');
    issuedOn($company, '2026-09-01', '200.00', '38.00', '238.00', dueOn: '2026-09-26');

    $figures = figuresOf($company, '2026-09-27');

    // The Zahlungsziel runs to the end of its day, which DocumentTest already
    // asserts for isOverdue().
    expect($figures->openCount)->toBe(2)
        ->and($figures->overdueCount)->toBe(1)
        ->and(amountOf($figures->overdueTotal))->toBe('238.00');
});

it('reads the newest Entwürfe with the Bruttobetrag computed from their Positionen', function (): void {
    $company = issuableCompany();
    issuedOn($company, '2026-09-01', '100.00', '19.00', '119.00');

    $older = draftInvoice($company);
    $older->forceFill(['issued_on' => '2026-09-01'])->save();

    $newer = draftInvoice($company);
    $newer->forceFill(['issued_on' => '2026-09-25'])->save();

    $drafts = figuresOf($company, '2026-09-27')->drafts;

    expect($drafts->pluck('id')->all())->toBe([$newer->getKey(), $older->getKey()])
        // gross_total is NULL on a draft; reading the column would print 0,00 €.
        ->and(amountOf($drafts->firstOrFail()->grossAmount()))->toBe('1666.90');
});

it('reads the längst überfälligen Belege, longest first, and only three', function (): void {
    $company = Company::factory()->create();

    issuedOn($company, '2026-07-01', '100.00', '19.00', '119.00', dueOn: '2026-08-20');
    issuedOn($company, '2026-07-01', '200.00', '38.00', '238.00', dueOn: '2026-08-01');
    issuedOn($company, '2026-07-01', '300.00', '57.00', '357.00', dueOn: '2026-09-10');
    issuedOn($company, '2026-07-01', '400.00', '76.00', '476.00', dueOn: '2026-09-20');

    $overdue = figuresOf($company, '2026-09-27')->overdueDocuments;

    expect($overdue)->toHaveCount(3)
        ->and($overdue->pluck('due_on')->map(fn ($d) => $d->toDateString())->all())
        ->toBe(['2026-08-01', '2026-08-20', '2026-09-10']);
});

it('shows figures for a company whose every Beleg was storniert', function (): void {
    $company = Company::factory()->create();
    issuedOn($company, '2026-09-04', '100.00', '19.00', '119.00', status: DocumentStatus::Cancelled);

    $figures = figuresOf($company, '2026-09-27');

    // It has invoiced. Sending it back to Erste Schritte would read as data loss.
    expect($figures->hasIssued)->toBeTrue()
        ->and(amountOf($figures->monthRevenue))->toBe('0.00');
});

it('shows no figures for a company with nothing but Entwürfe', function (): void {
    $company = Company::factory()->create();
    Invoice::factory()->for($company)->create();

    expect(figuresOf($company, '2026-09-27')->hasIssued)->toBeFalse();
});

it('costs nine queries with figures and one without, whatever the number of Belege', function (): void {
    $empty = Company::factory()->create();
    Invoice::factory()->for($empty)->create();

    $small = issuableCompany();
    issuedOn($small, '2026-09-04', '100.00', '19.00', '119.00', dueOn: '2026-09-01');
    draftInvoice($small);

    $large = issuableCompany();
    for ($i = 1; $i <= 30; $i++) {
        issuedOn($large, '2026-09-04', '100.00', '19.00', '119.00', dueOn: '2026-09-01');
        draftInvoice($large);
    }

    expect(queryCountOf(fn (): Figures => figuresOf($empty, '2026-09-27')))->toBe(1)
        ->and(queryCountOf(fn (): Figures => figuresOf($small, '2026-09-27')))->toBe(9)
        // The same, with thirty times the data: no query per row anywhere.
        ->and(queryCountOf(fn (): Figures => figuresOf($large, '2026-09-27')))->toBe(9);
});

/**
 * @param  callable(): mixed  $work
 */
function queryCountOf(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getRawQueryLog());

    DB::disableQueryLog();

    return $count;
}
