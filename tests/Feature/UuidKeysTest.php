<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('gives users a real uuid column, not a bigint', function (): void {
    // Asserting that $user->id is a string would pass against a bigint too —
    // PDO hands integers back as strings. The only assertion here that can
    // actually fail is the type the server itself reports for the column.
    $column = DB::selectOne(
        'select data_type from information_schema.columns
         where table_name = ? and column_name = ?',
        ['users', 'id']
    );

    expect($column->data_type)->toBe('uuid');
});

it('carries the uuid through to the sessions foreign key', function (): void {
    // The foreign key is the half that silently rots: users.id can be migrated
    // while sessions.user_id stays bigint, and nothing complains until a login
    // tries to write a session.
    $column = DB::selectOne(
        'select data_type from information_schema.columns
         where table_name = ? and column_name = ?',
        ['sessions', 'user_id']
    );

    expect($column->data_type)->toBe('uuid');
});

it('generates version 7 uuids, so keys stay time-ordered', function (): void {
    $user = User::factory()->create();

    expect($user->getIncrementing())->toBeFalse()
        ->and($user->getKeyType())->toBe('string')
        ->and(Str::isUuid($user->getKey()))->toBeTrue();

    // The version nibble sits at index 14 of the canonical form. This is the
    // discriminator between v7 and v4: both are valid UUIDs and both satisfy
    // every assertion above, but only v7 is time-ordered — which is what the
    // spec's claim about index locality rests on.
    expect($user->getKey()[14])->toBe('7');
});

it('round-trips a user by its uuid', function (): void {
    // Proves the key is usable for lookups, not merely stored. A mismatch
    // between the column type and the cast would surface here.
    //
    // whereKey()->first() rather than find(): find()'s return type is a union
    // with Collection, so reading a property off it fails Larastan at level 8.
    $user = User::factory()->create();

    $found = User::query()->whereKey($user->getKey())->first();

    expect($found?->email)->toBe($user->email);
});
