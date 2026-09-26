# Rechnungsentwürfe — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Spec to write first:** `docs/superpowers/specs/2026-09-27-invoice-drafts-design.md`.
Vocabulary: `CONTEXT.md`. UI rules: `.ai/guidelines/ui/core.blade.php`.
Mockups: the Penpot boards `Rechnungen – Liste`, `Rechnungen – leer (Erstnutzung)`,
`Rechnung – Neu (Entwurf)`, `Rechnung – Entwurf (Detail)`.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5, PostgreSQL 17. All commands through `docker compose run --rm app …`.

**Goal:** a **Rechnung** can be drafted, listed, read, corrected and thrown away.
Nothing is issued.

---

## Context

The master-data wave left every input a draft needs sitting unused: `Customer`,
`Company::selectableTaxRates()`, the `Unit` and `PaymentTerm` enums,
`MoneyCast`, and `CalculateTotals` — which is already tested against the exact
figures the `Rechnung – Neu` mockup prints. `tightenco/parental` has been
installed and unused since the first wave. Three seams on the customer page
(`customer.actions.new_invoice`, `customer.invoices.*`, `customer.stats.*`)
were built disabled, waiting for this.

This wave connects them and stops at the **Entwurf**. That cut is the mockups'
own: `invoice-ui-decisions` records that creating an invoice never issues it,
and the create page's only primary action is `Entwurf speichern`.

**After this wave you still cannot issue an invoice.** The deliverable is that
drafts can be typed, listed, read, corrected and deleted. The README entry has
to say so plainly, the way the customer tiles say `0,00 €` for now. It also does
not de-risk the wave after it: issuing is still number draw + Festschreibung +
totals + PDF + ZUGFeRD + validation + audit in one transaction, calling
`CheckReadiness` first. Splitting drafts off separates that work; it does not
shrink it.

### Decisions taken before planning

| Question | Decision |
|---|---|
| What addresses a document in a URL | **The UUID, always** — `/admin/{company}/invoices/{uuid}`, for the life of the document. One resolution path, no special-casing, and the URL never changes when the draft is issued. The cost is accepted: unlike `K-0004`, the Belegnummer never appears in a URL. So no `#[RouteKey]` override and no `resolveRouteBindingQuery()` — the opposite of `Customer`. |
| The Verlauf card | **One derived line**, „Erstellt" from `created_at`, the way the customer page shows „Kunde seit". No `AuditEntry` model. §3.8 wants entries written by the transaction that does the work, and that work — issue, send, pay — is all next wave. A draft is freely editable and not part of the GoBD record. |
| Deleting | **Drafts only.** §3.4 makes them the explicit exception: „they never received a number and can be deleted outright." The guard refuses anything that is not a draft, and is testable now against a factory-made issued row. |

### Two naming points to settle in the spec

1. **`LineItem`, not `DocumentLine`.** `CONTEXT.md` gives **Position** → `LineItem`;
   tech-stack spec §12 lists `DocumentLine`. `CLAUDE.md` makes `CONTEXT.md` the
   authority on what a domain concept is called, so §12 is corrected.
2. **The draft detail board says „Nummer, Beleg und PDF entstehen erst beim
   Ausstellen."** `CONTEXT.md` settled that the frozen identity block is the
   **Festschreibung** and that „Beleg" is the umbrella term — this is the exact
   misuse the glossary records as already corrected once. The copy reads
   „Nummer, Festschreibung und PDF", and the Penpot board is corrected to match.

---

## Design

### What the table carries now, and what waits

**Structure that constrains goes in now; data the issue step computes waits.**
An index and a discriminator are awkward to add to a populated table and cheap
to add to an empty one. Three nullable columns nobody writes are the speculative
structure `CLAUDE.md` warns about, and adding them later is one migration.

So `documents` gets `type`, `status`, and `number` **with its
`unique(company_id, number)` backstop** — the index numbering will rely on — but
**not** `due_on`, stored totals, `frozen_block`, the PDF path or its SHA-256.
Those arrive with the step that fills them.

### `documents`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 |
| `company_id` | uuid FK → `companies` | `restrictOnDelete`. The tenant. |
| `customer_id` | uuid FK → `customers` | `restrictOnDelete` — a customer a document names must never vanish |
| `type` | string | Parental's discriminator. `invoice` is the only value this wave can write. |
| `status` | string | `DocumentStatus`; only `draft` is reachable |
| `number` | integer, nullable | Null until issued |
| `issued_on` | date | The **Ausstellungsdatum**, labelled „Rechnungsdatum" on a Rechnung |
| `performed_on` | date, nullable | **Leistungsdatum** — ZUGFeRD BT-72 |
| `performed_from`, `performed_to` | date, nullable | **Leistungszeitraum** — BG-14 |
| `payment_term` | string | `PaymentTerm`, copied from the customer at creation |
| `created_at`, `updated_at` | timestamps | |

Indexes: the primary key, `unique(company_id, number)`, and
`index(company_id, issued_on)` for the list's default sort.

