<?php

declare(strict_types=1);

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Steuersatz a company may put on a Position.
 *
 * A table rather than an enum because it is per-company data the owner edits,
 * and because system design §3.4 puts tax rates among the things that are
 * deactivated rather than deleted — which only makes sense for rows. See
 * `docs/adr/0003-stammdatenlisten.md`.
 *
 * `rate` is in basis points: 1900 is 19 %.
 *
 * @property Carbon|null $deactivated_at
 */
#[Fillable(['rate', 'name', 'is_default'])]
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory, HasUuids;

    /**
     * „19 %", „7,5 %", „0 %" — as a German document prints it. Derived from the
     * integer rather than stored beside it, so the two cannot disagree.
     */
    public static function formatRate(int $basisPoints): string
    {
        return self::formatPercent($basisPoints).' %';
    }

    /**
     * The same figure without the sign, for a form input that carries its own
     * „%" suffix: „19", „7,5", „0".
     */
    public static function formatPercent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return "{$whole},".rtrim(sprintf('%02d', $fraction), '0');
    }

    /**
     * The inverse, for the settings form: „7,5" and „7.5" are the same rate.
     *
     * Through BigDecimal rather than a float multiplication, because 7.5 * 100
     * is not reliably 750 and a Steuersatz that is one basis point off prints
     * correctly while computing wrongly.
     */
    public static function basisPointsFrom(string $percent): int
    {
        return BigDecimal::of(str_replace(',', '.', trim($percent)))
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    public function formattedRate(): string
    {
        return self::formatRate((int) $this->rate);
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Mirrors Company and Customer: `deactivated_at` is state, changed through
     * these two methods and nowhere else, so it is not fillable.
     */
    public function deactivate(): void
    {
        if ($this->isDeactivated()) {
            return;
        }

        $this->forceFill(['deactivated_at' => now()])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();
    }

    public function makeDefault(): void
    {
        $this->fill(['is_default' => true])->save();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function booted(): void
    {
        // Clears the company's previous default before this row is written, so
        // the partial unique index is never the thing the user meets. It is
        // still there: this hook is the behaviour, the index is the guarantee.
        static::saving(function (TaxRate $rate): void {
            if ($rate->is_default !== true) {
                return;
            }

            $others = static::query()
                ->where('company_id', $rate->company_id)
                ->where('is_default', true);

            // A new row has no key yet — HasUuids assigns it on `creating`,
            // which fires after this — so there is nothing to exclude.
            if ($rate->exists) {
                $others->whereKeyNot($rate->getKey());
            }

            $others->update(['is_default' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'is_default' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }
}
