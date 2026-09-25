<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Declared because Larastan types these from the migration rather than from
 * casts(), as it does for Company::$legal_form.
 *
 * @property CustomerType $type
 * @property int $number
 * @property Carbon|null $archived_at
 */
#[Fillable([
    'type',
    'name',
    'contact_person',
    'vat_id',
    'street',
    'postal_code',
    'city',
    'email',
])]
#[RouteKey('number')]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * `number`, `company_id` and `archived_at` are absent from the fillable
     * list above: the number is assigned here, the company is the tenant, and
     * `archived_at` changes only through archive() and unarchive().
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

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Deactivates the customer. An already deactivated customer keeps its
     * original date.
     */
    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        // forceFill because archived_at is deliberately not fillable.
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
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
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return DB::transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        // Overwrites unconditionally: the number is assigned, never chosen —
        // not by the form, not by a factory, not by forceFill().
        static::creating(function (Customer $customer): void {
            $customer->number = self::nextNumberFor($customer);
        });

        // A hidden form field is not dehydrated, so switching a Firma to a
        // Privatperson would otherwise leave its contact person and VAT ID in
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
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'number' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
