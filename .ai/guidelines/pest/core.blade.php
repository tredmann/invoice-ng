@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
@scoped(['tests/**'])
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
@endscoped
