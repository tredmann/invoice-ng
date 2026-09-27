# Die Übersicht — company dashboard

**Status: built.** `/admin/{company}`, system design §9.

## What it answers

Issuing landed, so for the first time there are figures. The screen has two
states: the **Erste Schritte** a company sees until it has issued anything, and
the **Kennzahlen** it sees from then on.

The Kennzahlen, as the Penpot board draws them: four tiles (Umsatz this month
with a month-on-month comparison, Umsatz year to date, offene Forderungen,
Überfällig), a twelve-month Umsatzverlauf, the newest Entwürfe, and the longest
overdue Rechnungen.

## The figures

`App\Actions\CollectFigures(Company, ?Carbon $asOf): App\Company\Figures`, in
the shape of `CheckReadiness → Readiness`: an object with a stable answer rather
than methods on the page, because the customer detail page needs most of the
same numbers and its three tiles are still hardcoded to „0,00 €".

**Nine queries when a company has figures, one when it has not** — constant in
the number of Belege, and asserted in `FiguresTest`. Every private method takes
a `Builder`, never a `Company`, so `forCustomer()` becomes an entry point rather
than a second set of queries.

1. The empty-state probe. Asked first, and the only question asked of a company
   that has never issued.
2. One grouped `date_trunc` aggregate over twelve months serves the month tile,
   the previous month, the year to date and every bar of the chart. A
   twelve-month window ending on the current month always reaches 1 January of
   that year — exactly, in December — so the Jahresumsatz costs no query.
3. + 4. The offenen Forderungen and the überfälligen, through `OpenItems::sum()`.
5.–9. The three newest Entwürfe (with `customer` and `lineItems`) and the three
   longest overdue (with `customer`).

**Tenancy.** Every query goes through `$company->documents()`. Filament
registers the tenant scope on `Invoice` — the Resource's model — not on
`Document`, so `Document::query()` here would sum across companies, and
`Invoice::query()` would only be scoped inside a booted panel, making the answer
depend on where it runs. `FiguresTest` creates the *other* company's Beleg first,
so an unscoped query resolves to it.

**Drafts cannot be aggregated in SQL.** A draft's `gross_total` is NULL until it
is issued; its Betrag exists only through `totals()` over the loaded relation.
That is why the Entwürfe card eager-loads `lineItems`, and why the N+1 guard
compares one row against three rather than two against six — the worklists cap
at three, so the larger comparison would stay green against an N+1.

## Netto and brutto, and why they sit side by side

**Umsatz is netto.** Umsatzsteuer is collected for the state and passed on; it
is not income. CONTEXT.md puts the Bruttobetrag as „was der Kunde zahlt", which
is a receivable. The chart is labelled „Netto" for the same reason, and the
month tile would otherwise show a different number from its own bar.

**Die offenen Forderungen sind brutto.** A customer owes the gross; a net
receivable is not a figure anyone can chase.

So the tile row mixes the two. The sublines say so — „Netto" and „Netto, seit
01.01.2026" — because without it the two would eventually be added together.

## The offener Betrag, until Zahlungen exist

CONTEXT.md: **Bruttobetrag minus Zahlungen minus Teilstornos**. Neither exists.
So today the offener Betrag is the Bruttobetrag of an outstanding Beleg — not an
approximation but the truth, since nothing in the application can record a
payment. The tiles are shown rather than withheld: „no figure" is not more
honest than „the figure before payments", only less useful.

`App\Documents\OpenItems` is the seam, and the one file that changes when
`Payment` and `PartialCancellation` land. The dashboard, the customer's tiles
and any later Offene-Posten screen move with it because none of them writes that
sum itself.

## Überfällig in two languages

`Document::isOverdue()` was the single definition, and its docblock says so
precisely to stop a second one appearing. A set cannot be filtered by a PHP
predicate, so `whereOverdue()` now exists beside it.

What keeps them equal is `DocumentTest`: it builds a Beleg for every
`DocumentStatus` × (`due_on` before / on / after today / null) and asserts the
scope returns exactly the ids `isOverdue()` says — **and that the expected set is
not empty**, or the comparison would pass with both sides broken. The matrix is
generated from `DocumentStatus::cases()`, so a sixth status handled on one side
only turns it red without anyone remembering to extend it.

