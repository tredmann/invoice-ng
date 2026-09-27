<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\FromFormState;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The states of a Beleg (system design §3.5).
 *
 * `Draft` and `Issued` are reachable. `Sent`, `Paid` and `Cancelled` are not
 * written by anything yet — sending, payments and Storno are unbuilt — but
 * they exist because the immutability guards need something to refuse, and
 * because the sets below have to be right before the wave that reaches them.
 *
 * `Überfällig` is deliberately absent: it is a derived display state computed
 * from the Fälligkeitsdatum and the offener Betrag, not a stored value.
 */
enum DocumentStatus: string implements HasColor, HasLabel
{
    use FromFormState;

    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * Whether the document is still freely editable and deletable.
     *
     * The one question the model guards, the row actions and the detail page
     * all ask, so they cannot disagree about what a draft is.
     */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Ausgestellt and not yet settled, so the Beleg still carries an offener
     * Betrag and can fall überfällig.
     *
     * Written here rather than at each call site because it is asked in two
     * languages: `Document::isOverdue()` asks it of a row in PHP, and
     * `Document::whereOutstanding()` asks it of a set in SQL. One statement
     * of which statuses, consumed by both, is what stops them disagreeing —
     * and `DocumentTest` holds the two forms equal.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Issued || $this === self::Sent;
    }

    /**
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->isOutstanding(),
        ));
    }

    /**
     * Ausgestellt and nicht storniert — this Beleg's Betrag is Umsatz.
     *
     * A cancelled Beleg is „kein geminderter Umsatz" (CONTEXT.md): it leaves
     * the revenue sum entirely rather than subtracting from it. A draft was
     * never Umsatz to begin with.
     */
    public function countsAsRevenue(): bool
    {
        return in_array($this, [self::Issued, self::Sent, self::Paid], true);
    }

    /**
     * @return list<self>
     */
    public static function revenueBearing(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->countsAsRevenue(),
        ));
    }

    public function getLabel(): string
    {
        return __("document.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'info',
            self::Sent => 'info',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
