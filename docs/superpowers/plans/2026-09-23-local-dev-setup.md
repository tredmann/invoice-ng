# Local Development Environment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Progress tracking is external.** A task guide lives in Outline at
> <https://heimdall.tail1ec8f7.ts.net/doc/local-dev-setup-task-guide-wuiOXTFgBW>.
> After completing each task here, tick that task's boxes there before
> starting the next one. The Outline guide is the tracking surface; this
> file is the source of truth for what to actually do. If the two ever
> disagree, this file wins and the guide gets corrected.

**Goal:** A working local development environment — Docker image, Compose stack, Laravel with the project's packages, quality tooling, a verified WeasyPrint renderer, and a Filament panel with login and an empty dashboard.

**Architecture:** One Dockerfile carrying PHP 8.4 (FrankenPHP), the PHP extensions the project needs, and WeasyPrint with its native rendering stack. Compose runs that image plus PostgreSQL. The application is installed *through* the container, so no PHP, Composer or Python is ever required on the host.

**Tech Stack:** FrankenPHP · PHP 8.4 · Laravel 13 · Filament 5 · PostgreSQL 17 · WeasyPrint · Pest · Larastan · Pint

**Spec:**
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` (primary)
- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` (context)

## Scope

This plan builds **only the development environment**. It deliberately does not build:

- Any domain model — no companies, customers, documents, numbering, or money handling
- CI workflows (the stack spec §11 defines them; they are a separate plan)
- The production App Platform deployment
- Any invoice layout or ZUGFeRD generation

The deliverable is: `docker compose up`, log in, see an empty dashboard, and `pest` is green.

## Global Constraints

Copied from the stack spec. Every task's requirements implicitly include these.

- **PHP 8.4.** Base image `dunglas/frankenphp:php8.4-bookworm`.
- **PostgreSQL 17.** Never SQLite — *including in tests*. The gapless-numbering guarantee rests on `SELECT … FOR UPDATE`, which SQLite does not implement.
- **FrankenPHP in plain mode.** Octane is explicitly not used.
- **PHP extensions required:** `pdo_pgsql`, `intl`, `bcmath`, `zip`, `gd`.
- **No Node.js in the image.** No custom Filament theme exists yet, so nothing needs building. Revisit only when a theme is added.
- **Fonts and the WeasyPrint version are pinned.** A renderer that drifts produces documents that no longer match their stored PDFs.
- **Larastan level 8** with `treatPhpDocTypesAsCertain`.
- **Runtime dependencies are limited to five** beyond Laravel and Filament: `tightenco/parental`, `brick/money`, `spatie/laravel-pdf`, `pontedilana/php-weasyprint`, `horstoeko/zugferd`.
- **Timezone `Europe/Berlin`, locale `de`**, fallback `en`.

## Review Focus

Five failure modes the specs imply that a naive setup would not catch. Each has a test assigned to the task that owns the code.

1. **Tests silently running on SQLite.** Laravel's default `phpunit.xml` overrides the connection to in-memory SQLite. A green suite would then be misreporting the one property the system most depends on. → Task 2.
2. **Timezone and locale left at UTC/English.** Invoice dates and Leistungsdatum are legally meaningful; a date one day off at the year boundary lands in the wrong tax period. → Task 2.
3. **WeasyPrint present but unable to render German text.** The binary can run and still produce boxes where umlauts should be, if fonts are missing. Only a render containing `ÄÖÜß` catches it. → Task 6.
4. **Document storage hardcoded to the local disk.** The stack spec requires production to use Spaces without a code change. A hardcoded disk would only surface at deployment. → Task 5.
5. **The Filament panel reachable without authentication.** A misconfigured panel serving the dashboard to guests. → Task 7.

---

## Task 1: The container image and Compose stack

**Files:**
- Create: `Dockerfile`
- Create: `compose.yaml`
- Create: `.dockerignore`
- Create: `docker/postgres/init-test-db.sql`
- Create: `docker/verify-image.sh`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: a Compose service named `app` whose image has PHP 8.4, the five extensions, `composer` and `weasyprint` on `$PATH`, working directory `/app`; a service named `db` exposing PostgreSQL 17 with databases `invoice` and `invoice_test`, user `invoice`, password `secret`

