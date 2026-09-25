# Companies and Tenancy — Design

Date: 2026-09-24
Status: Approved (brainstorming complete, ready for implementation planning)
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole. This document covers one wave of it.

## 1. Purpose

The first domain wave. Until now the repository holds a development
environment and no domain model at all. This wave delivers companies as real
records — creatable, listable, with their master data enterable — and, because
companies are the tenant, it builds the tenancy backbone every later wave
plugs into.

Success means two things. The owner can enter the master data of their actual
companies, both the GmbH and the sole proprietorship. And the next wave —
customers — arrives at a tenancy boundary that already exists, rather than
inventing one.

What this wave is *not* is a step toward multi-user. §3.2 of the system design
keeps that possible; nothing here builds it.

## 2. Carried in from the system design

These are settled elsewhere and are not revisited here, only applied:

- UUID v7 primary keys; the UUID *is* the key (§3.1)
- The current company lives in the URL under a stable unique slug (§3.2)
- A user reaches companies through a join table carrying no roles (§3.2)
- Companies are deactivated, never deleted (§3.4)
- Table row actions live in one vertical-ellipsis dropdown
  (`.ai/guidelines/ui/core.blade.php`)
- Where a screen could show more or less, it shows less (same file)

## 3. Identifiers are English

**Columns, enums, classes and methods are named in English.** German survives
in exactly two places:

1. **User-facing labels**, which live in translation files — the system design
   already establishes that the interface is translatable while the invoice
   document is German.
2. **Legal designations that print verbatim on the document and have no
   English equivalent** — `GmbH` and `UG` are names, not words, the way `Inc.`
   is.

This is written down because the wave after this one is full of terms that
would otherwise leak: Storno, Gutschrift, Mahnung, Leistungsdatum become
`CancellationInvoice`, `CreditNote`, `PaymentReminder`, `service_date`.
Deciding it once is cheaper than deciding it per column, and the existing
specs use the German terms throughout, so without this section the next wave
re-litigates it.

The rule applies to new code. The specs keep the German terms where they
discuss the German domain; the code does not.

## 4. Data model

### 4.1 `companies`

UUID v7 primary key via Laravel's `HasUuids`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Primary key |
| `name` | string | The full legal name. Prints on the document; drives the slug. |
| `slug` | string, unique | Derived from `name` at creation, immutable afterwards |
| `legal_form` | string | Backed by the `LegalForm` enum |
| `street` | string | |
| `postal_code` | string | |
| `city` | string | |
| `tax_number` | string, nullable | Steuernummer |
| `vat_id` | string, nullable | USt-IdNr |
| `vat_scheme` | string | Backed by the `VatScheme` enum |
| `register_court` | string, nullable | Amtsgericht |
| `register_number` | string, nullable | HRB number |
| `managing_directors` | string, nullable | Footer text; see below |
| `bank_name` | string, nullable | |
| `iban` | string, nullable | Validated, see §4.4 |
| `bic` | string, nullable | |
| `archived_at` | timestamp, nullable | Deactivation (§3.4) |
| `created_at`, `updated_at` | timestamp | |

Choices worth the reasoning:

**No `country` column.** The system design scopes this application to German
invoicing only — no EU cross-border, no third country. A column whose value is
always `DE` is speculation, and the document does not print it for a German
seller invoicing a German customer.

**One `name`, not `name` plus `trading_name`.** For a sole proprietorship the
legal name is the owner's own name, so the company switcher will show a
person's name rather than a brand. That is accepted. Splitting the field later
is a migration and a form field; carrying two name fields from the first day is
the density the UI guidelines argue against.

**`managing_directors` is one string, not a related table.** It is footer text,
authored once per company. A GmbH with two Geschäftsführer writes both into it.
A table would buy ordering and per-person attributes that nothing needs.

**`vat_scheme` rather than a boolean.** §3.3 of the system design lists a
"Kleinunternehmer flag". The direct translation, `is_small_business`, names the
company rather than the thing that varies, which is which VAT scheme the
company invoices under. The enum reads in English without contortion and the
§19 UStG citation belongs in its docblock — as the reason, not the name.

`vat_scheme` carries no behaviour in this wave. It is here because it is master
data the owner enters now, and because it is why `vat_id` cannot be
unconditionally required.

### 4.2 `company_user`

