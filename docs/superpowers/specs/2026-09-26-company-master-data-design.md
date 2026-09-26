# Company Master Data and the Money Foundation — Design

Date: 2026-09-26
Status: Approved (brainstorming complete, ready for implementation planning)
Companion to: `2026-09-23-invoice-system-design.md` and
`2026-09-23-invoice-tech-stack-design.md`, which remain the authority on the
system as a whole. This document covers one wave of it.

## 1. Purpose

Everything the **Ausstellvorgang** needs, built before the first **Beleg** exists.

After this wave a **Firma** can be configured to the point where `IssueDocument`
would have all of its inputs: a **Nummernkreis** it can draw from under a lock,
**Steuersätze** to put on a **Position**, a **Zahlungsziel** to compute a
**Fälligkeitsdatum** from, an **Einheit** list carrying the UN/ECE codes the XML
requires, a money type that cannot be floated, the §6 rounding rule as a tested
unit, and a **Bereitschaftsprüfung** that says out loud what is still missing.

Success means three things. The owner can finish setting up a company without
guessing what is still required. The invoicing wave inherits a numbering
guarantee that has been proven under concurrency rather than asserted. And
nothing in this wave can issue anything — there is no **Beleg** here, on purpose.

This is also the wave that discharges a hazard `CLAUDE.md` records: a company
created through registration carries only a name and a legal form, its identity
block is incomplete, and issuing from it would not satisfy §14 UStG. Until now
nothing could tell. Now something can.

## 2. Carried in

Settled elsewhere and applied here, not revisited:

- UUID v7 primary keys; the UUID *is* the key (system design §3.1)
- Company-scoped screens live under `/admin/{company}/…` (§3.2)
- Tax rates are deactivated, never deleted (§3.4)
- Money is integer cents via `brick/money`; floats never touch money
  (tech stack §6.2, `CLAUDE.md`)
- Rounding is per **Steuersatz** group — not per **Position**, not at the end
  (system design §6, `CONTEXT.md`)
- One **Nummernkreis** per **Firma**, shared by every **Beleg** that is an
  invoice within the meaning of §14 UStG; the **Mahnung** has its own (§5)
- The number is drawn at **ausstellen**, under a lock, inside the transaction
  that either commits whole or consumes nothing (§5, §8.1)
- Numbering is written in this project rather than taken from a package
  (tech stack §6.6), and the class is `DrawNextNumber` (§11.3)
- PostgreSQL only, including in tests, because the guarantee rests on
  `SELECT … FOR UPDATE` (tech stack §11.2)
- Identifiers are English, labels German, in `lang/de/` (`CLAUDE.md`)
- One ⋮ dropdown per table row; a save-in-place page has one action, far right;
  show less rather than more (`.ai/guidelines/ui/core.blade.php`)

## 3. Data model

### 3.1 `tax_rates`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 via `HasUuids` |
| `company_id` | uuid, FK → `companies` | Required. The tenant. `restrictOnDelete`. |
| `rate` | integer | **Basis points.** 1900 is 19 %. |
| `name` | string | „Regelsatz", „Ermäßigter Satz", „Steuerfrei" |
| `is_default` | boolean | The rate a new **Position** starts with |
| `deactivated_at` | timestamp, nullable | Deactivation (§3.4 of the system design) |
| `created_at`, `updated_at` | timestamps | |

Indexes: the primary key, `unique(company_id, rate)`, and a **partial unique
index** `unique (company_id) where is_default`.

**Basis points, not `numeric(5,2)`.** The reason integer cents exist applies
here unchanged: an integer is a value a float cannot silently become. The repo's
own precedent is already "store the integer, format for display" — the customer
number is an `integer` rendered as `K-0004` for exactly this reason. A rate is
formatted to „19 %" on the way out and parsed from „19" on the way in, in one
place on the model, the way `Customer::formatNumber()` works.

**The partial unique index is the guarantee, not a hook.** „At most one default
per company" is a database property here. A `saving` hook that clears the other
rows is still written, because it is what makes the interface behave; the index
is what makes the interface's failure survivable. This mirrors the
`unique(company_id, number)` backstop on `customers`: the lock is the mechanism,
the index is the proof.