- [ ] **Step 1: Create the working branch**

```bash
git checkout main
git pull
git checkout -b feat/local-dev-setup
```

- [ ] **Step 2: Write the verification script (this is the failing test)**

Create `docker/verify-image.sh`:

```bash
#!/usr/bin/env bash
# Asserts the built image satisfies the stack spec. Run from the repo root.
set -euo pipefail

docker compose run --rm --no-deps --entrypoint sh app -c '
  set -e

  for ext in pdo_pgsql intl bcmath zip gd; do
    php -m | grep -qx "$ext" || { echo "FAIL: missing PHP extension: $ext"; exit 1; }
  done
  echo "ok: php extensions"

  php -r "exit(version_compare(PHP_VERSION, \"8.4\", \">=\") ? 0 : 1);" \
    || { echo "FAIL: PHP 8.4+ required, found $(php -r "echo PHP_VERSION;")"; exit 1; }
  echo "ok: php $(php -r "echo PHP_VERSION;")"

  weasyprint --version || { echo "FAIL: weasyprint not runnable"; exit 1; }
  composer --version >/dev/null || { echo "FAIL: composer not runnable"; exit 1; }
  echo "ok: weasyprint and composer"
'

echo "PASS: image satisfies the stack spec"
```

```bash
chmod +x docker/verify-image.sh
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./docker/verify-image.sh`
Expected: FAIL — no `compose.yaml` exists yet, so Docker Compose errors out.

- [ ] **Step 4: Write `.dockerignore`**

```
.git
.github
docs
vendor
node_modules
.env
storage/logs/*
storage/framework/cache/*
```

- [ ] **Step 5: Write the Dockerfile**

```dockerfile
FROM dunglas/frankenphp:php8.4-bookworm

# PHP extensions required by the stack spec.
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        bcmath \
        zip \
        gd \
        opcache

# WeasyPrint's native rendering stack, plus fonts with full German coverage.
# Fonts are installed deliberately rather than inherited: an invoice that
# renders differently after a base image update no longer matches the PDF
# that was stored and sent.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        git \
        unzip \
        python3 \
        python3-pip \
        libpango-1.0-0 \
        libpangoft2-1.0-0 \
        libharfbuzz0b \
        libcairo2 \
        libgdk-pixbuf-2.0-0 \
        fonts-dejavu \
        fonts-liberation2 \
 && rm -rf /var/lib/apt/lists/*

# Version pinned in Step 9 once the first build resolves it.
RUN pip3 install --break-system-packages --no-cache-dir weasyprint

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
```

- [ ] **Step 6: Write `docker/postgres/init-test-db.sql`**

```sql
-- Runs once, on first initialisation of the data volume.
-- Tests run against their own database so a test run never touches dev data.
CREATE DATABASE invoice_test OWNER invoice;
```

- [ ] **Step 7: Write `compose.yaml`**

```yaml
services:
  app:
    build: .
    ports:
      - "8080:80"
    volumes:
      - .:/app
    environment:
      SERVER_NAME: ":80"
    depends_on:
      db:
        condition: service_healthy

  db:
    image: postgres:17
    environment:
      POSTGRES_DB: invoice
      POSTGRES_USER: invoice
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./docker/postgres/init-test-db.sql:/docker-entrypoint-initdb.d/init-test-db.sql:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U invoice -d invoice"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  pgdata:
```

- [ ] **Step 8: Build the image**

Run: `docker compose build`
Expected: builds successfully. The image is large — a few hundred MB over a plain PHP image — which is the documented price of WeasyPrint.

- [ ] **Step 9: Pin the WeasyPrint version**

Run: `docker compose run --rm --no-deps --entrypoint weasyprint app --version`

Take the version it reports (for example `WeasyPrint version 66.0`) and edit the Dockerfile line to pin it exactly:

```dockerfile
RUN pip3 install --break-system-packages --no-cache-dir weasyprint==66.0
```

Then rebuild: `docker compose build`

- [ ] **Step 10: Run the verification script to verify it passes**

Run: `./docker/verify-image.sh`
Expected: `PASS: image satisfies the stack spec`

- [ ] **Step 11: Verify Postgres starts and both databases exist**

