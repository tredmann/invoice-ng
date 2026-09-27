<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\CalculateTotals;
use App\Casts\FrozenBlockCast;
use App\Casts\MoneyCast;
use App\Documents\FrozenBlock;
use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use App\Money\LineInput;
use App\Money\Totals;
use Brick\Money\Money;
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
 * @property string|null $number
 * @property Carbon $issued_on
 * @property Carbon|null $performed_on
 * @property Carbon|null $performed_from
 * @property Carbon|null $performed_to
 * @property Carbon|null $due_on
 * @property Money|null $net_total
 * @property Money|null $tax_total
 * @property Money|null $gross_total
 * @property FrozenBlock|null $frozen_block
 * @property string|null $pdf_path
 * @property string|null $pdf_sha256
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
     * The Verlauf, newest first — the order it is read in.
     *
     * @return HasMany<AuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class)->latest('occurred_at');
    }

    /**
     * Whether this Beleg has been issued: it carries a Belegnummer and a frozen
     * PDF, and nothing about it may change but its status.
     *
     * The negation of isDraft() is not the same question — `cancelled` is
     * neither a draft nor freshly issued — so this asks its own.
     */
    public function isIssued(): bool
    {
        return ! $this->status->isDraft();
    }

    /**
     * Whether the Fälligkeitsdatum has passed with the Beleg still unpaid.
     *
     * **Überfällig is derived, never stored** (§3.5). It is computed here so
     * the list, the detail page and any later Offene-Posten screen cannot
     * disagree about what overdue means.
     *
     * A cancelled Beleg is never overdue — it is erledigt — and neither is a
     * draft, which has no due date at all. Once Zahlungen exist this also has
     * to consult the offener Betrag; today no payment can be recorded, so an
     * issued Beleg past its date is overdue by definition.
     */
    public function isOverdue(): bool
    {
        if (! in_array($this->status, [DocumentStatus::Issued, DocumentStatus::Sent], true)) {
            return false;
        }

        return $this->due_on !== null && $this->due_on->isBefore(today());
    }

    /**
     * The Bruttobetrag, from wherever it is authoritative.
     *
     * Stored once the Beleg is issued — that figure is the one on the PDF the
     * customer holds, and it is what a sum over many documents has to read, so
     * it is also what a single document should show. Computed while a draft,
     * where there is nothing else to read.
     *
     * The two can only ever agree: Positionen are immutable after issue. A test
     * says so, because „can only" is a claim about code that could change.
     */
    public function grossAmount(): Money
    {
        return $this->gross_total ?? $this->totals()->gross;
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

        // `performed_from` is the authoritative input: it is the field the
        // form sends, and a stored Leistungsdatum is filled back into it. Were
        // `performed_on` allowed to win, editing the date of a document that
        // already had one would silently keep the old one.
        if ($from !== null) {
            if ($to !== null && ! $from->isSameDay($to)) {
                $this->performed_on = null;

                return;
            }

            $this->performed_on = $from;
            $this->performed_from = null;
            $this->performed_to = null;

            return;
        }

        $this->performed_on ??= $to;
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
            'issued_on' => 'date',
            'performed_on' => 'date',
            'performed_from' => 'date',
            'performed_to' => 'date',
            'due_on' => 'date',
            'net_total' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'gross_total' => MoneyCast::class,
            'frozen_block' => FrozenBlockCast::class,
        ];
    }
}