**`unique(company_id, rate)`** prevents two rows for 19 %, which would give the
picker two entries a user cannot tell apart.

A company is seeded with 19 % (default), 7 % and 0 % on creation. Seeding in
`Company::booted()` rather than in a seeder, because a company created through
`RegisterCompany` must get them too, and a `DatabaseSeeder` is not on that path.

**Seeded, while the Nummernkreis of §3.2 deliberately is not.** The two are not
the same kind of thing. The German rates are a fact about the law that every
company shares and none has to decide; a prefix and a starting value are a choice
only the owner can make, and a range invented on their behalf would make the
readiness check unable to say it is missing.

### 3.2 `number_ranges`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 |
| `company_id` | uuid, FK → `companies` | **Unique.** One range per company. |
| `prefix` | string, nullable | „RE-" — carries its own separator |
| `padding` | unsigned small integer | Default 4 |
| `next_value` | integer | Default 1. The number the next draw will take. |
| `include_year` | boolean | `RE-2026-0043` rather than `RE-0043` |
| `reset_yearly` | boolean | Counting restarts at 1 on 1 January |
| `last_reset_year` | integer, nullable | What the yearly reset compares against |
| `drawn_count` | integer | Default 0. How many numbers this range has issued. |
| `created_at`, `updated_at` | timestamps | |

**Its own table, not columns on `companies`.** Tech stack §7.4 holds the lock for
the whole PDF render — a second or two. Locking the `companies` row for that long
would block the settings page, the company switcher and every other write to the
company, for a lock that has nothing to do with them. A dedicated row is the
narrowest thing that can be locked.

**No row exists until the Nummernkreis tab is saved once.** This is what makes
the Bereitschaftsprüfung's „Noch nicht konfiguriert" a true statement rather than
a decorative one. A company auto-seeded with a default range would make that
check unable to fail, which §9 of this document forbids.

**`drawn_count` exists to make one guard possible**: `next_value` may be set
freely while nothing has been drawn, and may only ever be raised afterwards.
Without it there is no way to distinguish "migrating in, pick your start" from
"silently reissuing numbers that are already on a customer's invoice". It is not
a second source of truth about anything — no query derives a fact from it.

### 3.3 `companies`

Two columns are added:

| Column | Type | Notes |
|---|---|---|
| `payment_term` | string | Backed by `PaymentTerm`. Default `net_14`. |
| `logo_path` | string, nullable | Path on the `logos` disk |

### 3.4 `customers`

One column is added:

| Column | Type | Notes |
|---|---|---|
| `payment_term` | string, nullable | Null means "use the company's" |

This closes what the customers wave deferred: its §3.1 records „No default
payment term … a per-customer default arrives with them", and its §8 lists it as
out of scope. Payment terms now exist, so it arrives.

`Customer::effectivePaymentTerm()` resolves the fallback in one place. The stored
value is never backfilled from the company — changing the company's default must
not silently change what an existing customer is invoiced under.

### 3.5 `PaymentTerm`

A string-backed enum implementing `HasLabel`:

| Case | Value | Label | Days |
|---|---|---|---|
| `Immediate` | `immediate` | Sofort fällig | 0 |
| `Net7` | `net_7` | 7 Tage netto | 7 |
| `Net14` | `net_14` | 14 Tage netto | 14 |
| `Net30` | `net_30` | 30 Tage netto | 30 |
| `Net60` | `net_60` | 60 Tage netto | 60 |

It carries `days(): int` and `dueDateFrom(CarbonInterface $issuedOn):
CarbonImmutable`. The **Zahlungsziel** is a duration and the **Fälligkeitsdatum**
is a day (`CONTEXT.md`); this enum is the first, and `dueDateFrom()` is the only
place the second is computed from it.

### 3.6 `Unit`

A string-backed enum implementing `HasLabel`, **backed by the UN/ECE Rec 20 code
itself**:

| Case | Value | Label |
|---|---|---|
| `Piece` | `H87` | Stück |
| `Hour` | `HUR` | Stunde |
| `Day` | `DAY` | Tag |
| `LumpSum` | `LS` | Pauschal |
| `Kilometre` | `KMT` | km |

