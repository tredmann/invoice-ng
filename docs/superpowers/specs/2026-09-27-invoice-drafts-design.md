# Invoice Drafts — Design

Date: 2026-09-27
Status: Approved (brainstorming complete, ready for implementation planning)
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole. This document covers one wave of it.

## 1. Purpose

The first **Beleg**. A **Rechnung** can be drafted, listed, read, corrected and
thrown away.

**Nothing is issued.** No **Belegnummer** is drawn, no **Festschreibung** is
made, no PDF and no ZUGFeRD XML is produced. That is the next wave, and this one
is deliberately cut short of it.

Success means three things. The owner can type a real invoice for a real
customer and see the figures the document would carry. The **Entwurf** is
freely editable and freely deletable, exactly as §3.4 and §4 say it should be.
And the wave that issues arrives at a `Document` it can freeze, rather than
inventing one in the same breath as the numbering, the PDF and the validator.

This is also the wave that spends the master-data wave's output. Until now
`Customer`, `Company::selectableTaxRates()`, `Unit`, `PaymentTerm`, `MoneyCast`
and `CalculateTotals` were each tested in isolation and used by nothing.

## 2. Carried in

Settled elsewhere and applied here, not revisited:

- UUID v7 primary keys; the UUID *is* the key (system design §3.1)
- Company-scoped screens live under `/admin/{company}/…` (§3.2)
- One `documents` table, type-specific behaviour in distinct classes (§3.5)
- A **Position** carries its own values and references no master data (§3.6)
- A draft is freely editable and carries no number; after issue a document and
  its Positionen are immutable, enforced at the model layer (§4)
- Money is integer cents via `brick/money`; the rounding of §6 is
  `App\Actions\CalculateTotals`
- A **Beleg** carries either a **Leistungsdatum** or a **Leistungszeitraum**,
  never both — EN16931 maps them to BT-72 and BG-14 (§7, corrected 2026-09-26)
- Creating an invoice never issues it; the create page's only primary action is
  „Entwurf speichern" (the `invoice-ui-decisions` memory)
- Only active customers may be chosen in a picker (customers spec §3.5, which
  parked the rule for this wave)
- Identifiers are English, labels German, in `lang/de/` (`CLAUDE.md`)
- One ⋮ dropdown per table row; cancel far left and save far right on a form
  that navigates away (`.ai/guidelines/ui/core.blade.php`)

## 3. Data model

### 3.1 What the table carries now, and what waits

**Structure that constrains goes in now; data the issue step computes waits.**

A discriminator and a unique index are awkward to add to a populated table and
free to add to an empty one, so `type`, `status` and `number` — with the
`unique(company_id, number)` backstop the numbering will rest on — are here from
the start. `due_on`, the stored totals, the **Festschreibung**, the PDF path and
its SHA-256 are not: they are data that the act of issuing computes, adding them
later is one migration, and nullable columns nobody writes are the speculative
structure `CLAUDE.md` warns against.

### 3.2 `documents`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 via `HasUuids` |
| `company_id` | uuid, FK → `companies` | Required. The tenant. `restrictOnDelete`. |
| `customer_id` | uuid, FK → `customers` | Required. `restrictOnDelete`. |
| `type` | string | Parental's discriminator. Only `invoice` is writable here. |
| `status` | string | Backed by `DocumentStatus`. Only `draft` is reachable. |
| `number` | integer, nullable | Null until issued |
| `issued_on` | date | The **Ausstellungsdatum** |
| `performed_on` | date, nullable | **Leistungsdatum**, BT-72 |
| `performed_from` | date, nullable | **Leistungszeitraum** start, BT-73 |
| `performed_to` | date, nullable | **Leistungszeitraum** end, BT-74 |
| `payment_term` | string | Backed by `PaymentTerm` |
| `created_at`, `updated_at` | timestamps | |

Indexes: the primary key, `unique(company_id, number)`, and
`index(company_id, issued_on)` for the list's default sort.

**A unique index over a nullable column still admits every draft.** PostgreSQL
treats nulls as distinct, so any number of numberless drafts coexist under
`unique(company_id, number)` while the index still refuses two issued documents
sharing a number. That is the whole reason the index can be built before the
numbering that will rely on it.

