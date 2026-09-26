# Invoice

A multi-company German invoicing application. Laravel 13 + Filament 5, PHP 8.5.

**Current state: companies, the tenancy backbone and customers are in place.** A
company can be created, completed and archived, and every company-scoped screen
sits behind a Filament tenant boundary keyed on the company's slug. Each company
keeps its own customers — listed, created, viewed, edited and deactivated under
`/admin/{company}/customers`. Documents, invoice numbering and money handling do
not exist yet. If you are looking for an `Invoice` model, it has not been
written. What works is the container stack, the test harness, a proven PDF
renderer, and a Filament panel with login, tenancy and customers.

## Everything runs in the container

Nothing is installed on the host — not PHP, not Composer, not Postgres, not
WeasyPrint. Always go through Docker:

```sh
docker compose run --rm app php artisan <command>
docker compose run --rm app composer <command>
docker compose run --rm app ./vendor/bin/pest
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/rector process
```

`README.md` carries the first-run sequence and the full command list. The app
serves at http://localhost:8080.

## Decisions that are not negotiable

Each was expensive to reach. Read the reason before changing one.

- **PostgreSQL only, never SQLite — including in tests.** Gapless invoice numbering
  rests on `SELECT … FOR UPDATE`, which SQLite does not implement. A suite green on
  SQLite proves nothing about the property the whole system depends on.
- **Money is integer cents via `brick/money`.** Floats never touch money.
- **No Node.js.** There is no frontend build; Filament serves its pre-built assets.
- **DigitalOcean App Platform, not Laravel Cloud.** Laravel Cloud cannot run
  WeasyPrint, which renders the invoices.
- **Larastan stays at level 8.** Fix findings, or baseline them with a reason.
- **UUID primary keys on every model.** The UUID *is* the key — not a column
  beside a bigint. Version 7 via Laravel's `HasUuids`, so keys are time-ordered
  and index locality is fine. Identifiers here end up in URLs that get
  bookmarked and shared, and a sequential key tells the holder how many
  customers exist and lets them walk to a neighbour's. Unrelated to invoice
  numbers, which are sequential by law. See §3.1 of the system design spec.
  Customer URLs are the one place a sequential value sits in a path —
  `/admin/{company}/customers/K-0004` — and that is deliberate: every customer
  under a company's slug is one its viewer may already see, so there is no
  neighbour to walk to, and the number prints on every invoice anyway. The UUID
  is still the key. See §3.4 of the customers spec.
- **The current company lives in the URL, not only the session.** Every
  company-scoped screen sits under the company's slug — `/admin/{company}/invoices`,
  `/admin/{company}/settings`. Held in session alone, one URL shows different
  companies to the same person and a second tab fights the first. The panel
  keeps Filament's `/admin` prefix rather than mounting at the root; the
  property being protected is the company in the path, not the position of
  the prefix, and keeping it also leaves the root free for a real 404 instead
  of every unrecognised top-level segment reading as a company slug. See §3.2.
- **Identifiers are English; German is for labels and legal designations.**
  Columns, enums, classes and methods are named in English. German appears in
  user-facing labels, which live in `lang/de/`, and in legal designations that
  print verbatim and have no English equivalent — `GmbH` is a name, not a word.
  So the §19 UStG flag is a `vat_scheme` enum, and the document types of the
  next wave are `Cancellation`, `PartialCancellation`, `SelfBilledInvoice`
  and `DunningNotice` rather than Storno, Teilstorno, Gutschrift and Mahnung.
  Deciding this per column is how a codebase ends up bilingual, so the mapping
  is settled once and written down: **`CONTEXT.md` is the authority on what
  each domain concept is called in both languages.** Read it before naming
  anything in the domain, because several of these words mean close to the
  opposite of what they look like — a `Gutschrift` is not a credit note, it is
  a self-billed invoice under §14 Abs. 2 Satz 2 UStG, and the credit note is a
  `Teilstorno`.
- **Table row actions go in one vertical-ellipsis dropdown.** Never a row of
  buttons. Filament's `ActionGroup` already defaults to that trigger.
- **Simplicity over density in the interface.** Where a screen could show more
  or less, show less; add an affordance when a task needs it, not in advance.
  `.ai/guidelines/ui/core.blade.php` carries both UI rules in full.
- **Rector covers `app/` and `tests/` only.** `config/` and `bootstrap/` ship with
  Laravel and are replaced wholesale by framework upgrades; rewriting them turns
  every future skeleton diff into a merge conflict. Run Rector *before* Pint —
  Rector's output is not Pint-formatted, and the pair only settles in that order.

## Write assertions that can fail

This repo has produced four tests that passed while the thing they guarded was
broken. Before trusting a new test, answer one question: **what would make this
fail?** If nothing would, it is decoration.

Real examples from this codebase:

- `getDriverName()` reads resolved config, so it passes against a database that is
  switched off. Query the server instead: `select version()`.
- `19.99 × 3 == "59.97"` also passes under float arithmetic, because PHP rounds
  float-to-string at 14 significant digits. Assert something floats cannot do —
  `brick/money` throws rather than round silently.
- A PDF test checking for a `%PDF-` header and a plausible file size stayed green
  while every German character rendered as mojibake.
