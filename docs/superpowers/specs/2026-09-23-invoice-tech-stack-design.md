# Invoice — Technology Stack Design

Date: 2026-09-23
Status: Approved (brainstorming complete)
Companion to: `2026-09-23-invoice-system-design.md`

## 1. Purpose

The feature design settles *what* the system does. This settles *what it
is built from* and *where it runs*.

It exists as a separate document because the two change on different
clocks: the feature rules come from German bookkeeping law and will
outlive several stacks, while hosting and libraries will be revisited.

### Governing principles, stated by the owner

1. **YAGNI.** Build what v1 needs, not what v2 might.
2. **Known packages over hand-rolled code.** Where a maintained package
   solves the problem, use it, and justify every exception.

Section 10 records where the second principle was deliberately not
followed, and why.

## 2. What the feature design demands of the stack

Four requirements do most of the choosing:

| Requirement | Consequence |
| --- | --- |
| Gapless numbering | A database with real `SELECT … FOR UPDATE`. Rules out SQLite, including in tests. |
| Frozen PDFs are the legal record | Durable object storage, never container disk. Versioning on. |
| ZUGFeRD / PDF/A-3 | A renderer that can be installed, and a compliance library. Rules out platforms with fixed runtimes. |
| Immutability and audit | Nothing that quietly rewrites rows. |

## 3. The stack

| Layer | Choice |
| --- | --- |
| Language / framework | PHP 8.5, Laravel, Filament 5 |
| Web runtime | FrankenPHP, plain mode (no Octane) |
| Local development | Docker Compose, hand-written Dockerfile (not Sail) |
| Production | DigitalOcean App Platform, same Dockerfile |
| Database | Managed PostgreSQL |
| Queue and cache | Database driver. No Redis. |
| File storage | DigitalOcean Spaces, versioning enabled |
| PDF rendering | `spatie/laravel-pdf` with the WeasyPrint driver |
| e-Rechnung | `horstoeko/zugferd` |
| Document types | `tightenco/parental` (single table inheritance) |
| Money | `brick/money` |
| Tests | Pest, run inside the Docker image, against Postgres |
| Static analysis | Larastan, level 8 |
| Formatting | Pint, Laravel preset |
| Automated refactoring | Rector, `app/` and `tests/` only; runs before Pint |
| CI | GitHub Actions |

## 4. Why DigitalOcean App Platform and not Laravel Cloud

Both were candidates. The PDF renderer decided it.

**Laravel Cloud cannot run WeasyPrint.** It offers a managed PHP runtime
with a fixed list of PHP extensions and no custom Dockerfile. Build
commands provide `composer` and `npm`, not `apt-get`. WeasyPrint is
Python plus native libraries (pango, cairo, harfbuzz); there is no static
binary to install during build. The same reasoning rules out Browsershot,
which needs Chrome.

**App Platform builds from a Dockerfile**, so the image that runs locally
is the image that runs in production.

The alternatives on Laravel Cloud were each worse:

- **dompdf** — works anywhere, but table-based layout and dated CSS for
  the one part of the system that gets fiddled with repeatedly.
- **An external render service** (Gotenberg elsewhere) — more operational
  surface than the Dockerfile it was meant to avoid.
- **Cloudflare Browser Rendering** — posts every invoice's HTML, with
  customer names, addresses and amounts, to a third party. Drags an AVV
  and a GDPR assessment into a project that has neither. Rejected.

## 5. Runtime and deployment

### 5.1 The image

One Dockerfile, used for local development and CI.

It carries the toolchain only — it has no `COPY` of application code and no
production entrypoint, because the local and CI workflows bind-mount the
source. The production stage that adds those is part of the deployment plan,
not this one. What the shared base guarantees is that the renderer, its fonts
and the PHP extensions are identical everywhere.

Base: `dunglas/frankenphp` on PHP 8.5. On top:

- PHP extensions: `pdo_pgsql`, `intl`, `bcmath`, `zip`, `gd`, `opcache`, `pcntl`
- WeasyPrint and its native stack (pango, cairo, harfbuzz — including
  `libharfbuzz-subset0`, a separate package and the one WeasyPrint subsets
  fonts with)