Backing the case with the code means a **Position** stores the code, the XML gets
it without a lookup, and there is no second place where „Stunde" and `HUR` could
drift apart.

### 3.7 Three lists, three shapes

`Steuersatz`, `Zahlungsziel` and `Einheit` all read like "a list to pick from",
and all three are modelled differently. That is deliberate, and it is the thing a
later reader is most likely to mistake for inconsistency, so it is also
`docs/adr/0003-stammdatenlisten.md`.

- **`Steuersatz` is a per-company table** because it is per-company data a user
  edits. System design §3.4 puts tax rates among the things that are deactivated
  rather than deleted, which only makes sense for rows.
- **`Zahlungsziel` is an enum** because nothing asks a user to invent one. The
  set of German payment terms is small and closed, and each is a duration plus a
  wording that prints verbatim.
- **`Einheit` is an enum** because system design §3.3 says the unit list is „a
  standard, not per-company data, and … not user-editable", and §3.6 says a
  **Position** „references no master data". A `units` table would cost a seeder,
  a UUID key and a foreign key for five immutable rows — and a Position holding
  that foreign key would contradict §3.6 outright.

This deviates from tech stack §12, which lists `PaymentTerm` and `Unit` under
`app/Models`. That list was written before §3.6 was read against it; the
deviation is recorded there.

## 4. Money and the rounding of §6

`App\Casts\MoneyCast` casts a `bigint` of cents to a `Brick\Money\Money` in EUR
and back. No column uses it yet — no **Beleg** exists. It is written here so that
the invoicing wave inherits it rather than writing it under time pressure, and
so that the column type is fixed before anything depends on it.

The rounding rule is a pure unit with no Eloquent in it:

```
LineInput         quantity (BigDecimal), unitPrice (Money), rate (int basis points)
TaxGroup          rate, base (Money), tax (Money)
Totals            net (Money), groups (list<TaxGroup>), tax (Money), gross (Money)
CalculateTotals   __invoke(list<LineInput>): Totals
```

`CalculateTotals` performs §6 in its stated order:

1. Each line's net is quantity × unit price, rounded to the cent (HALF_UP).
2. Lines are grouped by rate; each group's **Bemessungsgrundlage** is the sum of
   its line nets.
3. Each group's **Umsatzsteuer** is base × rate, **rounded once**.
4. Totals are the sums of the group figures.

**Rounding happens per group.** Not per line, not at the end. This is the whole
reason the calculator exists as its own unit rather than as a method on a future
`Document`: it is table-testable against values derived by hand from EN16931,
and the case where per-line and per-group rounding differ by a cent is the only
test that can catch the rule being applied in the wrong place.

**Kleinunternehmer is not a branch in the calculator.** A §19 company's
**Positionen** simply carry 0 %. `Company::selectableTaxRates()` is the single
place that decides: for `VatScheme::SmallBusiness` it returns one non-editable
0 % rate, and the Steuersätze card is hidden with a note saying why. Putting the
scheme inside the arithmetic would give the same input two answers depending on
who asked.

## 5. Drawing a number

`App\Actions\DrawNextNumber::__invoke(Company $company): string`:

1. **Refuse to run outside a transaction.** `DB::transactionLevel() === 0` throws.
2. Lock the company's range row: `lockForUpdate()->firstOrFail()`.
3. If `reset_yearly` and `last_reset_year` is below the current year, set
   `next_value` to 1 and `last_reset_year` to the current year.
4. Format: `prefix` · (`include_year` ? year and `-` : nothing) ·
   `next_value` padded to `padding`. `RE-` + `2026-` + `0043` is `RE-2026-0043`;
   without the year it is `RE-0043`.
5. Increment `next_value` and `drawn_count`, save, return the string.

**Step 1 is not defensive programming; it is the guarantee.** Outside a
transaction the `FOR UPDATE` lock is released the moment the statement returns,
so two concurrent draws can both read the same `next_value` and the sequence is
not gapless — while every single-threaded test still passes. The condition is
cheap to check and impossible to notice the absence of, which is exactly the
shape of thing that has to be enforced rather than documented.

