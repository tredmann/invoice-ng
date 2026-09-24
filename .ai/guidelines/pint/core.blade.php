@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Pint Code Formatter

This project has no PHP on the host — always run Pint through the container.

@if($assist->supportsPintAgentFormatter())
- If you have modified any PHP files, you must run `docker compose run --rm app ./vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `docker compose run --rm app ./vendor/bin/pint --test --format agent`, simply run `docker compose run --rm app ./vendor/bin/pint --format agent` to fix any formatting issues.
@else
- If you have modified any PHP files, you must run `docker compose run --rm app ./vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `docker compose run --rm app ./vendor/bin/pint --test`, simply run `docker compose run --rm app ./vendor/bin/pint` to fix any formatting issues.
@endif
