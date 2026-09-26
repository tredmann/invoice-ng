# Firmenstammdaten und Geldfundament — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Spec:** `docs/superpowers/specs/2026-09-26-company-master-data-design.md` — the authority.
Vocabulary: `CONTEXT.md`. UI rules: `.ai/guidelines/ui/core.blade.php`. Decisions: `docs/adr/0003-stammdatenlisten.md`.
Mockups: the Penpot boards `Einstellungen – Firma / Steuer / Bank / Nummernkreis`, `Dashboard – leer (Erstnutzung)`, `Rechnung – Neu (Entwurf)`.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5, PostgreSQL 17. All commands through `docker compose run --rm app …`.

**Goal:** everything the *Ausstellvorgang* needs before the first Rechnung can exist.
No Belege in this wave.

---

## Context

The repo stops exactly where issuing would begin. Companies, tenancy and customers
work; `brick/money` is installed and used by nothing; `tightenco/parental` is
installed and used by nothing; `config/invoice.php` already carries a
`documents_disk` seam that nothing writes to. There is no `app/Actions`, no money
column in any migration, no `Steuersatz`, no `Zahlungsziel`, no `Nummernkreis`, no
`Einheit`, and no readiness check — `Company` has no `isComplete()` of any kind.

`CLAUDE.md` records the consequence as a live hazard: a company created through
registration has only a name and a legal form, its identity block is incomplete,
and *"it stops being safe the moment the invoicing wave can issue"*. This wave is
the one that has to make that check exist.

The intended outcome: after this wave a company can be configured to the point
where `IssueDocument` would have everything it needs — a locked, gapless number
range it can draw from, tax rates to put on a Position, a Zahlungsziel to compute
a Fälligkeitsdatum from, a unit list for the XML, a money type that cannot be
floated, a rounding rule that groups per Steuersatz, and a check that says out
loud what is still missing. The invoicing wave then writes documents against a
foundation that is already tested, instead of inventing it mid-flight.

### Decisions taken before planning

| Open question | Decision |
|---|---|
| Gutschrift + Vermittler in this wave | **No.** Pulled to its own wave after Rechnung/Storno/Teilstorno. ADR 0002's list — `Referrer` with own tax number and bank details, the `widersprochen` state, UNTDID 389, the inverted Identitätsblock — is none of it Firmenstammdaten. What this wave owes it: the Nummernkreis stays *belegartneutral*. |
| Where frozen PDFs and the logo live | **Separate disks.** `config/invoice.php` keeps `documents_disk` (unused, waiting) and gains `logos_disk`. Logos go on `public` locally (`storage:link`), a Spaces bucket in production, under `companies/{uuid}/logo.{ext}`. Documents keep the §7.1 path `companies/{company}/documents/{year}/{number}.pdf` — recorded in the spec, written by nobody yet. |
| KoSIT validator job | **No.** There is no XML. A CI job with no fixtures stays green while proving nothing — the exact decoration `CLAUDE.md` forbids. It ships with the wave that produces the first ZUGFeRD file. |
| What the Bereitschaftsprüfung blocks | **Only what the law requires blocks.** Blocking: complete Anschrift, Steuernummer *or* USt-IdNr., Registereintrag when `LegalForm::isRegistered()`, a configured Nummernkreis. Warning only: Bankverbindung and Logo. A missing logo must never stop a Rechnung. |

### Two things the mockups get wrong, and what to do

1. **`Einstellungen – Bank` heads its second card „Zahlungsbedingungen".**
   `CONTEXT.md` puts *Zahlungsbedingung* on the _Vermeiden_ list for
   **Zahlungsziel**. The card is renamed „Zahlungsziel" in code, and the Penpot
   board is corrected to match.
2. **The Bereitschaftsprüfung modal and the Dashboard disagree** on what is
   checked (modal: Nummernkreis, Steuernummer, Bank, Logo — dashboard: Adresse,
   Steuernummer, Nummernkreis). The decision above settles it; the modal board is
   corrected to show blockers and warnings as two kinds, and it stays a mockup —
   no modal is built, because nothing issues yet.

---

## Design

### The three lists are modelled three different ways, on purpose

This is the one place a reader will suspect inconsistency, so it gets an ADR.

- **`Steuersatz` → a per-company table.** The mockup has „+ Steuersatz
  hinzufügen"; spec §3.4 says tax rates are deactivated, never deleted. Rows.
