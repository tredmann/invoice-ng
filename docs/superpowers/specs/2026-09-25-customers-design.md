# Customers — Design

Date: 2026-09-25
Status: Approved (brainstorming complete, ready for implementation planning)
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole. This document covers one wave of it.

## 1. Purpose

The first company-owned model. A company can now keep its customers: list
them, create them, look at one, edit one, and deactivate one.

Success means three things. The owner can enter the real customers of each
of their companies, under the right company. A customer of one company can
never be seen from another. And the invoicing wave arrives at a customer model
it can reference, rather than inventing one.

It is also the wave that proves the tenancy scoping configured in the
companies wave. Until now no model was company-owned, so Filament's automatic
query scoping and tenant association were unexercised (companies spec §9).
This wave carries the tests that exercise them (§7).

## 2. Carried in

Settled elsewhere and applied here, not revisited:

- UUID v7 primary keys; the UUID *is* the key (system design §3.1)
- Company-scoped screens live under `/admin/{company}/…` (§3.2)
- A customer is a company or a private person, with a customer number, billing
  address and billing email (§3.3)
- Customers are deactivated, never deleted (§3.4)
- A customer without an email address is not a dead end: send is unavailable,
  download still works (§13)
- German B2B and B2C only; no country (§2)
- Identifiers are English, labels German, in `lang/de/` (`CLAUDE.md`)
- One ⋮ dropdown per table row; show less rather than more
  (`.ai/guidelines/ui/core.blade.php`)

## 3. Data model

### 3.1 `customers`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key, v7 via `HasUuids` |
| `company_id` | uuid, FK → `companies` | Required. The tenant. |
| `number` | integer | Unique together with `company_id`. Assigned, never entered (§3.3). |
| `type` | string | Backed by the `CustomerType` enum |
| `name` | string | Required. Printed on the document exactly as typed. |
| `contact_person` | string, nullable | Business customers only |
| `vat_id` | string, nullable | Business customers only |
| `street` | string | Required |
| `postal_code` | string | Required |
| `city` | string | Required |
| `email` | string, nullable | The billing email |
| `archived_at` | timestamp, nullable | Deactivation |
| `created_at`, `updated_at` | timestamps | |

Indexes: the primary key, and a unique index on `(company_id, number)`, which
also serves every tenant-scoped query through its leading column.

**One table for both types.** Separate tables would save two nullable columns
and cost a polymorphic reference from every document that points at a
customer. The two business-only columns are the whole of the difference.

**One `name` for both types.** Not salutation, title, first and last name for
private persons. The invoice prints the name as the owner writes it —
"Bauer & Kollegen GmbH", "Dr. Annika Vogel" — and the list sorts by it as
written. A split name would serve a salutation in email templates, which is a
later wave and can add it then.

**The address is required** because §14 UStG requires the recipient's full
name and address on the invoice. **The email is not**, because the system
design already provides for a customer without one.

**`vat_id` is optional.** With EU cross-border invoicing out of scope, nothing
in the system requires a customer's USt-IdNr; it is recorded when the owner
has it. It is not format-validated, consistent with the company's own
`vat_id`.

**No default payment term.** §3.3 of the system design lists one per customer,
but payment terms themselves do not exist yet; a per-customer default arrives
with them.

### 3.2 `CustomerType`

A string-backed enum implementing `HasLabel`:

| Case | Value | Label |
|---|---|---|
| `Business` | `business` | Firma |
| `PrivatePerson` | `private_person` | Privatperson |

It carries `isBusiness(): bool`, the one question the form and the save path
both ask of it — the same shape as `LegalForm::isRegistered()`.

### 3.3 The customer number

**Assigned on creation, per company, never editable.** A company's first
customer is 1, its next is 2, independently of every other company.

It is stored as an integer and **displayed as `K-` followed by the number
padded to at least four digits**: 4 → `K-0004`, 10000 → `K-10000`. Storing the
integer keeps ordering and `max + 1` correct past 9999; a string column would
sort `K-10000` before `K-9999`.

Assignment happens in a `creating` hook on `Customer`, inside a transaction:
lock the owning company's row with `lockForUpdate()`, then take
`max(number) + 1` over that company's customers. The lock serializes
concurrent creates within one company and leaves other companies unaffected.

`number` is not fillable and the form never dehydrates it, so no request can
choose one. The unique index on `(company_id, number)` is the backstop: were
the lock ever bypassed, a race produces a failed save with an error, never two
customers sharing a number.

Customers are never deleted, so a number is never reused.

The number is **not gapless** and does not need to be. Gaplessness is a legal
requirement on invoice numbers (system design §5), not on customer numbers; a
customer number only has to be unique and stable. No counter column on
`companies` is added for the same reason — it would be a second source of
truth that has to agree with the data, when the data already answers the
question.

### 3.4 The customer number is the route key

