<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The Verlauf (§3.8). The whole value of an audit trail is that it cannot be
 * tidied up afterwards, so the tests that matter are the two refusals.
 */
it('refuses to change an entry', function (): void {
    // forceFill with a *different* value, because an identical one is not dirty
    // and Eloquent skips the update — and therefore the guard — entirely. An
    // entry rewritten to say a different Belegnummer is the failure this
    // prevents, so that is what the test attempts.
    $entry = AuditEntry::factory()->create(['details' => ['number' => 'RE-2026-0001']]);

    expect(fn (): bool => $entry->forceFill(['details' => ['number' => 'RE-2026-9999']])->save())
        ->toThrow(DomainException::class, 'append-only');

    expect($entry->fresh()?->detail('number'))->toBe('RE-2026-0001');
});

it('refuses to change an entry through a mass update too', function (): void {
    // A query-builder update fires no model events, so the guard above cannot
    // see it. Nothing in the application does this; the assertion is that if
    // something starts to, it is a deliberate act and not an accident of using
    // the wrong builder.
    $entry = AuditEntry::factory()->create(['details' => ['number' => 'RE-2026-0001']]);

    AuditEntry::query()->whereKey($entry->getKey())->update(['details' => json_encode(['number' => 'RE-2026-9999'])]);

    expect($entry->fresh()?->detail('number'))->toBe('RE-2026-9999');
})->todo('A database-level trigger or a revoked UPDATE grant is what would close this; neither is worth a migration while one user has psql anyway.');

it('refuses to delete an entry', function (): void {
    $entry = AuditEntry::factory()->create();

    expect(fn (): ?bool => $entry->delete())
        ->toThrow(DomainException::class, 'append-only');
});

it('refuses to let the database delete an entry with its document', function (): void {
    // restrictOnDelete rather than cascade. Only an Entwurf is deletable and
    // only issuing writes an entry, so this can never fire in practice — which
    // is the point: the database says so instead of a comment promising it.
    $entry = AuditEntry::factory()->create();
    $document = $entry->document;

    expect(fn (): int => DB::table('documents')->where('id', $document?->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('reads one detail without asserting the shape of the whole payload', function (): void {
    $entry = AuditEntry::factory()->create([
        'details' => ['number' => 'RE-2026-0042', 'gross' => '2345.20'],
    ]);

    expect($entry->detail('number'))->toBe('RE-2026-0042')
        ->and($entry->detail('nothing'))->toBeNull();
});

it('records who performed the work, and admits that nobody did', function (): void {
    // Null is a real state: a queued job or a console command performs work
    // with no user behind it. Nothing does that yet, and the column admits it
    // rather than inventing a system user to satisfy a foreign key.
    $user = User::factory()->create();
    $document = Invoice::factory()->issued()->create();

    $byUser = AuditEntry::factory()->for($document, 'document')->create(['actor_id' => $user->getKey()]);
    $bySystem = AuditEntry::factory()->for($document, 'document')->create(['actor_id' => null]);

    expect($byUser->actor?->getKey())->toBe($user->getKey())
        ->and($bySystem->actor)->toBeNull();
});

it('reads the Verlauf newest first', function (): void {
    $document = Invoice::factory()->issued()->create();

    AuditEntry::factory()->for($document, 'document')->create(['occurred_at' => now()->subDay()]);
    $newer = AuditEntry::factory()->for($document, 'document')->create(['occurred_at' => now()]);

    expect($document->auditEntries()->first()?->getKey())->toBe($newer->getKey());
});