- **`Zahlungsziel` → a PHP enum.** The mockup has a plain select with no add
  button. A duration with a printed wording ("14 Tage netto") drawn from a fixed
  set; nothing in the domain asks a user to invent one.
- **`Einheit` → a PHP enum.** Spec §3.3: *"a standard, not per-company data, and
  not user-editable"*, and §3.6: a Position *"references no master data"*. A table
  would cost a seeder, a UUID key and a foreign key for five immutable rows, and
  a Position that points at one would contradict §3.6.

This deviates from tech-stack spec §12, which lists `PaymentTerm` and `Unit` under
`app/Models`. The deviation is deliberate and gets recorded there.

### Data model

**`tax_rates`** — uuid `id` · `company_id` uuid FK restrictOnDelete ·
`rate` integer (**basis points**: 1900 = 19 %) · `name` string ·
`is_default` boolean · `deactivated_at` nullable · timestamps.
Indexes: `unique(company_id, rate)` and a **partial unique index**
`unique(company_id) where is_default` — Postgres enforces "at most one default
per company" rather than a hook hoping to.

Basis points rather than `numeric(5,2)` for the reason integer cents exist: a
float can never get near it, and the repo's precedent is already "store the
integer, format for display" (the customer number). `Company` seeds 19 %, 7 % and
0 % on creation, 19 % default.

**`number_ranges`** — uuid `id` · `company_id` uuid FK, **unique** ·
`prefix` string nullable · `padding` unsigned small int default 4 ·
`next_value` integer default 1 · `include_year` boolean ·
`reset_yearly` boolean · `last_reset_year` integer nullable ·
`drawn_count` integer default 0 · timestamps.

Its own table, not columns on `companies`, because §7.4 holds the lock for the
whole PDF render — locking the `companies` row would block the settings page and
the switcher for a second or two per issue.

**No row until the Nummernkreis tab is saved once.** That is what makes the
readiness check's „Noch nicht konfiguriert" true rather than decorative.

**`companies`** gains `payment_term` (string, enum-backed, default `net_14`) and
`logo_path` (string, nullable).

