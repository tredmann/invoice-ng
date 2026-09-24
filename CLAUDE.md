# Invoice

A multi-company German invoicing application. Laravel 13 + Filament 5, PHP 8.5.

**Current state: the development environment only.** No domain model exists yet —
no companies, customers, documents, numbering or money handling. If you are looking
for an `Invoice` model, it has not been written. What works is the container stack,
the test harness, a proven PDF renderer, and a Filament panel with login.

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
- **The current company lives in the URL, not only the session.** Every
  company-scoped screen sits under the company's slug — `/{company}/invoices`,
  `/{company}/settings`. Held in session alone, one URL shows different
  companies to the same person and a second tab fights the first. See §3.2.
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

## Known gaps

Deliberately parked, so they are not mistaken for oversights:

- `User::canAccessPanel()` returns `true` for every authenticated user. Safe today
  (no registration; one hand-made account), and must become a real check when
  tenancy lands.
- `config/database.php` and `config/queue.php` still default to `sqlite`. Nothing
  reaches those defaults — `phpunit.xml` and `.env.example` both set `pgsql` — but
  they read badly here and are worth changing.
- The six forked files under `.ai/guidelines/` silently discard upstream
  improvements on a Boost upgrade. See the maintenance doc below. (Two of the
  eight files there — `documentation/` and `rector/` — are additions rather
  than forks, and have no upstream to drift from.)

## Where things are written down

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what it is built from
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

Dated plan and task documents are history, not current state. Do not update
them when the stack moves.
