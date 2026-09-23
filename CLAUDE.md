# Invoice

A multi-company German invoicing application. Laravel 13 + Filament 5, PHP 8.4.

## Everything runs in Docker

Nothing is installed on the host — not PHP, not Composer, not Postgres, not WeasyPrint.
Never run `php`, `composer`, or `artisan` directly. Always go through the container:

```sh
docker compose up -d                                    # start the stack
docker compose run --rm app php artisan <command>       # artisan
docker compose run --rm app composer <command>           # composer
docker compose run --rm app ./vendor/bin/pest           # tests
docker compose run --rm app ./vendor/bin/pint           # formatting
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
```

The app serves at http://localhost:8080.

## Decisions that are not negotiable

- **PostgreSQL only, never SQLite — including in tests.** Invoice numbering must be
  gapless, which rests on `SELECT … FOR UPDATE`. SQLite does not implement it, so a
  suite green on SQLite would prove nothing.
- **No Node.js.** There is no frontend build. Nothing should reference `@vite`.
- **Deployment target is DigitalOcean App Platform, not Laravel Cloud.** Laravel Cloud
  cannot run WeasyPrint, which this project needs to render invoices.
- **Larastan runs at level 8.** If it reports errors, fix them or baseline them —
  never lower the level.
- Money is integer cents via `brick/money`. Never floats.

## Where the design lives

- `docs/superpowers/specs/2026-09-23-invoice-system-design.md` — what the system does
- `docs/superpowers/specs/2026-09-23-invoice-tech-stack-design.md` — what it is built from

Read the relevant spec before changing behaviour. The specs are the authority.
