<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\PaymentTerm;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Declared because Larastan types these from the migration rather than from
 * casts(), as it does for Company::$legal_form.
 *
 * @property CustomerType $type
 * @property int $number
 * @property Carbon|null $deactivated_at
 * @property PaymentTerm|null $payment_term
 */
// `number`, `company_id` and `deactivated_at` are absent from the list below: the
// number is assigned here, the company is the tenant, and `deactivated_at`
// changes only through deactivate() and reactivate().
//
// Filament's tenancy hooks `creating` too, to associate a new record with the
// current tenant. When the next wave creates a customer for a company other
// than the one the panel request is scoped to — copying a customer to another
// company, say — that hook still fires and re-attaches company_id to the
// current tenant, so the customer would be numbered in one company and stored
// under another unless that wave accounts for it.
#[Fillable([
    'type',
    'name',
    'contact_person',
    'vat_id',
    'street',
    'postal_code',
    'city',
    'email',
    'payment_term',
])]
#[RouteKey('number')]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * Defaults to a business customer, the more common case.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'type' => 'business',
    ];

    public static function formatNumber(int $number): string
    {
        return 'K-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The number in a URL: exactly `K-` and digits, the way formatNumber()
     * writes it. At most nine digits, so a hand-edited URL cannot overflow
     * Postgres' integer column and turn a 404 into a 500.
     */
    public static function numberFromRouteKey(string $key): ?int
    {
        return preg_match('/^K-(\d{1,9})$/', $key, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * The number as someone types it into the search box: `K-0004`,
     * `k-0004`, `0004` or `4`.
     */
    public static function numberFromSearch(string $search): ?int
    {
        return preg_match('/^(?:K-)?(\d{1,9})$/i', trim($search), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    public function formattedNumber(): string
    {
        return self::formatNumber($this->number);
    }

    /**
     * Customer URLs carry the number, not the UUID — customers spec §3.4
     * argues why that does not breach §3.1 of the system design. The UUID is
     * still the key; this is only what a URL shows.
     */
    #[\Override]
    public function getRouteKey(): string
    {
        return $this->formattedNumber();
    }

    /**
     * Turns `K-0004` back into `number = 4`. Anything else matches nothing,
     * so it is a 404. Filament resolves a resource record through this, on a
     * query already scoped to the company in the URL.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return mixed
     */
    #[\Override]
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $number = self::numberFromRouteKey((string) $value);

        return $number === null
            ? $query->whereRaw('1 = 0')
            : $query->where('number', $number);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Deactivates the customer. An already deactivated customer keeps its
     * original date.
     */
    public function deactivate(): void
    {
        if ($this->isDeactivated()) {
            return;
        }

        // forceFill because deactivated_at is deliberately not fillable.
        $this->forceFill(['deactivated_at' => now()])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->latest('issued_on');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A new customer is saved in a transaction of its own, so that the lock
     * the `creating` hook takes on the company row is held until the INSERT
     * commits. Without it the lock would end when the hook returned, and two
     * concurrent creates could read the same maximum. Inside an outer
     * transaction this nests as a savepoint and the lock lasts until the
     * outer commit.
     *
     * Uses the model's own connection rather than DB::transaction(), which
     * always uses the default connection — the lock and the INSERT would
     * otherwise run on different connections whenever this model does not
     * use the default one.
     *
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        // Overwrites unconditionally: the number is assigned, never chosen —
        // not by the form, not by a factory, not by forceFill().
        static::creating(function (Customer $customer): void {
            $customer->number = self::nextNumberFor($customer);
        });

        // A hidden form field is not dehydrated, so switching a Firma to a
        // A Privatkunde would otherwise leave its contact person and VAT ID in
        // the database — where a later document would print them. Done here
        // rather than in the form so that every save path clears them, and
        // decided by isBusiness(), the predicate the form renders them by.
        static::saving(function (Customer $customer): void {
            if ($customer->type->isBusiness()) {
                return;
            }

            $customer->contact_person = null;
            $customer->vat_id = null;
        });
    }

    private static function nextNumberFor(Customer $customer): int
    {
        throw_if($customer->company_id === null, LogicException::class, 'A customer is numbered within its company, so its company must be set before it is saved.');

        // Serializes concurrent creates within one company and leaves every
        // other company unblocked.
        Company::query()->whereKey($customer->company_id)->lockForUpdate()->firstOrFail();

        // Unscoped on purpose: the company is named explicitly, and the answer
        // must not depend on which tenant the current request carries.
        $highest = self::query()
            ->withoutGlobalScopes()
            ->where('company_id', $customer->company_id)
            ->max('number');

        return (int) $highest + 1;
    }

    /**
     * @return array<string, string>
     */
    /**
     * The Zahlungsziel a Rechnung to this customer starts from: their own if
     * they have one, otherwise the company's.
     *
     * Resolved here and not backfilled into the column, because a company that
     * changes its default must not silently change the terms an existing
     * customer is invoiced under. A null stays a null, and means „whatever the
     * company says today".
     */
    public function effectivePaymentTerm(): PaymentTerm
    {
        if ($this->payment_term instanceof PaymentTerm) {
            return $this->payment_term;
        }

        $company = $this->company;

        throw_if($company === null, LogicException::class, 'A customer is invoiced under their company, so the company must be set '
        .'before a Zahlungsziel can be resolved.');

        return $company->payment_term;
    }

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'number' => 'integer',
            'payment_term' => PaymentTerm::class,
            'deactivated_at' => 'datetime',
        ];
    }
}