- Asserting a config value that equals its own `env()` default cannot detect the
  hardcoding it exists to prevent.

**Rendered PDFs need eyes.** `pdftotext` reads the ToUnicode table, which is
populated whether or not the font contains the glyph — so a missing font yields a
valid PDF full of empty boxes and a green suite. Look at the rendered page after
changing fonts, the base image, or the WeasyPrint version.

**A green suite says nothing about the development database.** Pest runs against
`invoice_test`, and `RefreshDatabase` rebuilds that schema on every run — so the
suite structurally cannot notice that `invoice`, the database the application at
<http://localhost:8080> actually uses, is missing a migration. A wave that adds
one is not usable in the browser until `php artisan migrate` has been run again,
and the symptom is `Undefined table` on the first page that touches the new
model, not a failing test. This has already happened once: companies shipped
with 73 passing tests and a development environment that could not load `/admin`.

## Known gaps

Deliberately parked, so they are not mistaken for oversights:

- `User::canAccessPanel()` returns `true` for every authenticated user. This is
  no longer a gap waiting on tenancy — tenancy has landed, and the boundary it
  was standing in for is `canAccessTenant()`, which checks the join table and
  makes Filament abort with 404 on a mismatch. `canAccessPanel()` stays
  permissive because there is still no registration and no user state to gate
  on; inventing a condition for it would be theatre, not a check.
- `config/database.php` and `config/queue.php` still default to `sqlite`. Nothing
  reaches those defaults — `phpunit.xml` and `.env.example` both set `pgsql` — but
  they read badly here and are worth changing.
- The six forked files under `.ai/guidelines/` silently discard upstream
  improvements on a Boost upgrade. See the maintenance doc below. (Two of the
  eight files there — `documentation/` and `rector/` — are additions rather
  than forks, and have no upstream to drift from.)
- A company created through registration has only a name and a legal form; its
  identity block — address, tax identifier, and for a registered legal form its
  register entry — is incomplete until the settings page has been saved once.
  Safe today because nothing in the application can issue a document yet. It
  stops being safe the moment the invoicing wave can: issuing from a company
  whose identity block is incomplete would not satisfy §14 UStG, so that wave
  must refuse to issue from one until it checks out complete.
- `company_user`'s only index is its composite primary key `(company_id,
  user_id)`. `User::getTenants()` filters on `user_id` alone — the trailing
  column of that composite — so it has no usable index path, while
  `canAccessTenant()`, which filters on both columns, does. Irrelevant at one
  user and a handful of companies; a one-line `$table->index('user_id')` when
  it isn't.
- Tenant scoping of `Customer` is Filament's: a global scope and a creation
  hook registered when the panel boots, active only inside panel requests. Code
  that runs outside the panel — a queued job, a console command, v2's
  recurring-invoice run — sees every company's customers and must scope
  explicitly. Livewire tests must boot the panel for the same reason
  (`actInCompany()` in `tests/Pest.php`).
- The **Berichtigung** (§31 Abs. 5 UStDV) is named and reserved in
  `CONTEXT.md`, but not built. Until it is, an error that leaves the amount
  untouched — a missing USt-IdNr., a wrong address — costs a full Storno, and
  on an invoice that was already paid the payment is left sitting on the
  cancelled document while its replacement stands open. See ADR 0001.
- The **Gutschrift** and the **Vermittler** are decided but unmodelled. A
  Vermittler needs what no `Customer` carries — their own tax number and bank
  details — because on a Gutschrift they are the supplying party and we are
  not. ADR 0002 carries the full list, including the `widersprochen` state
  that no invoice has and why revenue figures must exclude these documents
  even though they share the invoice number range.

## Where things are written down

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what it is built from
- `CONTEXT.md` — the German ubiquitous language of the domain, with the
  English identifier beside each term. A change to it is a change to what
  things are called everywhere.
- `docs/adr/` — decisions that were expensive to reach and would otherwise
  read as arbitrary
- `docs/agents-md-maintenance.md` — read before editing `AGENTS.md`; it is generated
- The Outline collection **Invoice** — <https://heimdall.tail1ec8f7.ts.net/collection/invoice-BgP8lxR8dF>

The specs are the authority. Read the relevant one before changing behaviour.

## A change usually makes more than one document wrong

The specs above are mirrored in Outline as many small linked pages, and
**nothing syncs the two**. A repo-only edit leaves the copy people actually
read quietly wrong — that is how one false claim about font subsetting
survived in three places at once.

So after changing the stack or behaviour, correct what the change falsified:
`README.md`, this file, the specs, and the Outline collection. `AGENTS.md`
carries the same list in full, including how to search and patch Outline —
and it is generated, so change it through `.ai/guidelines/`, never directly.

**A wave that adds something a user can see also owes `README.md` a feature
entry.** Its "What it does today" section is the only thing here written for
someone who wants to use the application rather than build it, so add the
behaviour there and delete the matching line from "Not built yet". Describe what
it does and what it is for, not the classes that implement it. Correcting a
falsified claim and recording a new capability are two different obligations;
this is the second one, and it is the one that gets forgotten, because nothing
breaks when a working feature goes unmentioned.

Dated plan and task documents are history, not current state. Do not update
them when the stack moves.