**`customers`** gains `payment_term` (string, nullable — null means "use the
company's"). This closes the divergence the customers wave recorded in its §8 and
the customer plan's „Known divergences" table.

### `App\Enums`

- `PaymentTerm` — `immediate`, `net_7`, `net_14`, `net_30`, `net_60`;
  `HasLabel` via `__("company.payment_term.{$this->value}")`; `days(): int`;
  `dueDateFrom(CarbonInterface $issuedOn): CarbonImmutable`.
- `Unit` — backed by the **UN/ECE Rec 20 code itself**, so a Position stores the
  code and the XML gets it for free: `H87` Stück · `HUR` Stunde · `DAY` Tag ·
  `LS` Pauschal · `KMT` km. `HasLabel` via `__("unit.{$this->value}")`.

Both follow the existing `CustomerType` / `LegalForm` shape exactly.

### `App\Money` — the rounding rule of §6, as a pure unit

Four small readonly types plus one invokable, none of which needs a Document:

```
LineInput   quantity (BigDecimal), unitPrice (Money), rate (int basis points)
TaxGroup    rate, base (Money), tax (Money)
Totals      net (Money), groups (list<TaxGroup>), tax (Money), gross (Money)
CalculateTotals   __invoke(list<LineInput>): Totals
```

The order is §6 verbatim and is the whole point:
1. line net = quantity × unit price, rounded to the cent (HALF_UP)
2. group by rate; base = sum of that group's line nets
3. group tax = base × rate, **rounded once**
4. totals = sums of the group figures

`App\Casts\MoneyCast` casts a `bigint` of cents ↔ `Brick\Money\Money` in EUR.
No money column exists to use it on yet; it is written and tested here so the
invoicing wave does not write it in a hurry. `Money` is never constructed from a
float anywhere.

Kleinunternehmer is **not** a branch in the calculator. `Company::selectableTaxRates()`
is the single place that decides: for `VatScheme::SmallBusiness` it returns one
non-editable 0 % rate, and the Steuersätze card is hidden with a note saying why.

### `App\Actions\DrawNextNumber`

```php
__invoke(Company $company): string
```

1. **Refuses to run outside a transaction.** `throw_if(DB::transactionLevel() === 0, …)`
   — outside one the `FOR UPDATE` lock releases at once and gaplessness is a lie.
   This is the invariant the whole §5 guarantee rests on, so it is enforced, not
   documented.
2. `NumberRange::query()->where('company_id', …)->lockForUpdate()->firstOrFail()`
3. If `reset_yearly` and `last_reset_year < currentYear`: `next_value = 1`,
   `last_reset_year = currentYear`
4. Format: `prefix . (include_year ? year.'-' : '') . str_pad(next_value, padding, '0', LEFT)`
   — the prefix carries its own separator, so `RE-` + `2026-` + `0043` is
   `RE-2026-0043` and without the year `RE-0043`, matching the mockup's preview.
5. `next_value++`, `drawn_count++`, save, return the string.

**`next_value` may never be lowered once `drawn_count > 0`.** Enforced twice, the
way the customer number already is: a validation rule on the settings form so the
user sees a message, and a `NumberRange::reconfigure()` guard as the backstop.
The yearly reset goes through `DrawNextNumber`, not through `reconfigure()`, so
the two cannot fight.

### `App\Company\Readiness` + `App\Actions\CheckReadiness`

`CheckReadiness` returns a readonly `Readiness` carrying
`list<ReadinessItem>` (key, `satisfied`, `Severity::Blocking|Warning`) and
`canIssue(): bool` = no unsatisfied blocker.

| Item | Severity | Satisfied when |
|---|---|---|
| `address` | blocking | street, postal_code and city all present |
| `tax_identifier` | blocking | `tax_number` or `vat_id` present |
| `register` | blocking *when* `legal_form->isRegistered()* | court, number and managing directors present |
| `number_range` | blocking | a `number_ranges` row exists |
| `bank` | warning | `iban` present |
| `logo` | warning | `logo_path` present |

Nothing issues yet, so the check has exactly one consumer this wave: the
Dashboard. The `IssueDocument` gate and the modal are the invoicing wave's, and
they call this same object — that is the reason it is an object and not a method
on the settings page.

### Screens

**`/admin/{company}/settings` becomes four tabs**, not four pages — the mockup
shows one „Einstellungen" heading, one tab bar and one „Speichern" bottom-right,
which is exactly what `Filament\Schemas\Components\Tabs` inside the existing
`EditTenantProfile` gives. The page keeps its route, its label and its lack of
`SeparatesFormActions` (save-in-place: one action, far right).

- **Firma** — the existing identity, address, register and management sections,
  plus a **Logo** card (`FileUpload`, image, on the `logos_disk`).
- **Steuer** — Besteuerung (`vat_scheme` as `ToggleButtons`), Steuernummern
  (existing `requiredWithout` pair), Steuersätze (`Repeater` on the `taxRates`
  relationship, `->table([...])` for Satz / Bezeichnung / Standard, hidden for a
  Kleinunternehmer with a note).
- **Bank** — the existing bank section, plus **Zahlungsziel** (`Select` of
  `PaymentTerm`). Card heading „Zahlungsziel", not „Zahlungsbedingungen".
- **Nummernkreis** — Präfix, Stellen, Startwert, the two toggles, a live
  „Nächste Nummer" preview that **reads without consuming**, and the mockup's
  warning callout. On a company with no range row, the tab shows defaults and
  saving creates the row.

**E-Mail is not built.** SMTP and templates belong to the sending wave; the
mockup keeps the fifth tab as the target state. Recorded as a deliberate
divergence, not drift.

**Dashboard** — the single `EmptyState` becomes the mockup's „Erste Schritte"
card: step 1 „Firmendaten vervollständigen" driven by `CheckReadiness` (✓ when no
blocker, otherwise the missing items named, warnings in a quieter line, linking to
the settings tab that fixes it), step 2 „Ersten Kunden anlegen", step 3 „Erste
Rechnung schreiben" disabled with a tooltip.

**Customer form** gains a Zahlungsziel select in the Rechnungsstellung section,
placeholder „Vorgabe der Firma (14 Tage netto)", and the detail page's
Stammdaten card gains the pair the customer plan deliberately left out.

### Testing — what would make each fail

The concurrency test cannot live in `tests/Feature`: `RefreshDatabase` holds an
open transaction, and a `pcntl_fork()`ed child gets a copy of the parent's PDO
socket and cannot see uncommitted rows. A new `tests/Concurrency/` directory with
its own `phpunit.xml` testsuite and a `pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency')`
binding, each child calling `DB::purge()` and reconnecting after the fork.

1. **Two forked processes drawing from one range get distinct, consecutive
   numbers.** Fails the moment `lockForUpdate()` is removed — both read the same
   `next_value`. Nothing else in the suite would notice.
2. **A rolled-back transaction consumes no number.** Draw, roll back, draw again
   → the same string. This is §5's actual promise.
3. **Drawing outside a transaction throws.** Fails if the guard is dropped, and a
   green happy path would never reveal it.
4. **Rounding, table-driven, values derived by hand from EN16931** — including
   the mockup's own invoice (1 710,00 @ 19 % → 324,90; 290,00 @ 7 % → 20,30;
   gross 2 345,20), a fractional quantity, a Kleinunternehmer 0 % case, and the
   case where per-line and per-group rounding differ by a cent (three lines of
   0,83 @ 19 %: per line 0,48, per group 0,47). The last one is the only test
   that can catch rounding in the wrong place.
