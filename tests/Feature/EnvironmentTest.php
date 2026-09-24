<?php

use Illuminate\Support\Facades\DB;

it('runs tests against postgresql, never sqlite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('is really talking to a live, separate postgresql database', function (): void {
    // getDatabaseName() only reads resolved config — it would pass even
    // against a database that does not exist. Folding driver, database name
    // and liveness into one round trip against the server itself proves all
    // three at once; the guard against SQLite cannot silently become a
    // no-op, and neither can the guard against sharing the dev database.
    $row = DB::selectOne('select version() as version, current_database() as db');

    expect($row->version)->toContain('PostgreSQL')
        ->and($row->db)->toBe('invoice_test');
});

it('is configured for german invoicing', function (): void {
    expect(config('app.timezone'))->toBe('Europe/Berlin')
        ->and(config('app.locale'))->toBe('de')
        ->and(config('app.fallback_locale'))->toBe('en');
});