**`issued_on` is editable on a draft and is a proposal until the document is
issued.** The mockup's create form shows it as „Rechnungsdatum", defaulting to
today. The name stays `issued_on` because `CONTEXT.md` fixes it.

**Exactly one of Leistungsdatum and Leistungszeitraum, never both** (§7's
correction: BT-72 and BG-14 are different structures and a field that holds
both maps to neither). Enforced by a model guard, not only by the form.

### `line_items`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 |
| `document_id` | uuid FK → `documents` | `cascadeOnDelete` — a draft's Positionen go with it |
| `position` | integer | 1-based, the printed Pos. |
| `title` | string | |
| `description` | text, nullable | |
| `quantity` | decimal(12,3) | Fractional quantities are a real case (`CalculateTotalsTest`) |
| `unit` | string | The `Unit` enum's UN/ECE code |
| `unit_price` | bigint | Cents, through `MoneyCast` |
| `tax_rate` | integer | Basis points — the **value**, not a foreign key |

`unique(document_id, position)`.

**A Position references no master data** (§3.6). `unit` holds the code and
`tax_rate` the basis points, copied from the pickers at the moment of typing.
A later rename or deactivation of a `TaxRate` row cannot reach backwards into a
document — which is what §4's immutability needs and what a foreign key would
break.

### `Document`, `Invoice`, `DocumentStatus`

`Document` is the base model on `documents`; `Invoice` is a `tightenco/parental`
child on `type = invoice`. One child now, because the table shape is the
expensive half and the second type should be a class rather than a migration
(tech-stack §6.5). `Invoice::query()` scopes itself; `Document::query()` returns
every type as its proper class.

`DocumentStatus`: `draft`, `issued`, `sent`, `paid`, `cancelled` — a
string-backed `HasLabel`/`HasColor` enum with `fromFormState()` and
`isDraft()`, following `CustomerType` exactly. Only `draft` is reachable.

**Immutability, now.** `Document::save()` refuses an update when the stored
status is not `draft`, and `LineItem` refuses any write whose document is not a
draft. Nothing can legitimately reach that state yet, and that is exactly why it
is worth writing: a factory can create an `issued` row directly, so the guard is
testable before the operation that would trip it exists.

### Totals

Computed, never stored, for a draft: `Invoice::totals(): Totals` maps its
`lineItems` onto `LineInput` and calls `CalculateTotals`. §6 stores totals *at
issue*; until then the lines are the truth and a stored copy could disagree with
them after an edit.

### Screens — `/admin/{company}/invoices`

A **„Rechnungen"** sidebar entry with a document icon, `$navigationSort = 2`,
between Kunden (1) and Einstellungen (which moves to 3).

**List.** Columns: **Nummer** (`—` for a draft), **Status** (badge), **Kunde**,
**Datum**, **Fällig** (`—`), **Betrag**. Sorted by `issued_on` descending.
Search over customer name and title. `->with('lineItems')` so the Betrag column
does not go N+1. **No status filter yet** — every document is a draft, and a
filter with one option is furniture; it arrives with the statuses.
Empty state: „Noch keine Rechnungen." / „Rechnungen entstehen als Entwurf und
bekommen ihre Nummer erst beim Ausstellen." / **Neue Rechnung**.
Row ⋮: Öffnen, Bearbeiten, Löschen.

**Create / edit.** Two cards, following `CustomerForm`'s shape:
- *Kopfdaten* — Kunde (searchable select, **active customers only**, plus the
  already-selected one when editing so an old draft does not lose its customer),
  Rechnungsdatum, Zahlungsziel (defaulting to the customer's
  `effectivePaymentTerm()`), and the Leistung pair.
- *Positionen* — a `Repeater` with `->table()`, the same API the Steuersätze
  card uses: Bezeichnung, Beschreibung, Menge, Einheit, Einzelpreis, Steuer,
  Netto. Tax options come from `Company::selectableTaxRates()`, so a
  Kleinunternehmer is offered 0 % and nothing else. A live totals block under
  the rows.

Primary action **Entwurf speichern**; cancel far left, save far right via
`SeparatesFormActions`.

**Detail.** Two columns, unlike the customer page — this is what the board
draws for a document:
- Left: *Beleg* card (Rechnungsempfänger block with Kundennr., Nummer `—`,
  Rechnungsdatum, Leistung, Zahlungsziel, and the „Entwurf – frei änderbar"
  note) and *Positionen* with the VAT summary and Gesamtbetrag.