- Fonts with full German coverage, from Debian stable (see §5.1 on pinning)

**The image will be large.** WeasyPrint's dependency tree adds a few
hundred megabytes over a plain PHP image. This is the price of the chosen
renderer, paid once at build time.

**What is pinned, and what is not.** The WeasyPrint version and its full
Python dependency set are pinned (`docker/requirements.txt`), because the
renderer's own version decides layout. PyPI never removes versions, so this
pins safely.

This paragraph used to rest the argument on `fontTools`, on the grounds that
it subsets and embeds the fonts and is in the pinned set. That stopped being
true when `libharfbuzz-subset0` was added: WeasyPrint subsets with HarfBuzz
whenever it is present, and falls back to fontTools only when it is not. The
subsetter is now an apt package, unpinned like the fonts — and covered by the
same reasoning below.

**Fonts are deliberately NOT apt-version-pinned.** Exact apt pins break the
build when Debian point releases rotate old versions off the mirror, and they
buy less than they appear to: document immutability comes from freezing each
PDF *with its fonts embedded* and recording its SHA-256 (§4, §7.3), not from
build reproducibility. A font update therefore cannot alter a document already
issued — only ones issued afterwards. Fonts come from Debian stable, which is
effectively frozen for the release.

### 5.2 Local

Docker Compose, two services in v1: app (FrankenPHP) and Postgres. No queue
worker, because nothing is queued yet (§5.3); it joins the compose file
with v2, matching production.

Files use the `local` disk in development rather than running MinIO. The
application code is identical either way because it goes through
Laravel's filesystem abstraction, and these files are only ever read and
written by the application — never served by public URL — so there is no
S3-specific behaviour to mirror.

### 5.3 Production

**v1 runs one component**: web (FrankenPHP), plus managed Postgres and a
Spaces bucket.

There is no worker in v1 because nothing is queued — issuing, rendering
and sending are all synchronous (§7.1, §8). The queue driver is
configured as `database` so that v2 enables it by adding a component.

**v2 adds a worker component** running `queue:work` and `schedule:work`
under supervisord. App Platform has no native cron, so the scheduler is a
long-running process rather than a platform feature.

Configuration is entirely environment variables, except per-company SMTP,
which is per-company data and lives in the database.

## 6. Data layer

### 6.1 PostgreSQL

Chosen over MySQL for stricter types, better `jsonb` handling for the
frozen snapshot block, and cleaner locking semantics. Nothing in the
design requires MySQL.

### 6.2 Money

`brick/money`. Amounts are `bigint` columns of integer cents, cast to
`Money` objects.

This is the clearest case for a package in the project: explicit rounding
modes map directly onto the EN16931 rounding order, and the type system
makes float arithmetic on money impossible rather than merely discouraged.

### 6.3 The frozen snapshot block

A `jsonb` column with a plain custom cast to a readonly DTO.

`spatie/laravel-data` was considered and rejected: this is one structure
in one place, not a pattern used across the application, and a 40-line
cast is less to carry than the package. Recorded as a deliberate
exception to the "prefer packages" principle.

### 6.4 Tenancy

**Filament 5's native tenancy**, plus a `BelongsToCompany` trait.

Filament handles the panel: company switcher, scoped resources. The trait
adds a global scope and fills `company_id` on create, covering what
Filament does not — console commands, queued jobs, tests.

**A queue worker has no current company.** Jobs must carry the company
explicitly and establish it before touching data. An implicit ambient
tenant is exactly the mechanism by which one company's invoice leaks into
another's. The global scope has a single explicit escape hatch, used
deliberately and audited, rather than being loosely bypassable.

No multi-tenancy package is used. `stancl/tenancy` and
`spatie/laravel-multitenancy` both solve multi-database tenancy, which
this is not.

### 6.5 Document types

`tightenco/parental`. `Document` carries a `type` column; `Invoice`,
`Storno` and `Gutschrift` are child classes.

`Document::query()` returns all three as their proper classes.
`Invoice::query()` scopes itself to invoices.

