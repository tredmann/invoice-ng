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
  dashboard, its customers (Kunden) and its settings (Einstellungen). With no
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
- **Settings in four tabs.** Under Einstellungen a company keeps its Firma
  (name, legal form, address, Handelsregister entry and logo), its Steuer, its
  Bank and its Nummernkreis. Everything saves together with one button.
- **Tax rates you can edit, with one marked as the default.** Every company
  starts with 19 %, 7 % and 0 %; you can rename them, add your own and mark a
  different one as the default a new invoice line will start from. Removing a
  rate never deletes it — it stops being offered, so an invoice already
  computed with it stays intact, and putting it back later picks the same rate
  up again. A Kleinunternehmer is not shown the list at all: §19 UStG means 0 %
  and there is nothing to choose.
- **A Zahlungsziel, per company and per customer.** The company sets the
  default — 14 Tage netto unless you say otherwise — and a customer can be
  given their own. A customer left on the company's default keeps following it;
  one with an agreed term of their own keeps that, even when the company's
  default changes later.
- **An invoice number range that cannot skip or repeat.** Choose a prefix, how
  many digits, where to start, whether the year appears in the number and
  whether counting restarts each January; the page shows the next number as you
  type. Numbers are drawn only at the moment of issuing, under a lock, so two
  invoices can never share one and a failure part-way through gives the number
  back instead of leaving a hole. Once a number has been issued the starting
  value can only be raised, never lowered, so nothing already sent to a customer
  can be handed out twice. The prefix prints on every document drawn from the
  range — a Storno and a Gutschrift included, not just Rechnungen.
- **The dashboard says what is still missing.** Instead of an empty screen, a
  company shows three first steps. The first is the honest one: it lists what
  §14 UStG still wants before an invoice can be issued — address, Steuernummer
  or USt-IdNr., the register entry of a GmbH or UG, and the number range — and
  separately what is merely recommended, like the bank details and the logo.
  A missing logo never stops an invoice.
- **Customers, kept per company.** Under Kunden, each company keeps its own
  customers — a Geschäftskunde, with an optional contact person and USt-IdNr.,
  or a Privatkunde — with billing address and billing email. Every new customer
  gets its company's next customer number (K-0001, K-0002, …) automatically,
  and that number is its address: `/admin/acme-gmbh/customers/K-0004` always
  opens the same customer. One search box finds customers by number, name,
  email or city. A customer of one company never appears under another.
- **A customer's page gathers what you know about them.** The header carries the
  name, whether they are a Geschäftskunde or a Privatkunde, and the customer
  number. Below it the master data sits in one card — billing address, contact
  person, billing email, USt-IdNr. and the date the customer was added — then
  three figures: revenue this year, open receivables, and how much of that is
  overdue. **Those three read 0,00 € for now**: they are counted from invoices,
  and invoicing is the next phase. The invoice list under them says so too.
- **Rechnungen, as drafts.** Under Rechnungen a company writes an invoice:
  pick the customer, the Rechnungsdatum, the Zahlungsziel and the Leistung,
  then type the Positionen — Bezeichnung, Menge, Einheit, Einzelpreis and
  Steuersatz — with the net, the Umsatzsteuer per Steuersatz and the
  Gesamtbetrag adding up under the rows as you go. The list shows every
  invoice with its customer, date and amount, and finds one by the customer or
  by what is written on it.
- **A draft stays a draft.** **Nothing can be issued yet**, so an invoice has
  no number, no due date and no PDF, and the list shows „—" where those will
  go. A draft is freely editable and can be deleted outright; once issuing
  exists, only its status will still be allowed to change and deleting will be
  refused — the application already enforces that, it simply has nothing to
  enforce it on. Drafts are counted nowhere: the customer's revenue and open
  receivables still read 0,00 €, because a draft is not revenue.
- **Only a customer of this company, and only an active one.** The picker
  offers the company's own customers, deactivated ones excluded — except on a
  draft that already names one, which keeps its recipient. A Kleinunternehmer
  is offered 0 % and no other Steuersatz.
- **Customers are deactivated, never deleted.** A deactivated customer stays in
  the list, greyed and marked "Deaktiviert", keeps its page and its number, and
  can be reactivated from its ⋮ menu or its page.
- **Companies are deactivated, never deleted.** A deactivated company drops out
  of the switcher but keeps its URL, so everything it is attached to stays
  readable. Deactivate and reactivate from the ⋮ menu on its tile and the
  "Deaktiviert" section of the start page — your last active company included.
- **German throughout.** The interface is German, and the application runs with
  the `de` locale and `Europe/Berlin`.

### Not built yet

**Ausstellen** — and everything that hangs off it: drawing the Belegnummer,
freezing the identity block, the ZUGFeRD PDF, sending it by email, recording
payments, Storno and Teilstorno, Gutschriften over a Vermittlungsprovision,
Mahnungen, recurring invoices, the period export for the tax advisor, and the
dashboard's figures. The E-Mail tab in the settings is not built either.

Everything an Ausstellvorgang needs *is* built and tested: the number range
and the locked, gapless draw, the money type and the VAT rounding, the tax
rates, the Zahlungsziel, the unit list, and now the Rechnung itself as a
draft. What is missing is the step that turns one into a Beleg.

`docs/superpowers/specs/2026-09-23-invoice-system-design.md` describes all of
it, and `CONTEXT.md` settles what each of those documents is called and why.

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
docker compose run --rm app php artisan storage:link
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
6. `php artisan storage:link` — links `public/storage` so uploaded company
   logos are served. Without it the logo field saves the file and the preview
   shows nothing.
7. `php artisan make:filament-user` — creates the account you'll actually
   log in with at `/admin`. It prompts for name, email, and password (or
   pass `--name=`, `--email=`, `--password=` non-interactively).
8. `docker compose up -d` — starts the stack in the background. The app
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

## When something goes wrong

**`Cannot connect to the Docker daemon`** — Docker Desktop is not running.
Start it (`open -a Docker` on a Mac), wait for it to settle, and retry. This is
the most likely reason any command above fails.

**`port is already allocated`** — something else holds 8080 or 5432. Find it
with `lsof -i :8080`, or change the left-hand number under `ports` in
`compose.yaml`.

**The page will not load right after `docker compose up -d`** — the app waits
for PostgreSQL to report healthy, which takes a few seconds from cold.
`docker compose ps` should show both services `healthy`.

**`SQLSTATE[08006] … no password supplied`** — `.env` is missing or stale.
`cp .env.example .env`, then `php artisan key:generate`.

**`SQLSTATE[42P01] … relation "…" does not exist`** — the database the browser
uses is a migration behind. Run `docker compose run --rm app php artisan
migrate`. A green test run does not protect you here: the suite runs against
`invoice_test` and rebuilds that schema every time, so it structurally cannot
notice that `invoice` is missing a table. Only loading the app finds it. **Any
wave that adds a migration needs this after pulling.**

**You want to start completely clean** — this destroys the database, including
your login account, after which the first-run sequence above applies again:

```sh
docker compose down -v
```

## Decisions that shape this setup

See `CLAUDE.md` for the non-negotiable decisions behind this environment
(PostgreSQL only, no Node.js, WeasyPrint over Browsershot, deployment
target, etc.) — read it before changing anything here.

## Where the design lives

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the
  system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what
  it is built from
- `CONTEXT.md` — what every concept in the domain is called, in German and in
  code
- `docs/adr/` — decisions that were hard to reverse, with the reasoning

Read the relevant spec before changing behaviour. The specs are the
authority.