```bash
docker compose up -d db
docker compose exec db psql -U invoice -lqt | cut -d'|' -f1 | grep -qw invoice_test && echo "ok: invoice_test exists"
```

Expected: `ok: invoice_test exists`

- [ ] **Step 12: Commit**

```bash
git add Dockerfile compose.yaml .dockerignore docker/
git commit -m "build: add Docker image and Compose stack

FrankenPHP on PHP 8.4 with the extensions the stack spec requires, plus
WeasyPrint and pinned fonts. Compose adds PostgreSQL 17 with a separate
invoice_test database, so tests never run against SQLite or dev data."
```

---

## Task 2: Laravel, wired to PostgreSQL, with a green test suite

**Files:**
- Create: the Laravel skeleton (many files)
- Modify: `.env`, `.env.example`
- Modify: `phpunit.xml`
- Create: `tests/Feature/EnvironmentTest.php`

**Interfaces:**
- Consumes: the `app` and `db` services from Task 1
- Produces: a booting Laravel application at `http://localhost:8080`; `docker compose run --rm app php artisan` works; `docker compose run --rm app ./vendor/bin/pest` runs against PostgreSQL

- [ ] **Step 1: Install Laravel through the container**

The repo already contains `docs/`, `LICENSE` and the Docker files, so `create-project` cannot run in place. Build it in a temp directory inside the container and copy it over:

```bash
docker compose run --rm --no-deps --entrypoint sh app -c '
  composer create-project laravel/laravel /tmp/laravel --no-interaction &&
  cp -a /tmp/laravel/. /app/
'
```

- [ ] **Step 1b: Restore the workspace ignore rule**

`cp -a` overwrites the repo's `.gitignore` with Laravel's. Ours carries the
SDD workspace ignore, and losing it would let `.superpowers/` be committed
by the next `git add -A`. Re-add it as the first line:

```bash
printf '.superpowers/\n' | cat - .gitignore > .gitignore.new && mv .gitignore.new .gitignore
git check-ignore -q .superpowers && echo "ok: workspace ignored"
```

Expected: `ok: workspace ignored`

On macOS the bind mount maps the files to your own user. On a Linux host
the container runs as root and the copied files will be root-owned; fix
that with `sudo chown -R "$USER:$USER" .` before continuing.

- [ ] **Step 2: Point the environment at PostgreSQL and Germany**

Edit `.env` so these keys read exactly:

```env
APP_URL=http://localhost:8080
APP_TIMEZONE=Europe/Berlin
APP_LOCALE=de
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=invoice
DB_USERNAME=invoice
DB_PASSWORD=secret
```

Apply the same values to `.env.example`, except leave `DB_PASSWORD=` empty there.

- [ ] **Step 3: Generate the application key and run migrations**

```bash
docker compose up -d db
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
```

Expected: migrations run against PostgreSQL without error.

- [ ] **Step 4: Verify the application serves**

```bash
docker compose up -d
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080
```

Expected: `200`

- [ ] **Step 5: Install Pest**

```bash
docker compose run --rm app composer require pestphp/pest --dev --with-all-dependencies --no-interaction
docker compose run --rm app ./vendor/bin/pest --init
```

If the skeleton already shipped with Pest, `require` is a no-op and
`--init` reports that `tests/Pest.php` exists. Both are fine — move on.

- [ ] **Step 6: Force tests onto PostgreSQL**

Laravel's default `phpunit.xml` overrides the database to in-memory SQLite. Leaving it would make the suite silently misreport the locking behaviour the whole design rests on.

In `phpunit.xml`, inside `<php>`, ensure these two entries exist with exactly these values, replacing any `sqlite` / `:memory:` versions (they may be present but commented out):

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="invoice_test"/>
```

- [ ] **Step 7: Enable database refreshing for feature tests**

In `tests/Pest.php`, change the `Feature` binding to include `RefreshDatabase`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 8: Write the failing test**

Create `tests/Feature/EnvironmentTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

it('runs tests against postgresql, never sqlite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('runs tests against a separate database', function () {
    expect(DB::connection()->getDatabaseName())->toBe('invoice_test');
});