**`customer_id` restricts rather than cascades.** §3.4 keeps customers a
document references intact; a delete that took an invoice with it would be worse
than the one the customers wave already refuses.

**`issued_on` is editable on a draft, and is a proposal until the document is
issued.** The mockup's create form shows it as „Rechnungsdatum", defaulting to
today. The column keeps the name `CONTEXT.md` fixes — **Ausstellungsdatum** —
because that is what it becomes, and because only a **Rechnung** may label it
„Rechnungsdatum" on screen.

**`payment_term` is copied onto the document, not read through the customer.**
The **Zahlungsziel** is part of what §4 freezes, and a customer who moves from
14 to 30 days must not retroactively change an invoice already written.

### 3.3 Exactly one Leistung

Three columns, and a document sets **either** `performed_on` **or** the
`performed_from`/`performed_to` pair — never both, never a half pair.

This is §7's correction made structural. EN16931 has two different structures,
BT-72 for the day and BG-14 for the period; a single field that held both would
map to neither. The rule is enforced by a model guard rather than only by the
form, because the form is one writer and a later import or job is another.

The form offers two date fields, „Leistung von" and an optional „bis". An empty
„bis", or one equal to „von", stores a **Leistungsdatum**; a later „bis" stores
a **Leistungszeitraum**. A calendar month, which §31 Abs. 4 UStDV permits, is a
Leistungszeitraum rather than a third form.

### 3.4 `line_items`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 |
| `document_id` | uuid, FK → `documents` | `cascadeOnDelete` |
| `position` | integer | 1-based; the printed **Pos.** |
| `title` | string | Required |
| `description` | text, nullable | |
| `quantity` | decimal(12,3) | Fractional quantities are a real case |
| `unit` | string | The `Unit` enum's UN/ECE code |
| `unit_price` | bigint | Cents, through `App\Casts\MoneyCast` |
| `tax_rate` | integer | Basis points — a value, not a foreign key |

`unique(document_id, position)`.

**`cascadeOnDelete` here, `restrictOnDelete` everywhere else.** A Position has
no life without its document: deleting a draft takes its Positionen with it,
which is the only deletion this system performs.

**No foreign key to `tax_rates` or to a units table.** §3.6 says a Position
„references no master data" and §4 makes an issued document immutable. A rate
that was renamed or deactivated afterwards must not be able to reach backwards
into a document that was computed with it, and a foreign key is exactly that
reach. `unit` holds the code and `tax_rate` the basis points, copied from the
pickers at the moment of typing.

**`quantity` is a decimal, not a float.** `CalculateTotals` takes a
`BigDecimal`, and `brick/math` refuses a float outright.

**Positionen are rewritten wholesale on every save**: the existing rows are
deleted and the submitted ones inserted, in the transaction Filament already
wraps the save in. This is what makes `unique(document_id, position)` safe —
reordering two rows in place would collide on the index halfway through — and it
is only affordable because nothing references a Position and because a draft is
the only thing that can be saved. The wave that issues will have to stop doing
this, and §3.7's guard is what will tell it.

### 3.5 `DocumentStatus`

A string-backed enum implementing `HasLabel` and `HasColor`, in the shape of
`CustomerType`:

| Case | Value | Label |
|---|---|---|
| `Draft` | `draft` | Entwurf |
| `Issued` | `issued` | Ausgestellt |
| `Sent` | `sent` | Versendet |
| `Paid` | `paid` | Bezahlt |
| `Cancelled` | `cancelled` | Storniert |

It carries `fromFormState()` and `isDraft()` — the one question the guards, the
actions and the form all ask of it. Only `Draft` is reachable in this wave; the
rest exist because the guards need something to refuse, and because a factory
state can produce them.

**Überfällig is not a case.** It is a derived display state, computed from the
due date and the open amount, and it arrives with the figures it needs.

### 3.6 `Document`, `Invoice`, `LineItem`

`Document` is the base model on `documents`. `Invoice` is a `tightenco/parental`
child on `type = invoice`: `Invoice::query()` scopes itself to invoices, and
`Document::query()` returns each row as its proper class.