5. **Money columns are `bigint` in Postgres**, read from `information_schema`, the
   way `UuidKeysTest` already reads column types — an assertion on the cast would
   pass against a `double precision` column.
6. **The partial unique index exists**: setting `is_default` on a second rate by
   direct `DB::table()` insert raises a unique violation. No happy path notices a
   missing index.
7. **`next_value` cannot be lowered after a draw**, and *can* be before one.
   One direction alone cannot tell "guarded" from "always refused".
8. **The readiness check blocks on law and warns on the rest**: a company with no
   IBAN and no logo has `canIssue() === true`; one missing its Steuernummer does
   not; a GmbH missing its Registernummer does not, and a sole proprietorship
   with the same empty fields does.
9. **Tenancy**: company B's tax rates and number range never appear under A, and
   a draw under B does not touch A's `next_value`.
10. **UUID v7 on the two new tables**, joining `UuidKeysTest`.

Not written: that a migration has particular columns; that the settings page
returns 200 without asserting what it shows.

---

## Work

### Step 0 — branch and spec

`git checkout -b feat/company-master-data`, then write
`docs/superpowers/specs/2026-09-26-company-master-data-design.md` from the Design
section above, in the shape of `2026-09-25-customers-design.md` (Purpose · Carried
in · Data model · Screens · Error handling · Testing · Out of scope · Documents
this wave falsifies · Decisions). Commit it and **stop for review** before any
code. Copy this plan to `docs/superpowers/plans/2026-09-26-company-master-data.md`
alongside it.

Also write `docs/adr/0003-stammdatenlisten.md`: why Steuersätze are rows,
Zahlungsziele and Einheiten enums, and what the tech-stack spec §12 deviation is.

### Task 1 — `Unit` and `PaymentTerm` enums

`app/Enums/Unit.php`, `app/Enums/PaymentTerm.php`, `lang/de/unit.php`, keys in
`lang/de/company.php`. Tests: every `Unit` case's value is its UN/ECE code and all
five are distinct; `PaymentTerm::Net14->dueDateFrom('2026-09-25')` is 2026-10-09,
matching the mockup's „Fällig am 09.10.2026"; `Immediate` gives the issue date.
No migration.

### Task 2 — money: cast, value objects, the §6 rounding

`app/Casts/MoneyCast.php`, `app/Money/{LineInput,TaxGroup,Totals}.php`,
`app/Actions/CalculateTotals.php`. This creates `app/Actions` and `app/Money`.
Tests 4 and 5 above — 5 exercises the cast directly, because no money column
exists to hang it on. Nothing in the UI consumes it yet, and the spec says so.

### Task 3 — `tax_rates`

Migration (incl. the partial unique index, which needs raw SQL —
`DB::statement('create unique index … on tax_rates (company_id) where is_default')`),
`app/Models/TaxRate.php` with `HasUuids` + `BelongsTo company` + `deactivate()`
mirroring `Customer`, `TaxRateFactory`, seeding of 19/7/0 in `Company::booted()`,
`Company::taxRates()` and `Company::selectableTaxRates()`. Tests 6 and 9.

### Task 4 — `number_ranges` and `DrawNextNumber`