it('is configured for german invoicing', function () {
    expect(config('app.timezone'))->toBe('Europe/Berlin')
        ->and(config('app.locale'))->toBe('de')
        ->and(config('app.fallback_locale'))->toBe('en');
});
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/EnvironmentTest.php`
Expected: 3 passed.

If the driver assertion fails, `phpunit.xml` still carries the SQLite override from Step 6.

- [ ] **Step 10: Run the whole suite**

Run: `docker compose run --rm app ./vendor/bin/pest`
Expected: all green.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat: install Laravel wired to PostgreSQL

Installed through the container, so no PHP or Composer is needed on the
host. Tests are forced onto PostgreSQL against a separate invoice_test
database: the stack spec rules out SQLite, because SELECT FOR UPDATE is
what the gapless numbering guarantee rests on."
```

---

## Task 3: Pint and Larastan

**Files:**
- Create: `pint.json`
- Create: `phpstan.neon`
- Create: `phpstan-baseline.neon` (only if Step 5 requires it)

**Interfaces:**
- Consumes: the Laravel application from Task 2
- Produces: `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` both pass

- [ ] **Step 1: Install Larastan**

```bash
docker compose run --rm app composer require larastan/larastan --dev --no-interaction
```

Pint ships with the Laravel skeleton and needs no install.

- [ ] **Step 2: Write `pint.json`**

```json
{
    "preset": "laravel"
}
```

- [ ] **Step 3: Write `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 8
    treatPhpDocTypesAsCertain: false

    paths:
        - app
        - config
        - database
        - routes
        - tests
```

- [ ] **Step 4: Run Pint**

```bash
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/pint --test
```

Expected: the second command reports no style issues.

- [ ] **Step 5: Run Larastan**

Run: `docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M`

Expected: `[OK] No errors`.

If the untouched Laravel skeleton reports errors at level 8, freeze them rather than lowering the level — the level is a spec constraint, and skeleton noise is not our code:

```bash
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline
```

Then add to `phpstan.neon` under `includes`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon
```

Re-run the analyse command and confirm `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add pint.json phpstan.neon phpstan-baseline.neon composer.json composer.lock
git commit -m "chore: add Pint and Larastan at level 8

Level 8 is a stack spec constraint. Skeleton noise is baselined rather
than answered by lowering the level."
```

---

## Task 4: Laravel Boost

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: whatever `boost:install` generates

**Interfaces:**
- Consumes: the Laravel application from Task 2
- Produces: Boost's MCP server available to AI tooling working in this repo

- [ ] **Step 1: Install Boost**

```bash
docker compose run --rm app composer require laravel/boost --dev --no-interaction
```

- [ ] **Step 2: Run the installer**

Run: `docker compose run --rm app php artisan boost:install`

This is interactive. Choose **Claude Code** as the editor. Decline anything offering Herd-specific integration — this project runs in Docker, not Herd.

- [ ] **Step 3: Verify Boost registered its commands**

Run: `docker compose run --rm app php artisan list | grep boost`
Expected: at least one `boost:` command is listed.

- [ ] **Step 4: Confirm the suite is still green**

```bash
docker compose run --rm app ./vendor/bin/pest
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both pass.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: add Laravel Boost for AI tooling"
```

---

## Task 5: Runtime packages and the documents disk

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/invoice.php`
- Modify: `.env`, `.env.example`
- Create: `tests/Feature/PackagesTest.php`

**Interfaces:**
- Consumes: the Laravel application from Task 2
- Produces: `config('invoice.documents_disk')` returning the name of the filesystem disk that frozen documents are written to — `local` in development, `s3` in production, changed by environment variable alone

- [ ] **Step 1: Install the runtime packages**

The stack spec allows exactly these five beyond Laravel and Filament:

```bash
docker compose run --rm app composer require --no-interaction \
  tightenco/parental \
  brick/money \
  spatie/laravel-pdf \
  pontedilana/php-weasyprint \
  horstoeko/zugferd
```

`pontedilana/php-weasyprint` is the PHP wrapper that `spatie/laravel-pdf`'s WeasyPrint driver requires; without it the driver throws on construction.

- [ ] **Step 2: Create `config/invoice.php`**

