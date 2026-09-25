<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasColor, HasLabel
{
    case Business = 'business';
    case PrivatePerson = 'private_person';

    /**
     * Coerces a form's state — the enum itself, its backing string, or
     * nothing yet — to a type. Forms hold whichever of these they were last
     * given, so a closure that asks the state a question has to accept all
     * three.
     */
    public static function fromFormState(mixed $state): ?self
    {
        if ($state instanceof self) {
            return $state;
        }

        return is_string($state) ? self::tryFrom($state) : null;
    }

    /**
     * Whether this customer can carry a contact person and a VAT ID.
     *
     * The one question both the form (render the fields?) and the model
     * (clear them on save?) ask. Asking it here keeps the two from disagreeing
     * about which types have them.
     */
    public function isBusiness(): bool
    {
        return match ($this) {
            self::Business => true,
            self::PrivatePerson => false,
        };
    }

    public function getLabel(): string
    {
        return __("customer.type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Business => 'info',
            self::PrivatePerson => 'gray',
        };
    }
}
