<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NumberRangeFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Nummernkreis of a Firma: one gapless sequence, shared by every Beleg that
 * is a Rechnung within the meaning of §14 UStG (system design §5).
 *
 * The model holds the configuration and the formatting. Drawing is
 * `App\Actions\DrawNextNumber`, because that is the part that must happen under
 * a lock inside the issuing transaction.
 */
#[Fillable(['prefix', 'padding', 'next_value', 'include_year', 'reset_yearly'])]
class NumberRange extends Model
{
    /** @use HasFactory<NumberRangeFactory> */
    use HasFactory, HasUuids;

    /**
     * The value the next draw will take, with the yearly reset applied.
     *
     * Shared by the draw and by the settings page's preview, so a page
     * promising `RE-2026-0001` while the draw produces `RE-2026-0057` is not a
     * reachable state.
     *
     * A null `last_reset_year` means nothing has ever been drawn. It is
     * deliberately not treated as "an older year": a company migrating in sets
     * its Startwert to where its previous system stopped, and resetting that to
     * 1 would reissue numbers its customers already hold.
     */
    public function upcomingValue(int $year): int
    {
        if ($this->reset_yearly && $this->last_reset_year !== null && (int) $this->last_reset_year < $year) {
            return 1;
        }

        return (int) $this->next_value;
    }

    /**
     * The prefix carries its own separator, so „RE-" plus the year gives
     * `RE-2026-0043` and without the year `RE-0043`.
     *
     * str_pad never truncates, so a value wider than the padding keeps all its
     * digits. Cutting it would let two Belege share a number.
     */
    public function format(int $value, int $year): string
    {
        return $this->prefix
            .($this->include_year ? $year.'-' : '')
            .str_pad((string) $value, (int) $this->padding, '0', STR_PAD_LEFT);
    }

    /**
     * What the next Beleg will be numbered — read only, and drawing nothing.
     * A preview implemented by calling DrawNextNumber would look identical on
     * screen and burn a number on every page load.
     */
    public function nextNumber(): string
    {
        $year = (int) now()->year;

        return $this->format($this->upcomingValue($year), $year);
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
        // The raise-only guard. On the model rather than only in the settings
        // form, so a console command or a future import cannot go round it.
        //
        // A draw is exempt because it is the one writer that legitimately
        // lowers the value — the yearly reset. It is told apart by
        // `drawn_count`, which every draw increments and nothing else touches;
        // that is cheaper and harder to forget than a flag the draw has to
        // remember to set.
        static::updating(function (NumberRange $range): void {
            if ($range->isDirty('drawn_count')) {
                return;
            }

            if ((int) $range->getOriginal('drawn_count') === 0) {
                return;
            }

            throw_if((int) $range->next_value < (int) $range->getOriginal('next_value'), DomainException::class, 'The Startwert cannot be lowered once a Belegnummer has been drawn: '
            .'the numbers below it are already on issued Belege.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_value' => 'integer',
            'include_year' => 'boolean',
            'reset_yearly' => 'boolean',
            'last_reset_year' => 'integer',
            'drawn_count' => 'integer',
        ];
    }
}
