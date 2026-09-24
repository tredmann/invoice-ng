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
  improvements on a Boost upgrade. See the maintenance doc below.

## Where things are written down

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what it is built from
- `docs/agents-md-maintenance.md` — read before editing `AGENTS.md`; it is generated

The specs are the authority. Read the relevant one before changing behaviour.
