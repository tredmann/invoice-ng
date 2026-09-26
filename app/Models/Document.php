<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property DocumentStatus $status
 * @property PaymentTerm $payment_term
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