- Right: *Status* card (badge, Betrag, „14 Tage ab Ausstellung") and *Verlauf*
  with the single derived „Erstellt" line.
- Header: **Bearbeiten**, **Rechnung ausstellen** rendered **disabled with a
  tooltip** — the precedent the customer page's „Neue Rechnung" set — and a ⋮
  holding Löschen.

### The customer page's three seams

`customer.actions.new_invoice` becomes a live link to the create page with the
customer preselected, and the invoices card lists that customer's drafts. **The
three stat tiles stay at `0,00 €`**: they count issued revenue and open
receivables, and nothing is issued.

*This is the one piece of scope beyond „invoices create/list/show/edit". It is
here because it removes a disabled placeholder this wave makes reachable; say so
now if it should wait.*

### Testing — what would make each fail

1. **A draft is created in the current company, for a customer of that company.**
   Beta has customers first, so an unscoped query resolves to the wrong one.
2. **A customer of another company cannot be selected**, and a deactivated one
   is absent from the picker — but an already-selected deactivated customer
   still shows when editing. One direction alone cannot tell „filtered" from
   „dropped".
3. **Positionen round-trip**, and the page's totals equal the mockup's:
   1 710,00 @ 19 % → 324,90, 290,00 @ 7 % → 20,30, gross 2 345,20. Same figures
   as `CalculateTotalsTest`, so the screen and the arithmetic cannot drift.
4. **A Kleinunternehmer is offered 0 % and nothing else.**
5. **Leistungsdatum or Leistungszeitraum, never both** — saving a period clears
   the date and the reverse; a row with both set is refused by the model.
6. **An issued document refuses an edit**, and a draft accepts one. Built with a
   factory `issued()` state, because nothing can issue yet.
7. **An issued document refuses deletion**, a draft allows it, and deleting a
   draft takes its Positionen with it.
8. **A document is addressed by UUID**, and a URL that is not one is a 404.
9. **Tenancy**: company B's drafts never appear under A, and a row action on B's
   draft from A's list raises `ActionNotResolvableException`.
10. **The list does not go N+1** over line items — asserted by query count, not
    by eye.
11. **UUID v7 on both tables**, joining `UuidKeysTest`.

Not written: that a migration has particular columns; that a page returns 200
without asserting whose data it shows.

---

## Work

### Step 0 — branch and spec

`git checkout -b feat/invoice-drafts`, write
`docs/superpowers/specs/2026-09-27-invoice-drafts-design.md` in the shape of the
customers spec (Purpose · Carried in · Data model · Screens · Error handling ·
Testing · Out of scope · Documents this wave falsifies · Decisions), copy this
plan beside it, commit, and **stop for review** before any code.

### Task 1 — the tables and the models

Migrations for `documents` and `line_items`; `DocumentStatus`;
`app/Models/{Document,Invoice,LineItem}.php` with parental; `Company::documents()`;
factories including an `issued()` state. Tests 8 and 11, plus the parental
round-trip (`Document::query()` returns an `Invoice`).

### Task 2 — the guards

The one-Leistung rule, the issued-is-immutable rule and the delete rule, on the
models. Tests 5, 6 and 7 — all before any screen exists.

### Task 3 — totals on the model

`Invoice::totals()` over `CalculateTotals`, and `lineItems()`. Test 3's
arithmetic half, without a page.

### Task 4 — list and empty state

`app/Filament/Resources/Invoices/` mirroring `Customers/`: resource, `Tables/`,
`Pages/ListInvoices`. The sidebar entry; Einstellungen moves to sort 3.
`lang/de/invoice.php`. Tests 9 and 10.

### Task 5 — create

`Schemas/InvoiceForm`, `Pages/CreateInvoice` with `handleRecordCreation()`
associating the tenant before save — the ordering trap `CreateCustomer`
documents. Tests 1, 2, 3 and 4.

### Task 6 — detail

`Schemas/InvoiceInfolist`, `Pages/ViewInvoice`, `Actions/InvoiceActions`
(delete; ausstellen disabled with a tooltip).

### Task 7 — edit and delete

`Pages/EditInvoice`, the ⋮ delete action on list and detail.

### Task 8 — the customer page's seams

Live „Neue Rechnung" link and the customer's draft list. Tiles stay at zero.

### Task 9 — correct the record

Gate in order: `rector process` → `pint` → `phpstan analyse --memory-limit=512M`
→ `pest`. Then:

- **`php artisan migrate` against the development database** — two migrations;
  the suite cannot notice they are missing from `invoice`.
- **`README.md`** — a feature entry saying plainly that drafts can be written
  and that issuing is the next phase; remove the matching line from „Not built
  yet".
- **`CLAUDE.md`** — the „Current state" paragraph; the gap that
  `CheckReadiness` still has no issue-time caller.
- **The specs** — system design §3.5 (four types, one built), §3.6 (`LineItem`),
  §4 (immutability is enforced, freezing is not built), §15 rows; tech-stack §12
  (`LineItem`, not `DocumentLine`).
- **`CONTEXT.md`** — no change expected; nothing here moves a name.
- **Penpot** — the „Nummer, Beleg und PDF" copy on the draft detail board.

---

## Verification

1. **The gate** — Rector, Pint, PHPStan and Pest green, Concurrency suite
   included.
2. **The arithmetic matches the drawing** — the mockup's invoice is a test case
   in both `CalculateTotalsTest` and the form test.
3. **Look at the pages.** `/admin/{company}/invoices` empty and populated, the
   create form, and a draft's detail page, side by side with the four Penpot
   boards. The intended differences are the disabled „Rechnung ausstellen", the
   absent status filter, and `—` in Nummer and Fällig.
4. **The development database serves them** after `php artisan migrate`.