`company_id`, `user_id`, timestamps. Composite primary key on the pair, which
also gives the uniqueness constraint. No roles column (§3.2).

**No UUID key.** The §3.1 rule governs models; this pivot carries no model and
no identifier that could leak into a URL.

`User belongsToMany Company`; `Company belongsToMany User`.

### 4.3 Enums

`app/Enums/LegalForm.php` — `GmbH`, `UG`, `SoleProprietorship`, backed by
strings. A string column rather than a native database enum, so a fourth legal
form is an enum case and not a migration.

The enum answers one question the forms and the document both ask: **is this
company entered in the Handelsregister?** `GmbH` and `UG` are; a sole
proprietorship is not. That predicate lives on the enum rather than being
re-derived at each call site.

`app/Enums/VatScheme.php` — `Standard`, `SmallBusiness`, backed by strings.

### 4.4 The IBAN rule

`app/Rules/Iban.php` — a format check followed by the mod-97 checksum: move the
first four characters to the end, map letters to their two-digit values, and
require a remainder of 1.

The realistic error here is a transposed pair of digits in an IBAN typed from a
bank statement, and mod-97 is the thing that catches it. Country-specific
lengths are not encoded; the checksum plus a length range is enough.

### 4.5 Slug generation

The slug is derived from `name` on creation and never regenerated. Collisions
get a numeric suffix, so two companies named "Acme GmbH" both get a working,
distinct URL.

Immutability is achieved by only ever setting it on create, not by a guard.
The test that a rename leaves the slug alone therefore guards a future
mistake — a `Sluggable` concern that regenerates on update is a common thing to
reach for, and it would silently break every bookmarked URL. Cheap, and worth
naming as a regression guard rather than a test of present behaviour.

## 5. Tenancy

### 5.1 Filament's multi-tenancy, not a hand-rolled boundary

The panel gains `->tenant(Company::class, slugAttribute: 'slug')`. Filament
then resolves the slug from the path, binds the company, scopes every
tenant-owned resource, and supplies the switcher, the tenant-registration page
and route-level access checks.

Two alternatives were weighed. A second panel for cross-company administration
would give the companies list a URL outside any company — a cosmetic gain paid
for with two panel providers, two navigations and two sets of middleware kept
in step forever. Hand-rolling the boundary — a `/{company}` route group,
resolving middleware, a global scope, a custom switcher — maps literally onto
the wording of §3.2, but spends the first domain wave rebuilding a framework
feature and then fights Filament's resource routing indefinitely. What §3.2
asks for is not unusual; it is this feature's default behaviour.

### 5.2 The panel keeps its `/admin` prefix

Company-scoped screens live at `/admin/{company}/…`, so settings is
`/admin/{company}/settings`.

**This deviates from §3.2 of the system design, which writes
`/{company}/invoices`.** A root-mounted panel would match that literally, at
the cost of every unrecognised top-level path being read as a company slug —
which makes genuine 404s and any future public page awkward. The property §3.2
argues for is that the company is in the path rather than in session state, and
the prefix does not weaken it.

§3.2 and the §15 decision table are therefore wrong as written, and correcting
them is part of this wave (§10).

### 5.3 The boundary itself

`User implements HasTenants`:

- `getTenants()` returns the user's **non-archived** companies. This is what
  the switcher lists.
- `canAccessTenant()` checks the join table only, **including archived
  companies**.

The asymmetry is deliberate. An archived company drops out of the switcher
because it is no longer used, but its historical documents must stay readable,
so its URL keeps working.

`canAccessTenant()` is the real tenancy boundary — the thing that stops one
company's data appearing under another's slug.

`canAccessPanel()` stays permissive. `CLAUDE.md` records it as a gap that must
become a real check when tenancy lands; the check it stood in for is
`canAccessTenant()`, which is now real. There is no registration and no user
state to gate on, so a condition invented for `canAccessPanel()` would be
theatre. The gap note gets corrected rather than satisfied (§10).

### 5.4 Archiving the last company is refused

A company can be archived while the owner is inside it: it leaves the switcher,
the current page stays put, and its URL keeps working.

Archiving the *last* non-archived company is refused, because it is a dead end.
With `getTenants()` empty, Filament redirects to the registration page on the
next navigation — and since the companies list is itself a panel screen behind
the tenant prefix, there would be no route left from which to restore anything.
One guard, for a reachable dead end.

