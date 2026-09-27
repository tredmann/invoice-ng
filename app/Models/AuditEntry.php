<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;
use Database\Factories\AuditEntryFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a Beleg's Verlauf (system design §3.8).
 *
 * Append-only: nothing ever updates or deletes an entry. That is enforced here
 * rather than promised in a comment, because the value of an audit trail is
 * exactly that it cannot be tidied up afterwards — a trail a bug can rewrite
 * proves nothing about what happened.
 *
 * Written by the transaction that performs the work, so an event cannot be
 * recorded for something that rolled back, nor missed for something that
 * committed.
 *
 * @property AuditEvent $event
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed>|null $details
 */
#[Fillable([
    'event',
    'occurred_at',
    'actor_id',
    'details',
])]
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * A single detail, for a caller that wants one value out of the payload
     * without asserting the shape of the whole thing.
     */
    public function detail(string $key): mixed
    {
        return $this->details[$key] ?? null;
    }

    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'occurred_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('A Verlauf entry is append-only; nothing may change one.');
        });

        static::deleting(function (): void {
            throw new DomainException('A Verlauf entry is append-only; nothing may delete one.');
        });
    }
}