Alternatives considered: hand-rolled `newFromBuilder` (owns the edge
cases in relations, factories and Filament, which is where thirty lines
becomes eighty), and a single model with type-behaviour objects (works,
but shared behaviour that should differ tends to quietly stay shared).
Parental is the fallback-friendly choice: moving to behaviour objects
later is contained.

### 6.6 Numbering

**Written in this project, not taken from a package.** A `NumberRange`
model per company (prefix, pattern, padding, reset mode, next value, last
reset year) and a `DrawNextNumber` action performing the locked
read-and-increment. Roughly sixty lines.

`eg-mohamed/referenceable` was proposed and its source read. It is a
sound package but a poor fit here on two counts:

1. Its `HasReference` trait assigns on `static::creating` — at draft
   creation. This design draws the number at *issue*, which is the entire
   basis of gaplessness.
2. Its format configuration lives in model class properties. This design
   needs per-company configuration from the companies table.

Using it would mean bypassing the trait and calling the generator
directly, supplying the format ourselves — taking one method
(`getNextSequentialNumber`, a `lockForUpdate` on a counter row) and
writing everything around it.

Gaplessness is the property the system most needs to be able to defend.
It should be readable in this project's own code.

## 7. The document pipeline

### 7.1 Sequence

Blade → HTML → WeasyPrint → `horstoeko/zugferd` → Spaces, all inside the
issue transaction.

1. A Blade view renders the invoice using **CSS Paged Media** — `@page`
   margins, a running footer for the company identity block, page
   counters for "Seite 1 von 2". This is what WeasyPrint is good at and
   why it beats Chrome for this document.
2. `spatie/laravel-pdf` with the WeasyPrint driver produces an ordinary
   PDF.
3. `horstoeko/zugferd` builds the EN16931 XML.
4. The XML is validated against the XSD (§10.2).
5. The library merges XML and PDF into a PDF/A-3 with correct Factur-X
   XMP metadata.
6. The file is written to Spaces under
   `companies/{company}/documents/{year}/{number}.pdf`, and its SHA-256
   is stored on the document row.

Neither the compliance metadata nor the XML structure is code written
here.

### 7.2 Ordering against the transaction

Object storage is not transactional, so the order is deliberate:

> **Upload before commit.**

If the upload succeeds and the transaction then fails, the result is an
orphaned object in Spaces — harmless, and no number was consumed. If the
transaction commits first and the upload fails, the result is an issued,
numbered, legally existing invoice with no document behind it.

The first is litter, collectable by comparing keys against the table. The
second is a hole in the books.

### 7.3 The SHA-256

Cheap to compute and stored on the document row. It provides proof that a
file served in 2029 is byte-identical to the one issued.

### 7.4 Accepted costs

**Issuing is slow and holds a lock.** Rendering takes a second or two,
and the number-range row is locked throughout. Invisible for one person
issuing by hand; it would matter under real concurrency. This is the
direct price of the gapless guarantee.

**Issuing is synchronous.** A transaction cannot span a queued job, and
the user needs to know the document exists. Queuing would mean issuing
something and learning later whether it succeeded.

## 8. Mail

**Sending is synchronous in v1.** The feature design justifies
fire-and-forget delivery precisely because the user presses send and sees
a failure on screen. Queuing the send would break that justification by
moving the failure into `failed_jobs`, where nobody looks.

**Per-company SMTP.** Credentials live on the company row under Laravel's
`encrypted` cast. The mailer is constructed at send time with
`Mail::build([...])`, the built-in on-demand mailer API. No package, and
no mail configuration in environment variables, because each company has
its own.

**Email templates** are rows per company per document type. Placeholders
are substituted by **plain string replacement** — `{invoice_number}`,
`{amount}`, `{due_date}`.

> Explicitly **not** `Blade::render()` on stored content. Rendering
> user-editable text as Blade is arbitrary PHP execution. It is harmless
> while there is one user and a serious hole the moment the system gains
> others. The safe version costs nothing.