A range with no row is a programming error at this point, not a user error: the
**Bereitschaftsprüfung** refuses before the transaction opens (§6), so
`firstOrFail()` is the right failure.

**`next_value` may never be lowered once `drawn_count` is above zero.** Enforced
twice, the way the customer number already is: a validation rule on the settings
form, so the owner sees a message, and a guard on the model so a path that
bypasses the form still cannot do it.

> **Corrected during implementation.** This section first put the model guard in
> a `NumberRange::reconfigure()` method — the single door the settings page was
> to write through. It is an `updating` hook instead, because a guard on one
> named method protects only the callers who remember to use it, while the hook
> covers a console command, a future import and a plain `fill()->save()` as
> well. The yearly reset is the one writer that legitimately lowers the value,
> and it is told apart by `drawn_count`, which every draw increments and nothing
> else touches — cheaper and harder to forget than a flag the draw has to
> remember to set.

Raising it stays allowed, always: a company migrating in continues from where its
previous system stopped, which system design §5 requires.

## 6. The Bereitschaftsprüfung

`App\Actions\CheckReadiness::__invoke(Company $company): Readiness`.

`Readiness` is readonly and carries a list of `ReadinessItem` — a key, whether it
is satisfied, and a `Severity` — plus `canIssue(): bool`, which is true when no
**blocking** item is unsatisfied.

| Item | Severity | Satisfied when |
|---|---|---|
| `address` | blocking | `street`, `postal_code` and `city` all present |
| `tax_identifier` | blocking | `tax_number` **or** `vat_id` present |
| `register` | blocking, only when `legal_form->isRegistered()` | `register_court`, `register_number` and `managing_directors` present |
| `number_range` | blocking | a `number_ranges` row exists |
| `bank` | warning | `iban` present |
| `logo` | warning | `logo_path` present |

**Only what the law requires blocks.** The blocking items are what §14 UStG
demands on the document (the supplier's full name and address, and a Steuernummer
or USt-IdNr.), what §35a GmbHG demands of a registered legal form, and the number
range without which §14 Abs. 4 Nr. 2 cannot be satisfied at all. The bank details
and the logo are neither: a **Rechnung** without an IBAN is legally valid and
merely inconvenient, and a **Rechnung** without a logo is legally valid and looks
plainer. A missing logo must never stop an invoice.

System design §8.1 lists the check's items as „number range, tax number, bank
details, logo" — four items with no severity and no address. The address is added
because §14 requires it and its absence is precisely the hazard `CLAUDE.md`
records; the register entry is added for the same reason; and the two that are
not legally required become warnings. §8.1 is corrected rather than followed.

**This wave has exactly one consumer: the dashboard.** The gate inside
`IssueDocument` and the modal in front of it belong to the invoicing wave. That
is the reason the check is an object with a stable answer rather than a method on
the settings page — the wave that needs it most is not this one.

## 7. Screens

### 7.1 Settings become four tabs — `/admin/{company}/settings`

**The page is relabelled „Einstellungen".** It was „Firmendaten", which named
the data rather than the page and stopped being accurate the moment the page
grew a Nummernkreis and a Zahlungsziel. The mockups already called it
Einstellungen. The old word keeps its job on the dashboard, where „Firmendaten
vervollständigen" does mean the data.

**One saving action, at the far right.** Filament's default is
`Alignment::Start`, so this has to be said rather than assumed —
`getFormActionsAlignment()` returns `Alignment::End`. `SeparatesFormActions` is
the wrong tool: it exists to put distance between cancel and save, and this page
has no cancel.

The mockups show one „Einstellungen" heading, one tab bar and one „Speichern"
bottom-right. That is one page, not four, so `CompanySettings` keeps its route,
its label, its `EditTenantProfile` base and its single save action at the far
right (`.ai/guidelines/ui/core.blade.php`: a page that saves in place has nothing
to separate). Its schema becomes a `Tabs`.

**Firma** — the existing identity, address, register and management sections,
unchanged, plus a **Logo** card: an image upload on the `logos` disk, with the
note that replacing it does not change already issued PDFs.

**Steuer** — three cards:
- *Besteuerung*: `vat_scheme` as two toggle buttons, with the §19 consequence
  spelled out beneath.