## 6. Screens

Four surfaces, deliberately no more.

**Creation — the tenant registration page.** `RegisterCompany` extends
Filament's `RegisterTenant`. It asks for `name` and `legal_form` only,
generates the slug, creates the company, links the current user through the
join table, and redirects into that company's settings to fill in the rest.

This is the only creation path. The companies list links here rather than
carrying a form of its own, so there is one form to keep correct.

It also solves bootstrapping for free: a user with no companies is redirected
here by Filament, so "no companies yet" needs no handling of its own.

**Company settings — the tenant profile page,** at `/admin/{company}/settings`.
Sections: Identity (`name`; the slug shown read-only), Address, Tax
(`tax_number`, `vat_id`, `vat_scheme`), Commercial register, Management, Bank.

The Commercial register and Management sections are rendered only for
registered legal forms, where their fields are required. For a sole
proprietorship neither exists — it has no Handelsregister entry and no
Geschäftsführer. Both sections read that predicate off the `LegalForm` enum
(§4.3) rather than testing for cases.

**Companies list — a `CompanyResource` with a list page and nothing else,**
marked not scoped to tenant. Columns: name, legal form, archived state. No
filters and no `created_at` — with a handful of rows those are speculative
(`.ai/guidelines/ui/core.blade.php`).

Row actions sit in one `ActionGroup`: Open, which switches into the company,
and Archive or Restore.

**It has no Edit or View page.** Settings is the single editing surface for a
company. Two surfaces onto one record drift apart, and the second one exists
only to duplicate the first.

The resource does not register a navigation item; it is reached from a "Manage
companies" entry in the tenant menu. The sidebar stays empty for the
company-scoped work that arrives in the next wave.

**The switcher** is Filament's tenant menu: non-archived companies, plus "New
company" and "Manage companies".

> **Corrected 2026-09-25 — a fifth surface, the company picker.** `/admin` no
> longer redirects into the user's default company. `SelectCompany`, a
> `SimplePage` outside any company, shows one tile per company from
> `getTenants()` — name, and legal form beneath it — linking to
> `/admin/{company}`, plus a single "New company" button to registration. It is
> shown even with one company, so `/admin` always means the same thing. Login
> lands there (a custom `LoginResponse`; a link the user was on the way to
> still wins), and the switcher gains an "All companies" entry back to it. A
> user with no non-archived company is still sent to registration.
>
> Filament registers `GET /admin` itself, after any application route, so the
> picker takes it over by binding Filament's `RedirectToTenantController` to the
> page in the container rather than by declaring a competing route.

## 7. Validation

The creation form requires `name` and `legal_form`. Nothing else, because
nothing else is needed for the record to exist and be routable.

The settings form requires the full identity block:

- address, always
- at least one of `tax_number` or `vat_id`, always — which of the two is the
  usual one depends on the VAT scheme, so neither can be required alone
- `register_court`, `register_number` and `managing_directors` for registered
  legal forms only; for a sole proprietorship these fields are not rendered and
  stay null
- a valid IBAN whenever one is given; bank details as a whole stay optional
  until a document needs them

A consequence: **a freshly created company is incomplete until settings is
saved once.** That is accepted here and hands the invoicing wave an
obligation — issuing a document from a company with an incomplete identity
block must be refused, since the document would not satisfy §14 UStG. This
spec records the obligation; it does not implement it.

## 8. Testing

The standing rule in `CLAUDE.md` is that a test must be able to fail. Each of
these names what breaks it.

1. **Isolation.** A user linked to company A only requests
   `/admin/{b-slug}/settings` and is refused. Fails if `canAccessTenant()` is
   wrong or missing — the characteristic failure of a multi-company invoicing
   system.
2. **The URL decides the company, not the session.** One user linked to both A
   and B: request A's settings and assert A's data, then B's settings in the
   same session and assert B's. The single-request version of this test passes
   against the session-only design §3.2 forbids; this one does not.
3. **Slug collision.** Two companies named "Acme GmbH" get distinct slugs and
   both resolve.
4. **Slug immutability.** Renaming a company leaves its slug alone. A
   regression guard against a regenerating `Sluggable`, per §4.5.
