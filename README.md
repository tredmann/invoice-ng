# Invoice

A multi-company German invoicing application, built on Laravel 13 and Filament 5.

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

1. `docker compose build` — builds the `app` image (FrankenPHP, PHP 8.4, the
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
   <http://localhost:8080/admin>.

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
