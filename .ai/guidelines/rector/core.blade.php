# Rector

This project has no PHP on the host — always run Rector through the container.

- Rector is scoped to `app/` and `tests/`. `config/` and `bootstrap/` are excluded on purpose: they ship with Laravel and are replaced wholesale by framework upgrades.
- If you have modified any PHP files under those paths, run `docker compose run --rm app ./vendor/bin/rector process` before Pint.
- **Order matters: Rector, then Pint.** Rector's output is not Pint-formatted, and the two only reach a fixed point in that order.
- Preview without writing using `docker compose run --rm app ./vendor/bin/rector process --dry-run`.