The status sets themselves live on the enum (`isOutstanding()`,
`countsAsRevenue()`), so „which statuses" is written once and read by both.

**The scopes are named `whereOverdue` / `whereOutstanding`, not `overdue`.**
`Model::isRelation()` is `method_exists()`, so a method named `overdue` makes
`$document->overdue` resolve as a relation and die — and `InvoicesTable` already
has a `TextColumn::make('overdue')`.

`DocumentType` exists for the other half of §9's exclusion: a Gutschrift shares
the table and the Nummernkreis, so to a sum it looks exactly like a Rechnung. A
parity test against `Document::$childTypes` makes registering one without a
decision a failing test rather than a silent addition to every revenue figure
(ADR 0002).

## The empty-state rule

Figures appear once `$company->documents()->whereNot('status', Draft)->exists()`
— **has ever issued**, not „has revenue" and not „has documents".

- Drafts and nothing issued → Erste Schritte. Every tile would be zero and the
  next step is the one that card names.
- Issued, then everything cancelled → the figures, at zero. It has invoiced;
  sending it back to the onboarding card would read as data loss.

A `hasRevenue()` condition would make the screen oscillate.

## The screen, measured

Every value below was read off the Penpot board through the MCP, not eyeballed.

**The page frame is already Filament's.** `.fi-main` padding-inline is 32px and
the schema container's gap is 24px; the board's Content board is padding 32,
gap 24. They agree exactly, so nothing here re-creates them.

| Block | Measured |
|---|---|
| Kennzahlen row | 4 columns, gap 20; tile padding 20, radius 12, border `--gray-200`, inner gap 8 |
| Tile label / value / note | 13/500 `--gray-500` · 26/600 `--gray-950` · 12/400 `--gray-500` |
| Cards | radius 12, padding 24 (Entwürfe 20), 16 from the subline to the content |
| Chart | height 216; bars 20 wide, radius 4, `#848d9c`, current month `#d97706` |
| Entwürfe row | name 13/500 over date 11/400, 2 apart; hairline `--gray-100` |
| Überfällig row | padding-block 12; due date in `--danger-700` |

Colours come from Filament's variables, never hex — except the two bar colours,
which a canvas cannot resolve `var(…)` for.

**Two things about Filament that this screen had to work around**, both of which
fail silently and are the reason a rendered page must be looked at:

1. `extraAttributes()` on a `Section` lands on a wrapper *around* `.fi-section`,
   so every padding override needs `.app-class > .fi-section > …`. Written
   without it the rules match nothing and the page quietly shows Filament's own
   spacing.
2. A Section with a header draws a rule above its content
   (`.fi-section-has-header > .fi-section-content-ctn`). The board draws none,
   and with it each card reads as two blocks instead of one.

## Deviations from the board, stated

- **The Umsatzverlauf is Chart.js**, not the board's drawn bars, and therefore
  has a hover tooltip the board does not draw. Everything else — bar width,
  radius, colours, the amber current month, hidden legend — is set to the
  measured values. Chart.js ships pre-built with `filament/widgets`; no Node is
  involved. Per-bar colour works because a dataset-level `backgroundColor` array
  beats the `options.backgroundColor` the Alpine component derives from CSS.
- The widget is **not lazy**. Filament defers widgets because one usually
  queries for itself; this one is handed its twelve numbers at mount, so
  deferring would be a second round trip for an answer already in hand.
- The y-axis shows five gridlines where the board draws four. Chart.js picks the
  step from the data.
- „Alle offenen Posten" points at the invoice list filtered to `issued`. Offen
  means issued *or* sent and the list's filter takes one value; it is exact today
  because nothing writes `sent`, and „Offene Posten" is its own screen when that
  changes.
- The Überfällig tile is red **only when something is overdue**. At 0,00 € it
  would warn about nothing, and a page where something is always red warns never.

## Tests

`tests/Feature/FiguresTest.php` asks the action directly, outside the panel —
inside one, Filament's scope on `Invoice` would hide a leak rather than expose
it, and the query counts would include the panel's own.
`tests/Feature/DashboardTest.php` renders the real URL and asserts the German
formatting end to end. `tests/Feature/DocumentTest.php` holds the two overdue
definitions equal and the Belegarten in step.