Migration, `app/Models/NumberRange.php` (with `reconfigure()` and the
never-lower guard), `NumberRangeFactory`, `app/Actions/DrawNextNumber.php`.
Tests 2, 3, 7, 9, 10 — all in `tests/Feature`.

### Task 5 — the concurrency test

`tests/Concurrency/` + the `phpunit.xml` testsuite + the Pest binding + the fork
harness. Test 1. Run it, then **deliberately delete `lockForUpdate()` and confirm
it goes red**, then restore — a concurrency test that has never failed is not
known to work.

### Task 6 — the settings page as four tabs

Rework `CompanySettings::form()` into `Tabs`. Firma tab gains the Logo card;
`config/invoice.php` gains `logos_disk`; `README.md`'s first-run sequence gains
`php artisan storage:link`. Steuer tab gains Besteuerung + the Steuersätze
repeater. Bank tab gains Zahlungsziel. Nummernkreis tab in full, with the live
preview and the callout. Keys in `lang/de/company.php`. Tests: each tab's fields
save; the Steuersätze card is absent for a Kleinunternehmer; the preview does not
increment `next_value` (assert `drawn_count` is still 0 after loading the page);
lowering Startwert after a draw is a form error.

### Task 7 — readiness and the dashboard

`app/Company/{Readiness,ReadinessItem,Severity}.php`,
`app/Actions/CheckReadiness.php`, the Dashboard's „Erste Schritte" card. Test 8,
plus a render test that a company missing its Steuernummer shows it by name on
the dashboard and one missing only its logo does not read as blocked.

### Task 8 — the per-customer Zahlungsziel

Migration adding `payment_term` to `customers`, the field on `CustomerForm`, the
pair on `CustomerInfolist`, `Customer::effectivePaymentTerm()` falling back to the
company's. Test: a customer with no term takes the company's, one with a term
keeps it, and changing the company's does not change the customer's stored value.

### Task 9 — correct the record

Run the gate in order: `rector process` → `pint` → `phpstan analyse
--memory-limit=512M` → `pest`. Then:

- **`php artisan migrate` against the development database.** Four migrations
  land in this wave; the suite structurally cannot notice they are missing from
  `invoice`, and this has already shipped a broken `/admin` once.
- **`README.md`** — feature entries for the four settings tabs, the Steuersätze,
  the Zahlungsziel, the logo and the Nummernkreis, written as what a user can do;
  `storage:link` in the first-run sequence; the „Not built yet" paragraph trimmed
  to the document side only.
- **`CLAUDE.md`** — the „Current state" paragraph; the known gap about an
  incomplete identity block, which is now *checked* though not yet *enforced at
  issue*; a new gap noting the Bereitschaftsprüfung has no issue-time caller yet.
- **The specs** — system design §3.3 (per-customer Zahlungsziel now exists), §5
  (the range and the draw are built, the document is not), §6, §8.1, and decision
  rows in §15; tech-stack §6.2, §6.6 and §12 (the enum deviation).
- **`CONTEXT.md`** — only if a name moved. Nothing above moves one; the
  „Zahlungsbedingungen" correction is a mockup fix, not a glossary change.
- **The customers spec §8** — the deferred Zahlungsziel is no longer deferred.
- **`AGENTS.md`** — only through `.ai/guidelines/`, never directly.
- **Penpot** — rename the Bank board's card to „Zahlungsziel"; split the
  Bereitschaftsprüfung modal's list into blockers and warnings. Keep the tab in
  the foreground while this runs, and look at the render afterwards.

---

## Verification

1. **The gate** — Rector `[OK]`, Pint `PASS`, PHPStan `No errors`, Pest green,
   including the new `Concurrency` suite.
2. **The lock is real** — Task 5's deliberate red run, recorded in the commit
   message of that task.
3. **Rounding agrees with the mockup to the cent** — the `Rechnung – Neu`
   board's figures are a test case, so the calculator and the drawn design cannot
   drift apart silently.
4. **Look at the pages.** `docker compose up -d`, then
   `/admin/{company}/settings` on each of the four tabs and `/admin/{company}`
   with a deliberately incomplete company, side by side with the Penpot boards.
   The only intended differences are the missing E-Mail tab and the corrected
   „Zahlungsziel" heading.
5. **The development database serves them** — after `php artisan migrate`, no
   `Undefined table` on any settings tab.