One child for one type looks like ceremony and is not. The table shape is the
expensive half of the decision, and building it as `documents` now is what makes
**Storno**, **Teilstorno** and **Gutschrift** new classes later instead of a
table rename with four foreign keys pointing at it (tech-stack §6.5).

> **`LineItem`, not `DocumentLine`.** Tech-stack spec §12 lists `DocumentLine`;
> `CONTEXT.md` gives **Position** → `LineItem`, and `CLAUDE.md` makes
> `CONTEXT.md` the authority on what a domain concept is called. §12 is
> corrected.

### 3.7 Immutability, before anything can be immutable

`Document` refuses an update whose stored status is not `draft`. `LineItem`
refuses any write — insert, update or delete — belonging to a document that is
not a draft. Deleting a document refuses unless it is a draft.

Nothing in this wave can reach a non-draft status, and that is the argument for
writing the guards now rather than later: a factory can create an `issued` row
directly, so each guard is testable before the operation that would trip it
exists. Written after the issue operation, they would be written by someone with
a green suite and no way to see them fail.

### 3.8 Totals are computed, not stored

`Invoice::totals(): Totals` maps its Positionen onto `App\Money\LineInput` and
calls `CalculateTotals`.

§6 says totals are stored on the document rather than recomputed on display —
and §4 says that storing happens *at issue*. Until then the Positionen are the
only truth, and a stored copy would be a second one that an edit could leave
disagreeing with the lines it claims to sum.

## 4. Tenancy

`InvoiceResource` is a tenant-aware resource owned through `Document::company()`,
the ownership relationship Filament derives from `Company`. As with customers,
nothing in the resource mentions tenancy: the global scope, the tenant
association on create and the URL shape all follow from that relationship.

`CreateInvoice::handleRecordCreation()` associates the tenant **before** `save()`,
the same ordering `CreateCustomer` documents — Filament associates the tenant in
a `creating` listener of its own, and a model hook that needs `company_id` must
not depend on which listener registered first.

The customer picker queries customers of the current company only. This is the
first picker in the system, and so the first enforcement of the rule the
customers wave wrote down and could not yet apply.

## 5. The URL

A document is addressed by its **UUID**, for its whole life:
`/admin/{company}/invoices/{uuid}`.

**This differs from `Customer` deliberately.** A customer is addressed by
`K-0004` because that number exists from the moment the record does. A draft has
no **Belegnummer** — that is the point of a draft — so it can only be a UUID,
and a scheme that switched to the number at issue would change a document's
address partway through its life and need a route key that resolves two
different shapes.

The cost is accepted and stated: unlike the customer number, a **Belegnummer**
never appears in a URL. `Invoice` therefore needs no `#[RouteKey]` attribute,
no `getRouteKey()` and no `resolveRouteBindingQuery()` — it is markedly simpler
than `Customer`, and a URL that is not a UUID of this company's document is a
404 by Filament's own resolution.

## 6. Screens

A **„Rechnungen"** sidebar entry with a document icon at sort 2, between Kunden
(1) and Einstellungen, which moves to 3. All labels in `lang/de/invoice.php`.

### 6.1 List — `/admin/{company}/invoices`

- Columns: **Nummer** (`—` while a draft), **Status** (badge), **Kunde**,
  **Datum**, **Fällig** (`—` while a draft), **Betrag**.
- Sorted by `issued_on` descending — newest first, as the board shows.
- One search box, over the customer's name and the Positionen's titles.
- The query eager-loads `lineItems`, because **Betrag** is computed per row and
  would otherwise be one query per invoice.
- Header action: **Neue Rechnung**. Row ⋮: **Öffnen**, **Bearbeiten**,
  **Löschen**.
- Empty state: „Noch keine Rechnungen." / „Rechnungen entstehen als Entwurf und
  bekommen ihre Nummer erst beim Ausstellen." / **Neue Rechnung**.

**No status filter yet.** The `invoice-ui-decisions` memory reserves one for
this list, and it earns its place when there is more than one status to filter
by. Today every document is a draft, and a filter with a single option is
furniture.

### 6.2 Create and edit — `…/invoices/create`, `…/invoices/{uuid}/edit`

Two cards:

1. **Kopfdaten** — Kunde (searchable, active customers only), Rechnungsdatum,
   Zahlungsziel, and the Leistung pair of §3.3. Choosing a customer fills the
   Zahlungsziel from `Customer::effectivePaymentTerm()`, and the field stays
   editable afterwards.
2. **Positionen** — a `Repeater` in table layout: Bezeichnung, Beschreibung,
   Menge, Einheit, Einzelpreis, Steuer, and the line's Netto. The Steuer options
   come from `Company::selectableTaxRates()`, so a **Kleinunternehmer** is
   offered 0 % and nothing else. Under the rows, the VAT summary and the
   Gesamtbetrag, recomputed live.

The primary action reads **Entwurf speichern**. Below the buttons, the note that
the number is assigned only at issue.

**A deactivated customer stays selectable on a draft that already names one.**
The picker excludes deactivated customers, but a draft written before a customer
was deactivated must not silently lose its recipient on the next save.

### 6.3 Detail — `…/invoices/{uuid}`

Two columns — unlike the customer page, which deliberately dropped its right
column; this is what the board draws for a document.

- Left: a **Beleg** card (Rechnungsempfänger with the Kundennr., Nummer `—`,
  Rechnungsdatum, Leistung, Zahlungsziel) carrying the note „Entwurf – frei
  änderbar. Nummer, Festschreibung und PDF entstehen erst beim Ausstellen.";
  then **Positionen** with the VAT summary and the Gesamtbetrag.