**Sending, concretely:** load the stored PDF from Spaces (never
re-render), substitute the template, build the company's mailer, send,
apply the optional BCC. On success write the audit entry. On a transport
exception write a "send failed" audit entry with the reason and surface
it on screen; the document stays issued and the user presses send again.

## 9. Durability, backup and retention

The frozen PDFs and the database together *are* the accounting record.
Losing them is losing the books.

- **Spaces versioning is enabled** on the bucket. It is off by default
  and can only be enabled through the DigitalOcean API — this is a
  required setup step, not a default.
- **Objects are write-once.** Nothing in the application overwrites or
  deletes a document file. Versioning is protection against operator
  error and accident, not normal operation.
- **Managed Postgres automated backups** are relied on for the database.
- **The SHA-256 on each document row** allows detecting silent
  corruption, independently of the storage provider.

**Retention:** German law requires accounting documents to be kept for
eight years (reduced from ten in 2025). Nothing in the system deletes
documents, so retention is satisfied by not implementing deletion.
Confirm the current period with a tax advisor rather than relying on this
document.

## 10. Dependencies

### 10.1 Runtime

Beyond Laravel and Filament:

| Package | For |
| --- | --- |
| `tightenco/parental` | Three document classes over one table |
| `brick/money` | Money arithmetic and rounding |
| `spatie/laravel-pdf` | HTML to PDF via the WeasyPrint driver |
| `pontedilana/php-weasyprint` | The PHP wrapper that driver requires |
| `horstoeko/zugferd` | EN16931 XML and PDF/A-3 embedding |

Five runtime dependencies. `filament/filament` and `laravel/framework` are the
platform, not additions to that budget.

Development: `pestphp/pest`, `larastan/larastan`, `laravel/pint`,
`rector/rector`, `driftingly/rector-laravel`.

### 10.2 Two levels of XML validation

The feature design requires the XML to be validated inside the issue
transaction. That splits into two levels, because the thorough validator
cannot run per invoice:

- **XSD validation, at runtime inside the transaction.** Pure PHP via
  libxml, milliseconds. Catches structural breakage. This is what guards
  the issue operation.
- **The official KoSIT / Mustang validator, in CI.** Full Schematron,
  business rules the XSD cannot express. It is a **Java** tool, so it runs
  as a CI job and never enters the production image.

### 10.3 Deliberately excluded

| Not used | Why |
| --- | --- |
| `eg-mohamed/referenceable` | Wrong assignment point and configuration location (§6.6) |
| `spatie/laravel-data` | One DTO does not justify it (§6.3) |
| `stancl/tenancy`, `spatie/laravel-multitenancy` | Solve multi-database tenancy; not this problem |
| Redis | Database queue and cache suffice at this volume |
| Laravel Octane | Throughput that will never be needed, paid for in state-leak bugs |
| Laravel Sail | Owner prefers a hand-written Dockerfile |

## 11. Testing and CI

### 11.1 Tests run inside the Docker image

Not against a PHP installed by a CI action. The image carries WeasyPrint
and the same fonts as production; testing against anything else tests a
renderer that is not shipped. CI builds the Dockerfile and runs Pest
inside it, making local, CI and production the same environment by
construction.

### 11.2 Tests run against real PostgreSQL

Never SQLite. This is not a preference. The gapless guarantee rests on
`SELECT … FOR UPDATE`, which SQLite does not implement — a green suite on
SQLite would actively misreport the property the system most depends on.
CI runs a Postgres service container.

### 11.3 The concurrency test

Asserting that the query log contains `for update` proves nothing.

The real test forks two processes that both call `DrawNextNumber` against
the same range and asserts they receive distinct, consecutive values with
no gap. `pcntl` is installed in the image for this purpose, and
`docker/verify-image.sh` asserts its presence so it cannot silently vanish.

It is the most awkward test in the suite and the one that actually
protects the guarantee.

### 11.4 Golden fixtures for e-Rechnung

The KoSIT validator job runs against a fixed set of generated documents:

- An invoice mixing 19% and 7%
- A Kleinunternehmer invoice at 0%
- A Storno
- A Gutschrift

When the validator or the standard changes, that job reports it before a
customer's software does.