Customer URLs carry the customer number, not the UUID:
`/admin/{company}/customers/K-0004` and `/admin/{company}/customers/K-0004/edit`.

**This looks like a breach of system design §3.1 and is not one.** §3.1 keeps
sequential values out of URLs for two reasons, and neither applies here:

- *It lets the holder walk to a neighbour's record.* Every customer under
  `/admin/{company}/…` is one the viewer may already see; another company's
  K-0004 resolves only under that company's slug, which the tenancy boundary
  refuses. There is no neighbour to walk to.
- *It tells the holder how many records exist.* The customer number prints on
  every invoice the customer receives. The URL discloses nothing the document
  does not.

The UUID remains the primary key and every foreign key carries it. The number
is a routing convenience, as the company slug is, and is stable for the same
reason the slug is: it can never change, so a bookmarked link keeps working.

Mechanically: the resource uses `number` as its record route key, and record
resolution is overridden to parse `K-0004` to `number = 4`. Anything that is
not `K-` followed by digits resolves to nothing and is a 404. Resolution runs
through the resource's query, so it is scoped to the current company (§4).

### 3.5 Deactivation

`archive()` and `unarchive()` on `Customer`, mirroring `Company`:
`archived_at` is not fillable and changes only through those two methods, and
archiving an already archived customer keeps its original date.

A deactivated customer stays viewable and editable and keeps its URL. What it
loses is a place in pickers — and the first picker arrives with invoices,
which is where "only active customers can be chosen" gets enforced.

## 4. Tenancy

`CustomerResource` is a tenant-aware resource in the existing panel, owned
through `Customer::company()`. Filament 5 therefore:

- adds a global scope to `Customer` that constrains queries to the current
  company, which the list table uses and which record resolution from the URL
  uses — a record of another company is a 404;
- sets `company_id` to the current company when a customer is created.

Both happen only inside the panel, after the tenant is identified. Nothing in
this wave queries customers outside it. The invoicing wave's customer picker
sits inside the panel too; the recurring-invoice job of v2 does not, and must
scope explicitly.

`canAccessTenant()` remains the routing boundary: a user who does not belong
to a company cannot reach any of its screens, customers included.

## 5. Screens

A **"Kunden"** sidebar entry, with a people icon, above Firmendaten. All
labels in `lang/de/customer.php`.

### 5.1 List — `/admin/{company}/customers`

- Columns: **Kundennr.** (`K-0004`), **Name**, **Typ** (badge: Firma /
  Privatperson), **E-Mail**, **Ort**.
- Sorted by name, ascending, by default.
- One search box, over number, name, email and city. The number matches
  however it is typed: `K-0004`, `0004` and `4` all find customer 4. The
  number match is exact on the parsed integer, not a substring, so `4` does not
  also find 14 or 40; name, email and city match as substrings,
  case-insensitively.
- Deactivated customers stay in the list, greyed, with a **Deaktiviert** badge
  beside the name.
- Header action: **Neuer Kunde**.
- Clicking a row opens the view page. One ⋮ menu per row: **Öffnen**,
  **Bearbeiten**, **Deaktivieren** — or **Wieder aktivieren** for a
  deactivated customer. Deactivating asks no confirmation; undoing it is one
  click.
- Empty state: heading "Noch keine Kunden angelegt.", description "Jede
  Rechnung geht an einen Kunden. Legen Sie den ersten an.", and a **Neuer
  Kunde** button.
- No filters, no bulk actions, no `created_at` column.

### 5.2 Create and edit — `…/customers/create`, `…/customers/K-0004/edit`

One form, three sections:

1. **Kunde** — Typ as a two-option toggle (Firma / Privatperson), live, Firma
   by default; Name; Ansprechpartner and USt-IdNr., both rendered for Firma
   only.
2. **Adresse** — Straße und Hausnummer, PLZ, Ort.
3. **Kontakt** — E-Mail, validated as an address when given.

On edit, the customer number is shown read-only, as the company slug is on the
settings page. On create it is absent; it does not exist until saved.

After saving, the owner lands on the view page. There is no delete action on
any page.

**Changing a business customer to a private person clears `contact_person`
and `vat_id` on save.** Hidden Filament fields are not dehydrated, so without
this they would stay in the database, where a later document could print
them. This is the precedent `CompanySettings` set for the register fields, and
it follows the same rule: the predicate that decides whether the fields are
rendered (`CustomerType::isBusiness()`) is the same one that decides whether
they are cleared, so the two cannot disagree.

### 5.3 View — `…/customers/K-0004`

- The same three sections, read-only, with Kundennr. and Typ at the top.
- Title: the customer's name, with the Deaktiviert badge when it applies.
- Header actions: **Bearbeiten** as the primary action, and **Deaktivieren**
  or **Wieder aktivieren**.

Today it largely repeats the form. It exists because invoicing will add the
customer's documents here, and because the list's **Öffnen** should lead
somewhere that cannot be changed by accident.

## 6. Error handling

