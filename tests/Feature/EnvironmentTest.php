<?php

use Illuminate\Support\Facades\DB;

it('runs tests against postgresql, never sqlite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('runs tests against a separate database', function () {
    expect(DB::connection()->getDatabaseName())->toBe('invoice_test');
});

it('is really talking to a live postgresql server', function () {
    // getDriverName() and getDatabaseName() only read resolved config — they
    // would both pass against an unreachable database. This asks the server
    // itself, so the guard against SQLite cannot silently become a no-op.
    $version = DB::selectOne('select version() as version')->version;

    expect($version)->toContain('PostgreSQL');
});

it('is configured for german invoicing', function () {
    expect(config('app.timezone'))->toBe('Europe/Berlin')
        ->and(config('app.locale'))->toBe('de')
        ->and(config('app.fallback_locale'))->toBe('en');
});