### 11.5 Rounding

Table-driven, with expected values written by hand from the EN16931
rules. A test that captures what the code currently produces cannot
detect that the code is wrong.

### 11.6 Quality gates

Larastan at **level 8** with `treatPhpDocTypesAsCertain`, raised toward
max as the code settles. Pint with the Laravel preset, enforced as
`pint --test`.

Rector, scoped to `app/` and `tests/`, enforced as `rector process
--dry-run`. Rule sets: PHP up-to-8.5, dead code, code quality, type
declarations, and the Laravel upgrade and idiom sets. `config/` and
`bootstrap/` are excluded on purpose — they ship with Laravel and are
replaced wholesale by framework upgrades, so rewriting them would turn every
future skeleton diff into a merge conflict.

**Rector runs before Pint.** Rector's output is not Pint-formatted, and the
two only reach a fixed point in that order.

### 11.7 CI jobs on push

1. Rector, `--dry-run`, so a missed refactor fails rather than rewrites
2. Pint
3. Larastan
4. Pest, in-image, against Postgres
5. The ZUGFeRD validator

## 12. Code organization

Plain Laravel layout. No `src/`, no modules, no DDD folder structure.

- **`app/Models`** — `Company`, `Customer`, `Document` with its three
  parental children, `DocumentLine`, `Payment`, `TaxRate`,
  `PaymentTerm`, `NumberRange`, `EmailTemplate`, `AuditEntry`, `Unit`
- **`app/Actions`** — one invokable class per named operation:
  `IssueDocument`, `CorrectInvoice`, `CreditInvoice`, `DrawNextNumber`,
  `RecordPayment`, `SendDocument`, `DuplicateDocument`. Plain classes, no
  package.
- **`app/Pdf`** — the WeasyPrint render step and the horstoeko assembly
  step, separately, so each is testable alone
- **`app/Filament`** — resources and pages

`app/Actions` is where the feature design's "issue is one named
operation, not edit-screen logic" becomes concrete, and it is what v2's
recurring job will call.

## 13. Known costs, accepted deliberately

1. **A large container image.** WeasyPrint's native dependencies. Paid
   once at build time.
2. **Issuing holds a lock for the duration of PDF rendering.** The direct
   cost of gapless numbering. Fine for one user; a real constraint under
   concurrency.
3. **Orphaned objects in Spaces are possible** when an issue transaction
   fails after upload. Deliberate — the alternative failure mode is far
   worse (§7.2). Cleanup is a housekeeping job, not a correctness fix.
4. **One Dockerfile to maintain**, including security updates to the
   WeasyPrint stack. This is what was traded for keeping the renderer.

## 14. Decisions

| Decision | Choice |
| --- | --- |
| Platform | DigitalOcean App Platform with a custom Dockerfile |
| Rejected platform | Laravel Cloud — cannot run WeasyPrint |
| Web runtime | FrankenPHP, plain mode |
| Octane | No |
| Local development | Docker Compose, no Sail, `local` disk |
| Database | PostgreSQL |
| Queue and cache | Database driver; no worker component in v1 |
| Scheduler | v2 only, `schedule:work` under supervisord |
| Storage | DO Spaces, versioning enabled, write-once |
| PDF renderer | WeasyPrint via `spatie/laravel-pdf` |
| e-Rechnung | `horstoeko/zugferd` |
| XML validation | XSD at runtime; KoSIT validator in CI |
| Document classes | `tightenco/parental` |
| Money | `brick/money` |
| Snapshot block | `jsonb` + custom cast, no package |
| Tenancy | Filament native + `BelongsToCompany` trait |
| Numbering | Written here, not `referenceable` |
| Mail | Synchronous in v1; per-company SMTP via `Mail::build()` |
| Templates | String replacement, never `Blade::render()` |
| Tests | Pest, in-image, real Postgres |
| Static analysis | Larastan level 8 |
| Formatting | Pint, Laravel preset |
| Automated refactoring | Rector, `app/` and `tests/` only; runs before Pint |
| Code layout | Plain Laravel, with `app/Actions` |