| Situation | Behaviour |
|---|---|
| Required field missing | Validation message on the field |
| Invalid email | Validation message on the field |
| Number in the URL unknown, malformed, or another company's | 404 |
| Company in the URL not the user's | 404, via `canAccessTenant()` |
| Two creates race past the lock | Unique violation; the save fails with an error; no duplicate number |

## 7. Testing

Each test names what makes it fail.

1. **Record resolution is scoped.** Companies A and B each have a customer
   K-0001 with different names. `/admin/a/customers/K-0001` shows A's name and
   `/admin/b/customers/K-0001` shows B's. Fails if resolution ignores the
   tenant — and a test where only one company has a K-0001 would pass against
   exactly that bug.
2. **The list is scoped.** A's list does not contain B's customer. Fails if
   the list query is unscoped.
3. **Creation is associated.** A customer created through the form under A
   has A's `company_id`. Fails if the ownership relationship is wrong.
4. **Numbers are per company.** A's first two customers get 1 and 2; B's
   first gets 1. Fails against one global sequence.
5. **The number cannot be chosen.** A submission that sets `number` to 99
   still receives the next number. Fails if `number` is fillable or dehydrated.
6. **The backstop exists.** Inserting a duplicate `(company_id, number)`
   directly raises a unique violation. Fails if the index is missing — which
   no test of the happy path would notice.
7. **Type changes clear business fields, and only type changes.** Business to
   private person nulls `contact_person` and `vat_id`; a business customer
   saved again keeps both. One direction alone cannot tell "cleared when
   switched" from "always cleared".
8. **Formatting and resolution.** 4 formats as `K-0004`, 10000 as `K-10000`;
   `…/customers/K-0004` resolves the customer numbered 4, and
   `…/customers/{its uuid}` is a 404.
9. **Search by number.** `0004` finds customer 4 and not customer 40.
10. **Deactivation.** A deactivated customer is still in the list with its
    badge and its view URL resolves; reactivating clears `archived_at`.
11. **UUID v7 on `customers`.** Column type read from Postgres, and the
    version nibble is 7 — joining the existing checks in `UuidKeysTest`.

PostgreSQL throughout, per the standing rule.

**Deliberately not written:** a true concurrency test of the lock. Here a lost
race is a failed save, and test 6 proves it cannot become a duplicate. The
concurrency test belongs to invoice numbering (system design §14), where a
lost race is a legal problem. Also not written: that the migration has
particular columns, or that a page returns 200 without asserting whose data it
shows.

## 8. Out of scope

- ~~A per-customer default payment term (§3.1)~~ — **built 2026-09-26**, with
  the wave that brought payment terms. `customers.payment_term` is nullable and
  null means „use the company's"; see
  `docs/superpowers/specs/2026-09-26-company-master-data-design.md` §3.4.
- A customer picker, and refusing deactivated customers in it — invoicing wave
- Deleting a customer
- A country field
- Filters, bulk actions, import or export
- Salutation and split names for private persons

## 9. Documents this wave falsifies or owes

Per `.ai/guidelines/documentation/core.blade.php`, when the implementation
lands:

- **`README.md`** — a feature entry under "What it does today" for customers,
  and the matching line removed from "Not built yet". Described as what it
  does and what it is for.
- **`CLAUDE.md`** — the "Current state" paragraph, which says customers do not
  exist; and a sentence in the UUID bullet that the customer number is the
  route key for customer URLs, and why that does not contradict it (§3.4).
- **`docs/superpowers/specs/2026-09-23-invoice-system-design.md`** — a
  corrective note in §3.1 on the customer number as route key; a note in §3.3
  that the per-customer default payment term is deferred until payment terms
  exist; decision rows in §15.
- **`AGENTS.md`** — only if a guideline changes; never edited directly.
- **The Outline collection "Invoice"** — the same corrections. Nothing syncs
  it.
- **The development database** — the wave adds a migration, so
  `php artisan migrate` must run against `invoice` before `/admin/{company}/customers`
  loads. The suite cannot notice.

This spec is dated and is not itself updated when the stack later moves.

## 10. Decisions made during this design

| Decision | Choice |
|---|---|
| Customer number | Assigned per company on creation, `max + 1` under a company-row lock; never editable |
| Number storage | Integer; displayed `K-` plus at least four digits |
| Number gaps | Allowed; gaplessness is required of invoice numbers only |
| Customer URL | Carries the customer number, not the UUID; the UUID stays the key |
| Customer types | `Business` and `PrivatePerson` in one table |
| Name | One field for both types, printed as typed |
| Required fields | Type, name, full address. Email, contact person, VAT ID optional |
| Switching to private person | Clears contact person and VAT ID on save |
| Default payment term | Deferred until payment terms exist |
| Deactivated customers | Stay in the list, badged; viewable and editable; keep their URL |
| View page | Read-only, now; it gains the customer's documents with invoicing |
| Deletion | None |