- Right: a **Status** card (badge, the Betrag large, „:term ab Ausstellung"),
  and **Verlauf**.
- Header: **Bearbeiten**, **Rechnung ausstellen** rendered *disabled with a
  tooltip*, and a ⋮ holding **Löschen**.

> The board reads „Nummer, Beleg und PDF entstehen erst beim Ausstellen",
> using **Beleg** for the frozen identity block. `CONTEXT.md` records that
> exact misuse as already corrected once: the block is the **Festschreibung**,
> and **Beleg** is the umbrella term for the document itself. The copy and the
> board are corrected.

**Verlauf carries one derived line.** „Erstellt", from `created_at`, the way the
customer page shows „Kunde seit". There is no `AuditEntry` model: §3.8 of the
system design wants entries written by the transaction that performs the work,
and the work worth auditing — issued, PDF generated, sent, paid — is all in the
next wave. A draft is freely editable and is not part of the GoBD record.

### 6.4 The customer page's seams

The customers wave left three placeholders. Two are filled here: **Neue
Rechnung** becomes a live link to the create page with the customer preselected,
and the invoices card lists that customer's documents instead of an empty state.

**The three stat tiles stay at `0,00 €`.** They count revenue, open receivables
and overdue amounts, and all three are properties of issued documents. A tile
that summed drafts would be a number no accountant would recognise.

## 7. Error handling

| Situation | Behaviour |
|---|---|
| Required field missing | Validation message on the field |
| No Position at all | Validation message; a Rechnung with no Position has nothing to bill |
| Customer of another company submitted | Validation failure — the picker's query is the tenant's |
| Both a Leistungsdatum and a Leistungszeitraum | Refused by the model |
| Editing a document that is not a draft | Refused by the model; the page offers no edit action for one |
| Deleting a document that is not a draft | Refused by the model; the action is hidden |
| Deleting a draft | Its Positionen go with it |
| A URL that is not this company's document | 404 |
| The company has no customers yet | The create page says so and links to Kunden, rather than offering an empty picker |

## 8. Testing

Each test names what makes it fail.

1. **A draft is created in the current company, for a customer of that company.**
   The other company's customers are created first, so an unscoped picker or an
   unassociated tenant resolves to the wrong one.
2. **The picker excludes other companies and deactivated customers** — and
   still shows a deactivated customer that the draft being edited already names.
   One direction alone cannot tell „filtered" from „dropped".
3. **The page's totals equal the mockup's**: 12 × 95,00 and 6 × 95,00 at 19 %
   plus 1 × 290,00 at 7 % gives 2 000,00 net, 324,90 and 20,30 tax, 2 345,20
   gross. The same figures as `CalculateTotalsTest`, so the screen and the
   arithmetic cannot drift apart.
4. **A Kleinunternehmer is offered 0 % and nothing else.**
5. **Leistungsdatum or Leistungszeitraum, never both.** Saving a period clears
   the date and the reverse; a row carrying both is refused by the model.
6. **An issued document refuses an edit and a draft accepts one**, against a
   factory `issued()` state. Written before anything can issue, which is the
   only time the guard can be watched failing.
7. **An issued document refuses deletion, a draft allows it**, and deleting a
   draft takes its Positionen with it.
8. **A document resolves by UUID**, and a URL that is not one is a 404.
9. **Tenancy.** Company B's drafts never appear in A's list, and a row action on
   B's draft from A's list raises `ActionNotResolvableException`.
10. **The list does not go N+1 over Positionen** — asserted by counting queries,
    because the symptom of the bug is a slow page and not a wrong one.
11. **Parental returns the child class**: `Document::query()->first()` is an
    `Invoice`, and `Invoice::query()` ignores a row of another type.
12. **UUID v7 on `documents` and `line_items`**, joining `UuidKeysTest`.

**Deliberately not written:** that a migration has particular columns; that a
page returns 200 without asserting whose data it shows; anything asserting the
totals are stored, because they are not.

PostgreSQL throughout, per the standing rule.

## 9. Out of scope

- **Ausstellen** — the number draw, the Festschreibung, the totals, the PDF,
  the ZUGFeRD XML and its validation, the audit entry. The whole of §8.1.
- **`CheckReadiness` as a gate.** It exists and is still called by nothing but
  the dashboard; the obligation stays recorded in `CLAUDE.md`.
- **Storno, Teilstorno, Gutschrift** — the table is shaped for them, the classes
  are not written.
- **Payments, Mahnungen, sending, the period export.**
- **The status filter, the Überfällig badge, and the customer page's stat
  tiles** — all of them need issued documents to mean anything.
- **Duplicate (§8.6)** — a convenience over a document set that does not exist
  yet.

## 10. Documents this wave falsifies or owes

- **`README.md`** — a feature entry under „What it does today" for drafting
  invoices, saying plainly that nothing can be issued yet; the matching line
  trimmed from „Not built yet".
- **`CLAUDE.md`** — the „Current state" paragraph; the gap that `CheckReadiness`
  has no issue-time caller, restated now that a `Document` exists to refuse.
- **`docs/superpowers/specs/2026-09-23-invoice-system-design.md`** — §3.5 (the
  table exists, one of four types is built), §3.6 (`LineItem`), §4
  (immutability is enforced; freezing is not built), and decision rows in §15.
- **`docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md`** — §6.5
  (parental is in use) and §12 (`LineItem`, not `DocumentLine`).
- **`docs/superpowers/specs/2026-09-25-customers-design.md`** — §3.5's „the
  first picker arrives with invoices" is now true.
- **`CONTEXT.md`** — no change expected; nothing here moves a name.
- **`AGENTS.md`** — only if a guideline changes; never edited directly.
- **The Penpot mockups** — the „Nummer, Beleg und PDF" copy on the draft detail
  board.
- **The development database** — two migrations, so `php artisan migrate` must
  run against `invoice`. The suite cannot notice.

This spec is dated and is not itself updated when the stack later moves.

## 11. Decisions made during this design

| Decision | Choice |
|---|---|
| Document URL | The UUID, for the document's whole life; the Belegnummer never appears in one |
| Table shape | `documents` + parental from the start; one child class for now |
| What the table carries | Constraining structure now (`type`, `status`, `number` + its index); data the issue step computes later |
| Line item name | `LineItem`, per `CONTEXT.md`; tech-stack §12's `DocumentLine` is corrected |
| Line item references | None — unit code and tax rate are copied values, never foreign keys |
| Totals | Computed from the Positionen while a draft; stored only at issue |
| Leistung | Three columns, exactly one form set, enforced on the model |
| Zahlungsziel | Copied onto the document at creation, not read through the customer |
| Immutability | Guards written now, tested against a factory `issued()` state |
| Deleting | Drafts only, Positionen cascade |
| Saving Positionen | Rewritten wholesale, so reordering cannot collide with the position index |
| Verlauf | One derived „Erstellt" line; no `AuditEntry` model yet |
| Status filter | Deferred until there is more than one status |
| Customer stat tiles | Stay at zero; they count issued documents |
