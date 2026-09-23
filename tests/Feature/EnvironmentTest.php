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