- *Steuernummern*: the existing `tax_number` / `vat_id` pair, each required
  without the other.
- *Steuersätze*: a table of Satz, Bezeichnung and a Standard marker, with an add
  action and a ⋮ per row. **Hidden entirely for a Kleinunternehmer**, replaced by
  a note saying documents are issued at 0 % with the prescribed §19 hint.

**Bank** — the existing bank section, plus a **Zahlungsziel** select, described
as the default for new customers and new invoices.

> The mockup heads that second card „Zahlungsbedingungen". `CONTEXT.md` puts
> *Zahlungsbedingung* on the _Vermeiden_ list for **Zahlungsziel**. The card is
> headed „Zahlungsziel", and the Penpot board is corrected to match.

**Nummernkreis** — Präfix, Stellen and Startwert; toggles for „Jahr in der Nummer
führen" and „Jährlich zurücksetzen"; a **Nächste Nummer** preview; and the
mockup's warning that the sequence is gapless, that the number is drawn under a
lock at **ausstellen** and rolled back on failure, and that numbers already
assigned never change.

The description says the prefix prints on **every** document drawn from the
range — a **Storno** and a **Gutschrift** included. System design §5's correction
of 2026-09-26 requires the page to say so, because a company that picks `RE-`
will otherwise be surprised to find it on documents that are not Rechnungen.

**The preview reads without consuming.** It formats `next_value` and does not
draw. This is worth stating because a preview implemented by calling
`DrawNextNumber` would look identical and would burn a number on every page load.

**E-Mail is not built.** SMTP credentials and per-document-type templates belong
to the wave that sends. The mockup keeps its fifth tab as the target state — a
deliberate, recorded divergence.

### 7.2 Dashboard — `/admin/{company}`

The single empty state becomes the mockup's **Erste Schritte** card:

1. **Firmendaten vervollständigen**, driven by `CheckReadiness`: a tick when no
   blocker is unsatisfied, otherwise the missing items named, with a link to the
   tab that fixes each. Warnings appear as a quieter line and never as a blocker.
2. **Ersten Kunden anlegen**, linking to the customer create page.
3. **Erste Rechnung schreiben**, disabled with a tooltip — there is no invoice
   route yet.

### 7.3 Customer form and detail

The create/edit form's *Rechnungsstellung* section gains a **Zahlungsziel**
select whose empty option reads „Vorgabe der Firma (…)" with the company's
current default named. The detail page's Stammdaten card gains the
**Zahlungsziel** pair the customer plan of 2026-09-26 deliberately left out and
recorded as a divergence; that divergence is now closed.

## 8. Error handling

| Situation | Behaviour |
|---|---|
| `DrawNextNumber` called outside a transaction | `LogicException`. It is a programming error, not a user one. |
| `DrawNextNumber` called with no range row | `ModelNotFoundException`; the readiness check is what prevents reaching it |
| Startwert lowered after a number has been drawn | Validation message on the field; the model guard refuses even without the form |
| Two concurrent draws | The row lock serializes them; distinct, consecutive numbers, no gap |
| A transaction containing a draw rolls back | The number is not consumed; the next draw returns the same string |
| A second default tax rate is written directly | Unique violation from the partial index |
| Two tax rates with the same rate | Unique violation from `(company_id, rate)` |
| Company not ready to issue | `canIssue()` is false and the blockers are listed; nothing to refuse yet, because nothing issues |
| A Kleinunternehmer's tax rates | The card is hidden; `selectableTaxRates()` returns a single 0 % rate |

## 9. Testing

Each test names what makes it fail.

**The concurrency test cannot live in `tests/Feature`.** `RefreshDatabase` holds
an open transaction for the duration of a test, and a `pcntl_fork()`ed child
receives a copy of the parent's PDO socket: it cannot see the parent's
uncommitted rows, and both processes writing through one connection corrupts it.
A `tests/Concurrency/` directory with its own `phpunit.xml` testsuite and a
`DatabaseTruncation` binding, with each child purging and reconnecting after the
fork, is what makes the test possible at all.

