<laravel-boost-guidelines>
=== .ai/documentation/core rules ===

# Keeping the documentation true

This project's documentation lives in six places, and one change to the stack or to behaviour usually makes more than one of them wrong. **After any such change, work this list and correct what you falsified** — a stale doc is read as current and is worse than no doc.

- `README.md` — what the application does from a user's point of view, then the first-run sequence and the day-to-day command list. Update when a command, service, or setup step changes. **When a wave adds something a user can see, add it to "What it does today" and delete the matching line from "Not built yet".** That section is the only thing in this repository written for someone who wants to use the application rather than build it, so a feature missing from it is invisible to everyone who has not read the diff. Describe the behaviour and what it is for, not the classes that implement it.
- `CLAUDE.md` — the non-negotiable decisions and the container command block. Update when a decision is made or reversed; a new one is worth recording with the reason that forced it.
- `CONTEXT.md` — the domain's ubiquitous language: the German term for every concept, with the English identifier beside it. **Update it the moment a name is decided or changed**, in the same session, not afterwards — an entry there is what stops one concept from quietly acquiring a second name in the next wave, and what stops two concepts from sharing one. It is a glossary and nothing else: no implementation detail belongs in it.
- `docs/adr/` — decisions that are hard to reverse, surprising without context, **and** the result of a real trade-off. Numbered sequentially. If a decision fails any one of those three, do not write one: an easy decision will simply be reversed, an unsurprising one raises no questions, and one with no alternative records nothing.
- `docs/superpowers/specs/` — **the authority** on what the system does and what it is built from. When reality moves away from something a spec claims, correct the claim in place and say what changed, rather than leaving the old reasoning to mislead.
- `AGENTS.md` — **never edit this file directly.** It is generated, and `boost:install` silently replaces the whole block. Edit the matching file under `.ai/guidelines/` and regenerate:

```sh
docker compose run --rm app php artisan boost:install --guidelines --no-interaction
```

  Then diff `AGENTS.md` to confirm the change landed and nothing else moved. `docs/agents-md-maintenance.md` explains the trap in full.

All six live **in this repository**. There is no external wiki to keep in step: an Outline collection used to mirror the specs, and that obligation was dropped on 2026-09-26 because maintaining both cost more than it returned. Do not add a documentation location outside the repo without saying so here.

Dated plan and task documents record what was built on a given date. **Do not update them when the stack moves.** They are history, not current state.

=== .ai/rector/core rules ===

# Rector

This project has no PHP on the host — always run Rector through the container.

- Rector is scoped to `app/` and `tests/`. `config/` and `bootstrap/` are excluded on purpose: they ship with Laravel and are replaced wholesale by framework upgrades.
- If you have modified any PHP files under those paths, run `docker compose run --rm app ./vendor/bin/rector process` before Pint.
- **Order matters: Rector, then Pint.** Rector's output is not Pint-formatted, and the two only reach a fixed point in that order.
- Preview without writing using `docker compose run --rm app ./vendor/bin/rector process --dry-run`.

=== .ai/ui/core rules ===

# Interface conventions

These are settled. They are not defaults to be improved on, and a screen that
breaks one needs a reason stated out loud, not a preference.

## Row actions live in a dropdown

**A table row gets one action control: a vertical-ellipsis (⋮) button that opens
a dropdown containing the actions.** Never a row of buttons, never icons strung
across the row, never an action column that widens with each feature.

In Filament 5, this is `ActionGroup` — the default trigger is already the
vertical ellipsis, so wrap the row's actions in one rather than returning them
loose:

```php
->recordActions([
    ActionGroup::make([
        ViewAction::make(),
        EditAction::make(),
        DeleteAction::make(),
    ]),
])
```

The reason is that loose row actions are a ratchet. Every feature adds one, no
feature ever removes one, and the table ends up wider than the data it exists to
show — with the destructive action sitting a few pixels from the common one.

## Simplicity over completeness

**When a screen could show more or show less, show less.** Density is not a
feature, and "while we're here" is how a form grows to forty fields nobody reads.

