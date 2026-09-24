@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Do Things the Laravel Way

This project has no PHP on the host — every Artisan command below runs through the container.

- Use `docker compose run --rm app php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `docker compose run --rm app php artisan list` and check their parameters with `docker compose run --rm app php artisan [command] --help`.
- If you're creating a generic PHP class, use `docker compose run --rm app php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

@scoped(['app/Models/**'])
### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `docker compose run --rm app php artisan make:model --help` to check the available options.
@endscoped

@scoped(['app/Http/**', 'routes/**'])
## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.
@endscoped

## URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

@scoped(['tests/**'])
## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `docker compose run --rm app php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.
@endscoped

## Vite Error
- This project has no Vite build. An "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error means a view references `@vite` and should not — remove the reference instead of trying to build assets.