1. **Two forked processes drawing from one range receive distinct, consecutive
   numbers.** Fails the moment `lockForUpdate()` is removed — both read the same
   `next_value` and return the same string. Nothing else in the suite would
   notice, which is the entire reason for the directory above. Tech stack §11.3
   names this the most awkward test in the suite and the one that actually
   protects the guarantee.
2. **A rolled-back transaction consumes no number.** Draw inside a transaction,
   roll it back, draw again: the same string. This is §5's actual promise, and it
   is what a test asserting only that two sequential draws differ would miss.
3. **Drawing outside a transaction throws.** Fails if the guard in §5 step 1 is
   dropped — and every happy-path test would stay green, because a
   single-threaded draw without a lock returns the right answer.
4. **Rounding, table-driven, with expected values written by hand from the
   EN16931 rules** and not captured from what the code produces:
   - The `Rechnung – Neu` mockup's own invoice: 12 × 95,00 and 6 × 95,00 at 19 %,
     1 × 290,00 at 7 % → net 2 000,00; 19 % on 1 710,00 → 324,90; 7 % on 290,00 →
     20,30; gross 2 345,20. Tying the test to the drawn design keeps the
     arithmetic and the mockup from drifting apart silently.
   - A fractional quantity.
   - 0 % for a Kleinunternehmer: one group, no tax, gross equals net.
   - **The case where per-line and per-group rounding differ by a cent**: three
     lines of 0,83 at 19 % give 0,48 rounded per line and 0,47 rounded per group.
     This is the only test that can detect the rule applied in the wrong place.
5. **`MoneyCast` round-trips cents and refuses a float.** No money column
   exists yet (§10), so the cast is tested directly: a stored `199900` comes back
   as a `Money` of 1 999,00 EUR and returns `199900` on the way in, and handing it
   a float raises rather than truncating. A test that only asserted the happy
   round-trip would pass against a cast that quietly accepts `19.99`.
6. **The partial unique index exists.** A second `is_default` row written
   straight to the table raises a unique violation. No happy path notices a
   missing index, and the hook that clears the others would hide it.
7. **`next_value` cannot be lowered after a draw, and can be before one.** One
   direction alone cannot tell "guarded" from "always refused".
8. **The Bereitschaftsprüfung blocks on law and warns on the rest.** A company
   with no IBAN and no logo can issue; one missing its Steuernummer cannot; a
   GmbH missing its Registernummer cannot, and an Einzelunternehmen with those
   same fields empty can. The last pair is what distinguishes a conditional
   blocker from an unconditional one.
9. **Tenancy.** Company B's tax rates and number range never appear under A, and
   a draw under B leaves A's `next_value` untouched.
10. **UUID v7 on `tax_rates` and `number_ranges`**, joining `UuidKeysTest`.
11. **The Nächste-Nummer preview does not consume.** `drawn_count` is still zero
    after the settings page has been rendered. Fails against a preview
    implemented by calling `DrawNextNumber`.
12. **A per-customer Zahlungsziel overrides and does not backfill.** A customer
    with none takes the company's; one with its own keeps it; changing the
    company's default leaves the stored value alone.

**Deliberately not written:** that a migration has particular columns; that a
settings tab returns 200 without asserting what it shows; anything asserting the
query log contains `for update`, which proves nothing (tech stack §11.3).

PostgreSQL throughout, per the standing rule.

## 10. Out of scope

- Every **Beleg**: `Document`, `LineItem`, `Payment`, issuing, PDFs, ZUGFeRD XML.
  `tightenco/parental` stays unused for one more wave.
- **Gutschrift** and **Vermittler**. ADR 0002's list — a `Referrer` with its own
  tax number and bank details, the `widersprochen` state, UNTDID 389, the
  inverted Identitätsblock — is none of it Firmenstammdaten, and it belongs after
  **Rechnung**, **Storno** and **Teilstorno** rather than before them. What this
  wave owes it is that the **Nummernkreis** stays belegartneutral: it formats a
  number and knows nothing about what kind of document will carry it.
- **The KoSIT / Mustang validator job.** There is no XML to validate. Tech stack
  §11.4 runs it against a fixed set of generated documents and none can be
  generated; a CI job with no fixtures would stay green while proving nothing,
  which is the decoration `CLAUDE.md` forbids. It ships with the first ZUGFeRD
  file.