5. **IBAN mod-97.** A valid IBAN passes and the same IBAN with two digits
   transposed fails. Asserting length and country prefix would pass against no
   checksum at all.
6. **Conditional validation, both directions.** A sole proprietorship saves
   with the register fields empty; a GmbH without a `register_number` fails.
   One direction alone cannot distinguish "required for a GmbH" from "required
   always" or "never required".
7. **Archiving.** The archived company is absent from `getTenants()` *and* its
   URL still resolves. Either assertion alone passes a wrong implementation.
8. **Archiving the last company is refused,** and archiving one of two
   succeeds. Same reasoning.
9. **UUID v7 keys on `companies`.** The version nibble is 7 — a v4 key passes a
   "looks like a UUID" regex — and the column type comes from a query to
   Postgres rather than from resolved config, which is the trap `CLAUDE.md`
   records.

PostgreSQL throughout, per the standing rule. The composite pivot key and the
`uuid` column types are exactly the things SQLite accepts differently.

Tests deliberately not written: that a migration has particular columns; that
creating a company results in one row; that a Filament page returns 200 without
asserting whose data it shows.

## 9. Out of scope, and what is handed forward

**Not in this wave:** logo; tax rates; payment terms; number ranges; SMTP
credentials; email templates; customers; invoices and any document; numbering;
money handling; dashboard widgets; roles or multi-user; deleting a company.

Each of the master-data items is §3.3 data whose screen or consumer does not
exist yet.

**A gap to record rather than assume.** Tenancy scoping is configured but not
exercised: no model is company-owned until customers arrive, so
`ownershipRelationship` and the automatic scoping of tenant-owned records are
unproven at the end of this wave. The isolation tests cover the routing
boundary, not the query scoping. The first company-owned model is what proves
the rest, and it should carry the test that does so.

**Handed to the invoicing wave:** refusing to issue a document from a company
with an incomplete identity block (§7).

## 10. Documents this wave falsifies

Per `.ai/guidelines/documentation/core.blade.php`, to be corrected when the
implementation lands:

- **`CLAUDE.md`** — the "development environment only" paragraph; the
  `/{company}/invoices` bullet, which becomes `/admin/{company}/…`; the
  `canAccessPanel()` known gap, which is superseded by `canAccessTenant()`
  rather than satisfied; and the English-identifiers convention of §3, which is
  a new decision worth recording.
- **`docs/superpowers/specs/2026-09-23-invoice-system-design.md`** — §3.2's URL
  shape, and two new rows in the §15 decision table: the panel prefix and the
  naming convention.
- **`README.md`** — it names `/admin` and `http://localhost:8080/admin` in the
  first-run sequence; check both still read true once the panel has tenancy,
  since logging in with no company now lands on company registration.
- **`AGENTS.md`** — never edited directly. The naming convention goes into the
  matching file under `.ai/guidelines/`, then `boost:install --guidelines`.
- **The Outline collection "Invoice"** — the URL shape and the naming
  convention. Nothing syncs it; search it for the terms that changed and patch
  with `editMode: "patch"`.

This spec is dated and is not itself updated when the stack later moves.

## 11. Decisions made during this design

| Decision | Choice |
|---|---|
| Tenancy mechanism | Filament's built-in multi-tenancy, not two panels and not hand-rolled |
| Panel path | Keeps the `/admin` prefix; deviates from §3.2, which gets corrected |
| Identifier language | English, except legal designations that print verbatim |
| Kleinunternehmer flag | Becomes a `vat_scheme` enum, `Standard` or `SmallBusiness` |
| Company name | One `name`, the legal name; no separate trading name |
| Country | No column; German invoicing only |
| Geschäftsführer | One string of footer text, not a related table |
| Legal forms | `GmbH`, `UG`, `SoleProprietorship`; string column, not a database enum |
| Pivot key | Composite, no UUID; it carries no model |
| Creation path | Only the tenant-registration page; asks name and legal form |
| Editing surface | Only the tenant-profile settings page; the resource has no edit or view page |
| Companies list | List page only, unscoped, reached from the tenant menu |
| Archived companies | Leave the switcher, keep their URL |
| Archiving the last company | Refused; it is a dead end |
| Incomplete companies | Allowed to exist; issuing from one must be refused later |
| Master data in this wave | Identity, tax, register, management, bank. Not logo, tax rates, terms, numbering, SMTP, templates |
