# Invoice

A multi-company German invoicing application, built on Laravel 13 and Filament 5.

## What it does today

From the point of view of someone using it, rather than building it:

- **Several companies from one login.** Set up more than one company and switch
  between them with the switcher in the header. Every company-scoped screen
  lives under that company's own slug — `/admin/acme-gmbh/settings` — so a
  bookmarked or shared link always shows the same company, and a second browser
  tab cannot quietly switch the first one out from under you.
- **A start page with all your companies, and a switcher in the header.**
  After logging in you land on `/admin`: one tile per active company — its name
  and legal form — and a button to set up a new one. The company switcher at
  the top left moves you between companies from any screen, and its "Firmen
  verwalten" brings you back to the start page. Inside a company the sidebar holds its
  dashboard, its customers (Kunden) and its company data (Firmendaten). With no
  company yet, the start page says so and offers to create one — nothing makes
  you.
- **The header says where you are.** Inside a company, the switcher names the
  company above the sidebar, and next to it, lined up with the page below, a
  trail shows where you are in it — `Kunden › Bauer & Kollegen GmbH`; every
  step but the last is a link back. The switcher's menu lists your companies
  with the current one ticked, and also takes you to the start page ("Firmen
  verwalten") or to setting up a new company ("Neue Firma").
- **Company master data, kept per company.** Legal name and legal form, address,
  Steuernummer and/or USt-IdNr, bank details, and whether the company invoices
  under the standard VAT scheme or as a Kleinunternehmer (§19 UStG). An IBAN is
  checked against its mod-97 checksum, so a pair of transposed digits is caught
  as you type it rather than when a payment goes missing. It can be pasted
  straight from online banking — spaces and lower case are fine.
- **Each legal form is asked only for what applies to it.** A GmbH or UG states
  its Registergericht, Registernummer and Geschäftsführer; an Einzelunternehmen
  is never asked for them. Change a company's legal form to one with no register
  entry and the old entry is cleared, rather than left behind to print on a
  later document.
- **Customers, kept per company.** Under Kunden, each company keeps its own
  customers — a Firma, with an optional contact person and USt-IdNr., or a
  Privatperson — with billing address and billing email. Every new customer
  gets its company's next customer number (K-0001, K-0002, …) automatically,
  and that number is its address: `/admin/acme-gmbh/customers/K-0004` always
  opens the same customer. One search box finds customers by number, name,
  email or city. A customer of one company never appears under another.
- **Customers are deactivated, never deleted.** A deactivated customer stays in
  the list, greyed and marked "Deaktiviert", keeps its page and its number, and
  can be reactivated from its ⋮ menu or its page.
- **Companies are deactivated, never deleted.** An archived company drops out of
  the switcher but keeps its URL, so everything it is attached to stays
  readable. Archive and restore from the ⋮ menu on its tile and the
  "Deaktiviert" section of the start page — your last active company included.
- **German throughout.** The interface is German, and the application runs with
  the `de` locale and `Europe/Berlin`.

### Not built yet

The entire document side: ZUGFeRD invoice PDFs, gapless invoice
numbering, Storno and Gutschrift, recording payments, sending email, reminders,
recurring invoices, the period export for the tax advisor, and dashboard
reporting. `docs/superpowers/specs/2026-09-23-invoice-system-design.md`
describes all of it. None of it exists yet.

## Prerequisites

**Docker Desktop only.** Nothing else needs to be installed on the host — not
PHP, not Composer, not Node, not PostgreSQL. Everything runs inside the
`app` container defined in `compose.yaml`.

## First run (fresh clone)

Run these from the repository root, in order:

```sh
docker compose build
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan make:filament-user
docker compose up -d
```

What each step does:

1. `docker compose build` — builds the `app` image (FrankenPHP, PHP 8.5, the
   PostgreSQL client extension, and WeasyPrint's native rendering stack).
2. `cp .env.example .env` — copies the environment template. The default
   database credentials already match `compose.yaml`'s `db` service, so no
   editing is required to get a working local setup.
3. `docker compose run --rm app composer install` — installs PHP
   dependencies into the container-managed `vendor/` directory.
4. `php artisan key:generate` — writes an `APP_KEY` into `.env`.
5. `php artisan migrate` — creates the schema on the `db` service (started
   automatically as a dependency of `app`).
6. `php artisan make:filament-user` — creates the account you'll actually
   log in with at `/admin`. It prompts for name, email, and password (or
   pass `--name=`, `--email=`, `--password=` non-interactively).
7. `docker compose up -d` — starts the stack in the background. The app
   serves at <http://localhost:8080>, and the admin panel is at
   <http://localhost:8080/admin>. A freshly created user belongs to no
   company yet, so the first login shows an empty start page with a button to
   create the first company.

## Day-to-day commands

Everything goes through `docker compose run --rm app …` (or, for services
already running via `docker compose up -d`, `docker compose exec app …`):

```sh
docker compose up -d                                     # start the stack
docker compose run --rm app php artisan <command>         # artisan
docker compose run --rm app composer <command>            # composer
docker compose run --rm app ./vendor/bin/pest             # run tests
docker compose run --rm app ./vendor/bin/pint             # fix formatting
docker compose run --rm app ./vendor/bin/pint --test      # check formatting
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M  # static analysis
docker compose run --rm app ./vendor/bin/rector process         # apply automated refactors
docker compose run --rm app ./vendor/bin/rector process --dry-run  # preview them
./docker/verify-image.sh                                  # verify the built image has the required toolchain
```

Never run `php`, `composer`, or `artisan` directly on the host — there is
nothing installed there to run them with.

## Decisions that shape this setup

See `CLAUDE.md` for the non-negotiable decisions behind this environment
(PostgreSQL only, no Node.js, WeasyPrint over Browsershot, deployment
target, etc.) — read it before changing anything here.

## Where the design lives

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the
  system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what
  it is built from

Read the relevant spec before changing behaviour. The specs are the
authority.