In practice:

- Put on the screen what the task needs. Everything else goes behind a link, a
  detail view, or a collapsed section — not in a sidebar "for convenience".
- Prefer one obvious primary action to three equal ones. If everything is
  emphasised, nothing is.
- Do not add a filter, a widget, a badge or a column speculatively. Add it when
  a real task needs it.
- Empty states say what to do next, rather than apologising for being empty.

When a choice is genuinely balanced, take the plainer one. It is much cheaper to
add an affordance someone asked for than to remove one they have started relying
on.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `docker compose run --rm app composer show --direct` to list direct dependencies with versions, or `docker compose run --rm app composer show <vendor/package>` for a single package.
- JS packages: this project has no Node runtime and no `package.json` dependencies that matter — see Frontend Bundling below.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- This project has no frontend build step and no Node runtime. Do not suggest `npm run build`, `npm run dev`, or `composer run dev` — there is nothing to build. `package.json` and `vite.config.js` are vestigial from the Laravel skeleton; nothing in the app references `@vite`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- This project has no PHP on the host. Run Artisan commands through the container: `docker compose run --rm app php artisan route:list`. Use `docker compose run --rm app php artisan list` to discover available commands and `docker compose run --rm app php artisan [command] --help` to check parameters.
- Inspect routes with `docker compose run --rm app php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `docker compose run --rm app php artisan config:show app.name`, `docker compose run --rm app php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always run through the container, and use single quotes to prevent shell expansion: `docker compose run --rm app php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `docker compose run --rm app php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- This project deploys to DigitalOcean App Platform, not Laravel Cloud — Laravel Cloud cannot run WeasyPrint, which this project needs to render invoices. There is no `deploying-to-cloud` skill in this project; do not activate it or reference Laravel Cloud tooling.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

This project has no PHP on the host — every Artisan command below runs through the container.

- Use `docker compose run --rm app php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `docker compose run --rm app php artisan list` and check their parameters with `docker compose run --rm app php artisan [command] --help`.
- If you're creating a generic PHP class, use `docker compose run --rm app php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `docker compose run --rm app php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `docker compose run --rm app php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Naming: identifiers are English

- Columns, enums, classes and methods are named in English, even where the
  domain and the specs are German. German appears in exactly two places: **user-facing
  labels**, which live in `lang/de/`, and **legal designations that print
  verbatim on a document and have no English equivalent** — `GmbH` and `UG`
  are names, not words, the way `Inc.` is.
- So the §19 UStG Kleinunternehmer flag is the `VatScheme` enum
  (`Standard`/`SmallBusiness`), not `is_small_business` or a German name, and
  the invoicing wave's Storno, Teilstorno, Gutschrift and Mahnung become
  `Cancellation`, `PartialCancellation`, `SelfBilledInvoice` and
  `DunningNotice` in code while the German terms stay in the specs and in
  `lang/de/`.
- **`CONTEXT.md` is the authority on that mapping.** Read it before naming
  anything in the domain rather than translating term by term, because several
  of these words mean close to the opposite of what they look like: a
  `Gutschrift` is not a credit note but a self-billed invoice under §14 Abs. 2
  Satz 2 UStG, and the credit note is a `Teilstorno`. Deciding a translation
  per column is how a codebase ends up bilingual.

## Vite Error

- This project has no Vite build. An "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error means a view references `@vite` and should not — remove the reference instead of trying to build assets.

=== pint/core rules ===

# Laravel Pint Code Formatter

This project has no PHP on the host — always run Pint through the container.

- If you have modified any PHP files, you must run `docker compose run --rm app ./vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `docker compose run --rm app ./vendor/bin/pint --test --format agent`, simply run `docker compose run --rm app ./vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

This project has no PHP on the host — always run Pest through the container.

- This project uses Pest. Create tests with `docker compose run --rm app php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `docker compose run --rm app php artisan test --compact`.
- Rerun a test after each change to it.
- Run `docker compose run --rm app ./vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `docker compose run --rm app php artisan test --compact`.

</laravel-boost-guidelines>
