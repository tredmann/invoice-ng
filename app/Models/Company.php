<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
