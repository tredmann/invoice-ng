# Maintaining AGENTS.md

`AGENTS.md` is a **build artifact**, not a source file. Editing it directly works
until someone runs `boost:install`, which silently reverts the edit.

## Why

The whole file sits inside a `<laravel-boost-guidelines>` block. Laravel Boost's
`GuidelineWriter::write()` replaces that block wholesale by regex on every run.
Any project-specific correction living only inside it — "no npm build", "no
Laravel Cloud", "run everything through Docker" — comes straight back from
Boost's own templates.

## Where to edit instead

`.ai/guidelines/`. Boost's `GuidelineComposer` checks that directory for a
same-path override of each vendor guideline **before** falling back to its
bundled copy (`vendor/laravel/boost/src/Install/GuidelineComposer.php`,
`guidelinePath()`). Overrides therefore survive regeneration.

Six are currently forked:

```
.ai/guidelines/foundation.blade.php
.ai/guidelines/deployments/core.blade.php
.ai/guidelines/laravel/core.blade.php
.ai/guidelines/boost/core.blade.php
.ai/guidelines/pint/core.blade.php
.ai/guidelines/pest/core.blade.php
```

To correct something that has no override yet, create one at the same relative
path Boost uses internally — mirror `vendor/laravel/boost/.ai/<path>`.

Verify a change with:

```sh
docker compose run --rm app php artisan boost:install --guidelines --no-interaction
```

then diff `AGENTS.md`.

## The known trap

`guidelinePath()` returns the override **instead of** vendor — there is no merge.
A `laravel/boost` upgrade that improves any of those six templates is silently
discarded, and nothing detects it. The package is on `^2.10`, so `composer update`
will move it.

**After any Boost upgrade**, diff each fork against its vendor original and pull in
anything worth keeping. A test that hashes the vendor originals and fails when they
change would turn this human promise into a red build; it is not written yet.

Also re-check for *new* vendor guideline files whose path we do not override — one
could reintroduce a host-execution, npm, or Laravel Cloud instruction.

## Not the same thing

`.ai/rules/` is a different, `record-rule`-driven mechanism for path-scoped recorded
decisions. It also survives regeneration, but it does not override a guideline
section. Do not reach for it here.
