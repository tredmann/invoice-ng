<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\FromFormState;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The states of a Beleg (system design §3.5).
 *
 * Only `Draft` is reachable today — nothing can issue. The rest exist because
 * the immutability guards need something to refuse and a factory state needs
 * something to produce, which is what makes those guards testable before the
 * operation that would trip them is written.
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