```php
<?php

return [

    /*
     * The filesystem disk that frozen invoice documents are written to.
     *
     * Documents are the legal record, so production writes them to object
     * storage rather than container disk. Development uses the local disk.
     * This is a configuration seam, not a code branch: nothing in the
     * application names a disk directly.
     */
    'documents_disk' => env('INVOICE_DOCUMENTS_DISK', 'local'),

];
```

- [ ] **Step 3: Add the environment key**

Add to both `.env` and `.env.example`:

```env
INVOICE_DOCUMENTS_DISK=local
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/PackagesTest.php`:

```php
<?php

use Brick\Money\Money;
use Illuminate\Support\Facades\Storage;

it('has the runtime packages the stack spec allows', function () {
    expect(trait_exists(Parental\HasChildren::class))->toBeTrue()
        ->and(class_exists(Brick\Money\Money::class))->toBeTrue()
        ->and(class_exists(Spatie\LaravelPdf\Facades\Pdf::class))->toBeTrue()
        ->and(class_exists(Pontedilana\PhpWeasyPrint\Pdf::class))->toBeTrue()
        ->and(class_exists(horstoeko\zugferd\ZugferdDocumentBuilder::class))->toBeTrue();
});

it('does arithmetic on money without floats', function () {
    $total = Money::of('19.99', 'EUR')->multipliedBy(3);

    expect($total->getAmount()->__toString())->toBe('59.97');
});

it('resolves the documents disk from configuration', function () {
    expect(config('invoice.documents_disk'))->toBe('local');

    Storage::disk(config('invoice.documents_disk'))->put('probe.txt', 'ok');

    expect(Storage::disk(config('invoice.documents_disk'))->get('probe.txt'))->toBe('ok');

    Storage::disk(config('invoice.documents_disk'))->delete('probe.txt');
});
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/PackagesTest.php`
Expected: 3 passed.

If a `class_exists` assertion fails, that package did not install — check `composer.json` rather than adjusting the test.

- [ ] **Step 6: Confirm the gates are still green**

```bash
docker compose run --rm app ./vendor/bin/pint --test
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
```

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add runtime packages and the documents disk seam

Five runtime dependencies, as the stack spec allows. The documents disk
is named in configuration rather than in code, so production switches to
Spaces with an environment variable."
```

---

## Task 6: Prove WeasyPrint renders

**Files:**
- Modify: `.env`, `.env.example`
- Create: `tests/Feature/PdfRenderingTest.php`

**Interfaces:**
- Consumes: `spatie/laravel-pdf` and `pontedilana/php-weasyprint` from Task 5; the `weasyprint` binary from Task 1
- Produces: proof that the image can render a PDF containing German characters — the riskiest single assumption in the stack

- [ ] **Step 1: Select the WeasyPrint driver**

Add to both `.env` and `.env.example`:

```env
LARAVEL_PDF_DRIVER=weasyprint
```

The package resolves the binary from `$PATH` by default, which is where Task 1's image puts it. No config file needs publishing.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/PdfRenderingTest.php`:

```php
<?php

use Illuminate\Support\Facades\Process;
use Spatie\LaravelPdf\Facades\Pdf;

it('has the weasyprint binary available', function () {
    expect(Process::run('weasyprint --version')->successful())->toBeTrue();
});

it('renders a pdf containing german characters', function () {
    $path = storage_path('app/testing/render-smoke.pdf');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    // The umlauts are the point. A missing font stack produces a valid PDF
    // full of empty boxes, which no smoke test on file size would catch.
    Pdf::html('<h1>Rechnung</h1><p>Größe · Übertrag · Änderung · Weiß</p>')
        ->format('a4')
        ->save($path);

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path, false, null, 0, 5))->toBe('%PDF-')
        ->and(filesize($path))->toBeGreaterThan(1000);

    // Deliberately NOT asserting on the PDF's internals here. Font
    // dictionaries live inside compressed object streams, so grepping the
    // bytes would fail for reasons unrelated to fonts. Whether the umlauts
    // are letters or empty boxes is checked by eye in the next step.
    unlink($path);
});
```

