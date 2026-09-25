<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Rules\Iban;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Declared because Larastan types `legal_form` as a string despite the enum
 * cast in casts(), and calling getLabel() on it then fails analysis.
 *
 * @property LegalForm $legal_form
 */
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
     * Archives the company. An already archived company keeps its original
     * archive date.
     *
     * There is no guard against archiving the user's last active company. One
     * existed while that was a dead end — Filament forced a user with no
     * companies into registration — and went when /admin became a page that
     * renders without a company (company-picker spec §2.5).
     */
    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        // forceFill because archived_at is deliberately not fillable: it is
        // state, changed through these two methods and nowhere else.
        $this->forceFill(['archived_at' => now()])->save();
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
