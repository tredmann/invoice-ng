<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\CalculateTotals;
use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use App\Money\LineInput;
use App\Money\Totals;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Parental\HasChildren;

/**
 * A **Beleg**: one table for every Belegart, with the behaviour that differs
 * living in a class of its own rather than in conditionals (system design
 * §3.5).
 *
 * Only `Invoice` exists so far. The single table is built now because its
 * shape is the expensive half of that decision — a Storno, a Teilstorno and a
 * Gutschrift then arrive as classes, not as a table rename with every foreign
 * key that points here following it.
 *
 * Addressed by its UUID for its whole life. Unlike `Customer`, which is
 * reached by `K-0004`, a document has no number until it is issued, and a
 * scheme that switched at that moment would change a document's address
 * partway through its life. The cost is accepted and written down: a
 * Belegnummer never appears in a URL. See the invoice-drafts spec §5.
 *
 * The cast columns are declared because Larastan types them from the migration
 * — a date column as a string, a string column as a string — and the casts in
 * casts() are invisible to it. Company carries the same declarations for the
 * same reason.
 *
 * @property DocumentStatus $status
 * @property PaymentTerm $payment_term
 * @property int|null $number
 * @property Carbon $issued_on
 * @property Carbon|null $performed_on
 * @property Carbon|null $performed_from
 * @property Carbon|null $performed_to
 */
#[Fillable([
    'customer_id',
    'issued_on',
    'performed_on',
    'performed_from',
    'performed_to',
    'payment_term',
])]
class Document extends Model
{
    use HasChildren, HasUuids;

    /**
     * `status`, `number` and `company_id` are deliberately absent from the
     * fillable list. The status is state, the number is drawn at issue and
     * never chosen, and the company comes from the tenant.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Parental's alias map. The alias is what the `type` column stores, so it
     * is domain vocabulary rather than a class name and survives a namespace
     * move.
     *
     * @var array<string, class-string<Model>>
     */
    protected array $childTypes = [
        'invoice' => Invoice::class,
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<LineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class)->orderBy('position');
    }

    /**
     * The Beleg's figures, computed from its Positionen by the rounding of §6.
     *
     * Computed and not stored, while this is a draft. §6 stores totals on the
     * document and §4 says that storing happens *at issue*; until then the
     * Positionen are the only truth, and a stored copy would be a second one
     * that an edit could leave disagreeing with the lines it claims to sum.
     *
     * Reads the relation rather than querying it, so a caller that eager-loaded
     * `lineItems` — the list does, for its Betrag column — pays nothing extra.
     */
    public function totals(): Totals
    {
        $lines = $this->lineItems
            ->map(fn (LineItem $item): LineInput => LineInput::of(
                (string) $item->quantity,
                $item->unit_price,
                $item->tax_rate,
            ))
            ->all();

        // array_values rather than the collection's values(): PHPStan reads
        // the first as a list and the second as an array with int keys, and
        // CalculateTotals asks for a list.
        return (new CalculateTotals)(array_values($lines));
    }

    protected static function booted(): void
    {
        // Normalising rather than refusing, the way Customer clears the
        // business-only fields: whichever way the two forms arrive, exactly
        // one of them is stored. A rule that threw would leave the caller to
        // work out which half to clear — and a half-set period is the shape
        // that maps to neither BT-72 nor BG-14.
        static::saving(function (Document $document): void {
            $document->normaliseLeistung();
        });

        // §4: after issue the document is immutable, and only the status,
        // payments and audit entries may still change.
        //
        // Read from the *stored* status, not the one being written. Reading
        // the new value would refuse the draft → issued transition itself, so
        // the guard would forbid the operation it exists to protect.
        static::updating(function (Document $document): void {
            // getRawOriginal, not getOriginal: the latter applies the cast and
            // hands back the enum, which does not survive a string cast.
            $stored = DocumentStatus::from((string) $document->getRawOriginal('status'));

            if ($stored->isDraft()) {
                return;
            }

            $changed = array_diff(array_keys($document->getDirty()), ['status', 'updated_at']);

            if ($changed !== []) {
                throw new DomainException(sprintf(
                    'An issued Beleg is unveränderlich; only its status may still change, not [%s].',
                    implode(', ', $changed),
                ));
            }
        });

        // A draft never received a number and can be deleted outright (§3.4).
        // Nothing else in this system is deletable.
        static::deleting(function (Document $document): void {
            throw_unless($document->status->isDraft(), DomainException::class, 'Only an Entwurf can be deleted; an issued Beleg is part of the record.');
        });
    }

    /**
     * A Beleg carries either a Leistungsdatum or a Leistungszeitraum, never
     * both (§7). Two dates that differ are a period; anything else is a single
     * day, taking whichever of the two was given.
     *
     * A period whose ends coincide is a day: ZUGFeRD has no one-day BG-14.
     */
    private function normaliseLeistung(): void
    {
        $from = $this->performed_from;
        $to = $this->performed_to;

        if ($from !== null && $to !== null && ! $from->isSameDay($to)) {
            $this->performed_on = null;

            return;
        }

        $this->performed_on ??= $from ?? $to;
        $this->performed_from = null;
        $this->performed_to = null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'payment_term' => PaymentTerm::class,
            'number' => 'integer',
            'issued_on' => 'date',
            'performed_on' => 'date',
            'performed_from' => 'date',
            'performed_to' => 'date',
        ];
    }
}