- **E-Mail settings**: SMTP credentials, per-document-type templates, the BCC.
- **The issue-time modal.** The Bereitschaftsprüfung exists; the dialogue in
  front of `IssueDocument` does not, because `IssueDocument` does not.
- A **Mahnung** number range. It is separate by §5 and arrives with v2.
- Any use of `MoneyCast` on a real column, because there is no money column yet.

## 11. Documents this wave falsifies or owes

Per `.ai/guidelines/documentation/core.blade.php`, when the implementation lands:

- **`README.md`** — feature entries under „What it does today" for the four
  settings tabs, the Steuersätze, the Zahlungsziel, the logo and the
  Nummernkreis, described as what a user can do; `php artisan storage:link` added
  to the first-run sequence; the „Not built yet" paragraph trimmed to the
  document side only, since gapless numbering now partly exists.
- **`CLAUDE.md`** — the „Current state" paragraph; the known gap about an
  incomplete identity block, which is now **checked** although not yet **enforced
  at issue**; and a new gap recording that the Bereitschaftsprüfung has no
  issue-time caller.
- **`docs/superpowers/specs/2026-09-23-invoice-system-design.md`** — §3.3 (the
  per-customer Zahlungsziel now exists), §5 (the range and the draw are built,
  the document is not), §6 (where the rounding rule now lives), §8.1 (the
  readiness check's real item list and its two severities), and decision rows in
  §15.
- **`docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md`** — §6.2
  (the cast exists), §6.6 (the action exists) and §12 (`PaymentTerm` and `Unit`
  are enums, not models).
- **`docs/superpowers/specs/2026-09-25-customers-design.md`** — §8's deferred
  payment term is no longer deferred.
- **`docs/adr/0003-stammdatenlisten.md`** — new; §3.7 of this document.
- **`CONTEXT.md`** — no change. Nothing here moves a name; the
  „Zahlungsbedingungen" correction is a mockup fix, not a glossary change.
- **`AGENTS.md`** — only if a guideline changes; never edited directly.
- **The Penpot mockups** — the Bank board's card heading, and the
  Bereitschaftsprüfung modal, which lists as equals four items this design splits
  into blockers and warnings.
- **The development database** — the wave adds four migrations, so
  `php artisan migrate` must run against `invoice` before any settings tab loads.
  The suite cannot notice.

This spec is dated and is not itself updated when the stack later moves.

## 12. Decisions made during this design

| Decision | Choice |
|---|---|
| Gutschrift and Vermittler | Deferred to their own wave, after Storno and Teilstorno |
| Frozen PDFs and logos | Separate disks; `documents_disk` stays unused, `logos_disk` is added |
| KoSIT validator job | Deferred to the wave that produces the first XML |
| What the readiness check blocks | Only what §14 UStG and §35a GmbHG require; bank details and logo warn |
| Address in the readiness check | Added; system design §8.1 omitted it |
| Tax rates | A per-company table, basis points, one default enforced by a partial unique index |
| Payment terms | An enum, not a table; the mockup offers no way to add one |
| Units | An enum backed by the UN/ECE code, not a table; a Position references no master data |
| Number range storage | Its own table, because the lock is held for the length of a PDF render |
| Number range creation | No row until the settings tab is saved, so „not configured" can be true |
| Startwert | Freely settable until the first draw, raise-only afterwards; guarded in the form and by a model `updating` hook, not by one named method |
| Drawing outside a transaction | Refused, because the lock would otherwise be released immediately |
| Kleinunternehmer | Decided in `selectableTaxRates()`, never inside the rounding |
| Rounding | Per Steuersatz group, as a pure unit with no Eloquent, tested against hand-derived values |
| Settings layout | One page with four tabs, not four pages; one save action, far right |
| „Zahlungsbedingungen" | Renamed to „Zahlungsziel" in code and in the mockup, per `CONTEXT.md` |
| E-Mail settings | Out; they belong to the wave that sends |
| Per-customer Zahlungsziel | Included; it is what the customers wave deferred until payment terms existed |