- [ ] **Step 3: Run the test to verify it passes**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/PdfRenderingTest.php`
Expected: 2 passed.

If the render throws `CouldNotGeneratePdf::weasyPrintPackageNotInstalled`, `pontedilana/php-weasyprint` is missing — return to Task 5 Step 1.

- [ ] **Step 4: Look at the output once, by eye**

Automated assertions cannot tell you the umlauts rendered rather than boxed. Generate one PDF and open it:

```bash
docker compose run --rm app php artisan tinker --execute="\Spatie\LaravelPdf\Facades\Pdf::html('<h1>Rechnung</h1><p>Größe · Übertrag · Änderung · Weiß</p>')->format('a4')->save('storage/app/testing/eyeball.pdf');"
open storage/app/testing/eyeball.pdf
```

Confirm the umlauts are letters, not boxes. Then delete it:

```bash
rm storage/app/testing/eyeball.pdf
```

- [ ] **Step 5: Ignore the testing output directory**

Add to `.gitignore`:

```
/storage/app/testing
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "test: prove WeasyPrint renders German text

The riskiest assumption in the stack is that the image can render. The
test asserts on umlauts specifically: a missing font stack yields a
perfectly valid PDF full of empty boxes."
```

---

## Task 7: Filament panel, login, and an empty dashboard

**Files:**
- Create: `app/Providers/Filament/AdminPanelProvider.php` (generated)
- Modify: `routes/web.php`
- Modify: `bootstrap/providers.php` (generated)
- Create: `tests/Feature/PanelTest.php`

**Interfaces:**
- Consumes: the Laravel application from Task 2
- Produces: a Filament panel at `/admin` with login, an empty dashboard, and `/` redirecting to it

- [ ] **Step 1: Install Filament**

```bash
docker compose run --rm app composer require filament/filament:"^5.0" --no-interaction
docker compose run --rm app php artisan filament:install --panels
```

When asked for the panel ID, accept the default: `admin`.

- [ ] **Step 2: Redirect the root URL to the panel**

This application is an admin panel; there is no public front end. Redirecting also avoids Laravel's default welcome page, which references Vite — and the stack spec keeps Node.js out of the image.

Replace the contents of `routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
```

- [ ] **Step 3: Delete the unused welcome view and the test that asserted it**

Laravel's skeleton ships `tests/Feature/ExampleTest.php`, which asserts that
`GET /` returns 200. Step 2 just made it a 302, so that test now fails. Its
coverage is replaced by `PanelTest` below.

```bash
rm resources/views/welcome.blade.php
rm tests/Feature/ExampleTest.php
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/PanelTest.php`:

```php
<?php

use App\Models\User;

it('redirects the root url to the panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('refuses the dashboard to guests', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('serves the login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('shows the dashboard to an authenticated user', function () {
    $user = User::factory()->create();

    // Asserting on the user's name rather than on the word "Dashboard":
    // the app runs with locale=de and Filament ships German translations,
    // so any chrome string is a translation change away from breaking.
    // The name is data, and its presence proves the panel chrome rendered.
    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee($user->name);
});
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/PanelTest.php`
Expected: 4 passed.

- [ ] **Step 6: Create a development user**

```bash
docker compose run --rm app php artisan make:filament-user \
  --name="Tobias Redmann" \
  --email="tobias.redmann@gmail.com" \
  --password="password"
```

- [ ] **Step 7: Log in by hand**

```bash
docker compose up -d
open http://localhost:8080
```

Confirm: the root URL redirects to the panel, the login form appears, the credentials from Step 6 work, and an empty dashboard loads.

- [ ] **Step 8: Run every gate**

```bash
docker compose run --rm app ./vendor/bin/pest
docker compose run --rm app ./vendor/bin/pint --test
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
./docker/verify-image.sh
```

Expected: all four pass.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add Filament panel with login and empty dashboard

Root redirects to the panel: this is an admin application with no public
front end, and the default welcome page pulls in Vite, which the stack
spec deliberately keeps out of the image."
```

---

## Done

The environment is complete when all of these hold:

- `docker compose up -d` serves the panel at `http://localhost:8080`
- Logging in reaches an empty dashboard
- `./vendor/bin/pest` is green, running against PostgreSQL
- `./vendor/bin/pint --test` reports no issues
- `./vendor/bin/phpstan analyse` reports no errors at level 8
- `./docker/verify-image.sh` passes
- A rendered PDF shows German characters as letters

No domain model exists yet. That is the next plan.
