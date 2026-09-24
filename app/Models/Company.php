<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Exceptions\CannotArchiveLastCompany;
use App\Rules\Iban;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'legal_form',
    'vat_scheme',
    'street',
    'postal_code',
    'city',
    'tax_number',
    'vat_id',
    'register_court',
    'register_number',
    'managing_directors',
    'bank_name',
    'iban',
    'bic',
])]
// Filament builds tenant URLs with `route(..., ['tenant' => $company])`,
// which calls `getRouteKey()`. The panel identifies a tenant by querying the
// `slug` column, so generation has to agree with resolution or every link in
// the switcher points at a URL that 404s.
#[RouteKey('slug')]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /**
     * The slug is never mass-assigned and `archived_at` is written only through
     * the archiving methods, so neither appears in the fillable list above.
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'vat_scheme' => 'standard',
    ];

    public static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name);

        // A name of nothing but punctuation slugs to the empty string, which
        // would be an unreachable URL and then a unique-index violation on the
        // next one.
        if ($base === '') {
            $base = 'company';
        }

        $slug = $base;
        $suffix = 2;

        // Counts past suffixes already in use rather than assuming "-2" is
        // free. The unique index is what guarantees correctness under a race;
        // this loop is what keeps the common case from hitting it.
        while (self::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Stored without spaces and upper-cased, so the same account is one value
     * however it was typed. Normalized through `Iban::normalize()`, shared
     * with the validation rule so storage and validation cannot drift apart.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function iban(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : Iban::normalize($value),
        );
    }

    /**
     * Whether this company may be archived.
     *
     * An active company can be archived only while another of the given
     * user's active companies remains. The last one is refused because
     * archiving it strands the owner: Filament redirects a user with no
     * tenants to registration, and every screen that could restore a company
     * sits behind the tenant prefix.
     *
     * The count is per user, through the join table, like every other
     * boundary in this wave. A global count would let one user's company
     * keep another user's last company archivable, or the reverse — two users
     * with one company each would give a global count of 2, and either could
     * archive their only company.
     */
    public function canBeArchived(User $user): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        return $this->activeCompanyCountFor($user) > 1;
    }

    /**
     * Archives the company, guarded and locked against a concurrent archive
     * of the user's other company racing this one.
     *
     * The guard is check-then-act, so the counting query takes a row lock:
     * without it, two concurrent archives against a two-company state could
     * both read "2 active" before either writes, and both proceed, leaving
     * zero. The transaction is what makes that lock hold for the count *and*
     * the save.
     */
    public function archive(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $isArchivable = ! $this->isArchived()
                && $this->activeCompanyCountFor($user, lock: true) > 1;

            throw_unless($isArchivable, CannotArchiveLastCompany::class, $this);

            // forceFill because archived_at is deliberately not fillable: it
            // is state, changed through these two methods and nowhere else.
            $this->forceFill(['archived_at' => now()])->save();
        });
    }

    /**
     * The number of the given user's companies that are not archived,
     * counted through the join table so the boundary matches every other one
     * in this wave.
     *
     * The locked path counts a fetched collection rather than `count()`,
     * because PostgreSQL rejects `FOR UPDATE` combined with an aggregate
     * function ("FOR UPDATE is not allowed with aggregate functions"). Fetching
     * the rows still takes the row lock the guard needs; only the counting
     * moves from SQL to PHP.
     */
    private function activeCompanyCountFor(User $user, bool $lock = false): int
    {
        $query = $user->companies()->whereNull('archived_at');

        if ($lock) {
            return count($query->lockForUpdate()->get());
        }

        return $query->count();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    protected static function booted(): void
    {
        // Set on create and never again: the slug is a stable public
        // identifier, and a rename must not break a bookmarked URL.
        static::creating(function (Company $company): void {
            $company->slug ??= self::uniqueSlugFrom((string) $company->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_form' => LegalForm::class,
            'vat_scheme' => VatScheme::class,
            'archived_at' => 'datetime',
        ];
    }
}
